<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\GPS\Models\MqttDeadLetter;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\DTOs\TelemetryDTO;
use App\Modules\GPS\Services\TelemetryService;
use App\Modules\GPS\Services\MessageValidatorService;
use App\Modules\GPS\Services\MessageParserService;

class MqttDlqRetry extends Command
{
    protected $signature = 'mqtt:dlq:retry {id : El ID del mensaje en la DLQ}';
    protected $description = 'Reintenta procesar un mensaje de la Dead Letter Queue';

    protected $telemetryService;
    protected $validatorService;
    protected $parserService;

    public function __construct(
        TelemetryService $telemetryService,
        MessageValidatorService $validatorService,
        MessageParserService $parserService
    ) {
        parent::__construct();
        $this->telemetryService = $telemetryService;
        $this->validatorService = $validatorService;
        $this->parserService = $parserService;
    }

    public function handle()
    {
        $id = $this->argument('id');
        $deadMsg = MqttDeadLetter::find($id);

        if (!$deadMsg) {
            $this->error(" No se encontró ningún mensaje en la DLQ con el ID: {$id}");
            return 1;
        }

        $this->info(" Reintentando mensaje ID: {$id} (Topic: {$deadMsg->topic})...");

        try {
            // Convertimos el array de nuevo a string JSON crudo para pasarlo por el parser
            $rawPayload = is_array($deadMsg->raw_payload) ? json_encode($deadMsg->raw_payload) : $deadMsg->raw_payload;

            // 1. Parseo
            $parsedResult = $this->parserService->parse($deadMsg->topic, $rawPayload);
            $deviceUuid = $parsedResult['uuid'];
            $cleanPayload = $parsedResult['payload'];

            // 2. Validación
            $errores = $this->validatorService->findErrors($deviceUuid, json_encode($cleanPayload));

            if (!empty($errores)) {
                $this->error(" El mensaje sigue siendo inválido. Detalle:");
                foreach ($errores as $error) {
                    $this->line(" - [{$error['severity']}] {$error['message']}");
                }
                // Incrementamos el contador de intentos en la DLQ
                $deadMsg->increment('attempts');
                return 1;
            }

            // 3. Ingesta
            $device = Device::where('uuid', $deviceUuid)->first();
            $telemetryDTO = TelemetryDTO::fromArray($cleanPayload);
            $this->telemetryService->ingest($device->id, $telemetryDTO);

            // ¡ÉXITO! Borramos el mensaje de la DLQ
            $deadMsg->delete();
            $this->info(" ¡Procesamiento exitoso! El mensaje ha sido recuperado y eliminado de la DLQ.");

            return 0;

        } catch (\Exception $e) {
            $this->error(" Falló nuevamente: " . $e->getMessage());
            $deadMsg->increment('attempts');
            $deadMsg->update(['error_message' => $e->getMessage(), 'failed_at' => now()]);
            return 1;
        }
    }
}
