<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\DTOs\TelemetryDTO;
use Illuminate\Support\Facades\DB;
use Exception;

class TelemetryService
{
    /**
     * Procesa los datos de telemetría recibidos y los guarda en la base de datos.
     * * @param int $deviceId ID numerico del dispositivo al que pertenecen los datos de telemetría
     * * @param TelemetryDTO $dto DTO con los datos de telemetría a guardar
     * * @return array Resultado del procesamiento, con éxito o error
     */
    public function ingest(int $deviceId, TelemetryDTO $dto): array
    {
        //1. Preparar el JSON payload
        //El DTO tiene un metodo toProcedurePayload() que convierte sus datos al formato esperado por el procedimiento almacenado en PostgreSQL
        $jsonPayload = $dto->toProcedurePayload();

        try {
            //2. Ejecutar el procedimiento almacenado
            //CALL gps.telemetry_process(p_device_id, p_data)
            DB::statement('CALL gps.telemetry_process(?, ?)', [
                $deviceId,
                $jsonPayload
            ]);
            //3. Obtener la respuesta de la sesion
            //SELECT * FROM core.response_get()
            $response = DB::selectOne('SELECT * FROM core.response_get()');

            //4. Evaluar resultado
            if ($response->status !== 'OK') {
                throw new Exception("Error de procesamiento SQL: [{$response->code}] {$response->message}");
            }
            return [
                'success' => true,
                'code' => $response->code,
                'message' => $response->message,
            ];
        } catch (Exception $e) {
            //Aqui capturamos errores fatales si el SP falla por alguna razon, como violaciones de constraints no manejadas o errores de sintaxis SQL
            throw new Exception("Fallo en ingesta de telemetría: " . $e->getMessage());
        }
    }
}