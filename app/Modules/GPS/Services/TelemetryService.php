<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\DTOs\TelemetryDTO;
use App\Modules\GPS\Models\Telemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Lottery;
use Exception;

class TelemetryService
{
    /**
     * Procesa los datos de telemetría y ejecuta la auditoría Post-Persistencia
     */
    protected $metrics;
    public function __construct(PersistenceMetricsService $metrics)
    {
        $this->metrics = $metrics;
    }
    public function ingest(int $deviceId, TelemetryDTO $dto, string $traceId = 'N/A'): array
    {
        $startTime = microtime(true);
        $this->metrics->recordAttempt();

        //  DEBUG: Solo se imprimirá si tu .env tiene LOG_LEVEL=debug
        Log::channel('mqtt')->debug("[{$traceId}] Iniciando persistencia", [
            'device_id' => $deviceId,
            'payload' => $dto->toArray()
        ]);

        $previousTelemetry = Telemetry::where('device_id', $deviceId)->first();
        $previousSatellite = $previousTelemetry ? $previousTelemetry->satellite : null;
        $isUpdate = $previousTelemetry !== null;
        $operationType = $isUpdate ? 'UPDATE' : 'INSERT'; //  Definimos la operación

        $jsonPayload = $dto->toProcedurePayload();

        try {
            DB::statement('CALL gps.telemetry_process(?, ?)', [$deviceId, $jsonPayload]);
            $response = DB::selectOne('SELECT * FROM core.response_get()');

            if ($response->status !== 'OK') {
                throw new Exception("Error SQL: [{$response->code}] {$response->message}");
            }

            // ... (Validación de Triggers igual que antes) ...
            $currentTelemetry = Telemetry::where('device_id', $deviceId)->first();
            if ($currentTelemetry) {
                $triggerSuccess = $this->verifyTriggerExecution($currentTelemetry, $previousSatellite, $isUpdate);
                $this->metrics->recordTriggerResult($triggerSuccess);
            }

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->metrics->recordSuccess($durationMs);

            //  INFO: UPSERT Exitoso con SAMPLING (10% en prod, 100% en dev)
            $logContext = [
                'trace_id'    => $traceId,
                'device_id'   => $deviceId,
                'operation'   => $operationType,
                'duration_ms' => $durationMs,
                'satellite'   => $dto->satellite, // Campo clave
                'battery'     => $dto->battery,   // Campo clave
            ];

            Lottery::odds(env('APP_ENV') === 'production' ? 1 : 10, 10)
                ->winner(fn () => Log::channel('mqtt')->info("[{$traceId}]  Persistencia exitosa", $logContext))
                ->choose();

            return [
                'success' => true,
                'code' => $response->code,
                'message' => $response->message,
            ];

        } catch (Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->metrics->recordError((new \ReflectionClass($e))->getShortName());
            
            //  ERROR: Fallo la persistencia (El Retry Service ya tira los WARNINGS por su cuenta)
            Log::channel('mqtt')->error("[{$traceId}] Fallo en persistencia: " . $e->getMessage(), [
                'device_id' => $deviceId,
                'operation' => $operationType,
                'duration_ms' => $durationMs
            ]);
            
            throw new Exception("Fallo en ingesta de telemetría: " . $e->getMessage());
        }
    }

    /**
     * Verifica que PostgreSQL ejecutó correctamente sus funciones y triggers internos
     * * @param Telemetry $telemetry Snapshot actual recién guardado
     * @param string|null $previousSatellite Fecha del satélite antes del UPSERT
     * @param bool $wasUpdate Indica si el registro ya existía
     */
    public function verifyTriggerExecution(Telemetry $telemetry, ?string $previousSatellite, bool $wasUpdate): bool
    {
        $isValid = true;
        $deviceId = $telemetry->device_id; // En esta arquitectura, device_id es la PK de telemetry

        // REGLA 1: location DEBE calcularse automáticamente por PostgreSQL (GENERATED ALWAYS)
        if ($telemetry->latitude !== null && $telemetry->longitude !== null) {
            // Hacemos una consulta cruda para verificar que PostGIS llenó la columna binaria
            $hasLocation = DB::scalar('SELECT location IS NOT NULL FROM telemetry WHERE device_id = ?', [$deviceId]);
            if (!$hasLocation) {
                Log::channel('mqtt')->warning(" Trigger Falló: 'location' es NULL para el dispositivo {$deviceId} a pesar de tener coordenadas.");
                $isValid = false;
            }
        }

        // REGLA 2: Si el timestamp del satélite es nuevo, DEBE existir en la tabla positions
        if ($telemetry->satellite && (!$previousSatellite || $telemetry->satellite > $previousSatellite)) {
            // Verificamos en la Hypertable si el trigger pasó el dato
            $positionExists = DB::table('positions')
                ->where('device_id', $deviceId)
                ->where('satellite', $telemetry->satellite)
                ->exists();

            if (!$positionExists) {
                Log::channel('mqtt')->warning(" Trigger Falló: No se insertó el histórico en 'positions' para el dispositivo {$deviceId} (Satélite: {$telemetry->satellite}).");
                $isValid = false;
            }
        }

        // REGLA 3: Si fue un UPDATE, el timestamp de actualización debe ser reciente
        if ($wasUpdate) {
            // Validamos que la fecha de actualización no sea más antigua que hace 5 segundos
            if ($telemetry->updated_at && $telemetry->updated_at->diffInSeconds(now()) > 5) {
                Log::channel('mqtt')->warning(" Trigger Falló: El campo 'updated_at' no se refrescó tras el UPDATE en dispositivo {$deviceId}.");
                $isValid = false;
            }
        }

        // 4. SISTEMA DE ALARMA TASA DE FALLO > 5%
        $this->evaluateFailureRate($isValid);

        return $isValid;
    }

    /**
     * Calcula la tasa de fallos de los triggers y dispara una alarma si supera el 5%
     */
    private function evaluateFailureRate(bool $isValid): void
    {
        // Usamos Redis/Cache nativo de Laravel para llevar el conteo (expira cada 1 hora)
        $totalKey = 'telemetry:triggers:total';
        $failsKey = 'telemetry:triggers:fails';

        Cache::add($totalKey, 0, 3600);
        Cache::add($failsKey, 0, 3600);

        $total = Cache::increment($totalKey);
        $fails = $isValid ? Cache::get($failsKey) : Cache::increment($failsKey);

        // Solo evaluamos el porcentaje si tenemos una muestra significativa (ej. > 100 mensajes)
        if ($total > 100) {
            $failureRate = ($fails / $total) * 100;

            if ($failureRate > 5.0) {
                Log::channel('mqtt')->critical(" ALARMA CRÍTICA: La tasa de fallo de los triggers en PostgreSQL es del " . round($failureRate, 2) . "%. Revisar funciones de Base de Datos URGENTE.");
                
                // Opcional: Aquí podrías disparar un evento que envíe un Slack/Email al equipo DevOps
                
                // Reseteamos contadores para no spamear el log infinitamente
                Cache::put($totalKey, 0, 3600);
                Cache::put($failsKey, 0, 3600);
            }
        }
    }
}