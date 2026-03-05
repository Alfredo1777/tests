<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Modules\GPS\Services\TelemetryService;
use Illuminate\Support\Facades\Log;
use App\Modules\GPS\Validators\TelemetryValidator;
use App\Modules\GPS\DTOs\TelemetryDTO;
use App\Modules\GPS\Models\Device; 
use Illuminate\Validation\ValidationException;
use App\Exceptions\app\Modules\GPS\Exceptions\BusinessRuleException;
use App\Exceptions\app\Modules\GPS\Exceptions\InvalidJsonException;
use App\Modules\GPS\Services\MessageValidatorService;
use App\Modules\GPS\Services\MessageParserService;
use App\Exceptions\app\Modules\GPS\Exceptions\SchemaValidationException;
use App\Modules\GPS\Services\MessageRetryService;
use App\Modules\GPS\Services\DlqService;
use App\Modules\GPS\Services\MessageSanitizerService;
use Illuminate\Support\Lottery;
use App\Modules\GPS\Services\MetricsService;
use Exception;

class MqttSubscribe extends Command
{
    protected $signature = 'mqtt:subscribe';
    protected $description = 'Inicia el subscriber MQTT con el pipeline completo de validación';

    // Inyección de dependencias del Pipeline
    protected $telemetryService;
    protected $parserService;
    protected $sanitizerService;
    protected $validatorService;
    protected $retryService;
    protected $dlqService;
    protected $metricsService;

    public function __construct(
        TelemetryService $telemetryService,
        MessageParserService $parserService,
        MessageSanitizerService $sanitizerService,
        MessageValidatorService $validatorService,
        MessageRetryService $retryService,
        DlqService $dlqService,
        MetricsService $metricsService
    ) {
        parent::__construct();
        $this->telemetryService = $telemetryService;
        $this->parserService = $parserService;
        $this->sanitizerService = $sanitizerService;
        $this->validatorService = $validatorService;
        $this->retryService = $retryService;
        $this->dlqService = $dlqService;
        $this->metricsService = $metricsService;
    }

    public function handle()
    {
        $server   = env('MQTT_HOST', 'localhost');
        $port     = env('MQTT_PORT', 1883);
        $clientId = env('MQTT_CLIENT_ID', 'laravel_worker_') . uniqid();
        $user     = env('MQTT_USERNAME');
        $password = env('MQTT_PASSWORD');

        $this->info("Iniciando conexión MQTT a {$server}:{$port}...");

        try {
            $connectionSettings = (new ConnectionSettings)
                ->setUsername($user)
                ->setPassword($password)
                ->setKeepAliveInterval(60)
                ->setReconnectAutomatically(true);

            $mqtt = new MqttClient($server, $port, $clientId);
            $mqtt->connect($connectionSettings);

            $this->info("Conectado exitosamente como: {$user}");

            $topic = 'gps/devices/+/telemetry';
            
            $mqtt->subscribe($topic, function ($topic, $message) {
                // Aislamiento total: Cada mensaje tiene su propio ciclo de vida
                $this->procesarMensajePipeline($topic, $message);
            }, 1);

            $this->info("Escuchando en: {$topic}");
            $this->info("Presiona Ctrl+C para detener.");

            $mqtt->loop(true);

        } catch (Exception $e) {
            $this->error("Error fatal en el cliente MQTT: " . $e->getMessage());
            Log::channel('mqtt')->critical("MQTT Fatal Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * PIPELINE DE PROCESAMIENTO 
     * Parser -> Sanitizer -> Validator -> TelemetryService
     */
    protected function procesarMensajePipeline($topic, $message)
    {
        $startTime = microtime(true);
        $parts = explode('/', $topic);
        $deviceUuid = $parts[2] ?? 'unknown';
        $messageTrackerId = "msg_" . $deviceUuid . "_" . time();

        // Registrar métrica inicial
        $this->metricsService->recordActiveDevice($deviceUuid);
        
        Log::channel('mqtt')->debug(" [FASE 0] Inicia pipeline para mensaje", ['uuid' => $deviceUuid]);

        try {
            $this->retryService->processWithRetry($messageTrackerId, function () use ($topic, $message, $deviceUuid, $startTime) {
                
                // ---------------------------------------------------------
                //  FASE 1: Parser (JSON Decode y Normalización inicial)
                // ---------------------------------------------------------
                $parsedResult = $this->parserService->parse($topic, $message);
                $cleanPayload = $parsedResult['payload'];
                Log::channel('mqtt')->debug(" [FASE 1] JSON decodificado y normalizado exitosamente");

                // ---------------------------------------------------------
                //  FASE 2: Sanitizer (Corrección de errores comunes)
                // ---------------------------------------------------------
                $sanitizedPayload = $this->sanitizerService->sanitize($deviceUuid, $cleanPayload);
                Log::channel('mqtt')->debug(" [FASE 2] Reglas de sanitización aplicadas");

                // ---------------------------------------------------------
                //  FASE 3: Validator (Validación Estructural y Negocio)
                // ---------------------------------------------------------
                $errores = $this->validatorService->findErrors($deviceUuid, json_encode($sanitizedPayload));
                if (!empty($errores)) {
                    throw new BusinessRuleException("Falló validación de esquema/negocio.");
                }
                Log::channel('mqtt')->debug(" [FASE 3] Validación estricta superada");

                // ---------------------------------------------------------
                //  FASE 4: Persistencia (TelemetryService)
                // ---------------------------------------------------------
                $device = Device::where('uuid', $deviceUuid)->first();
                $telemetryDTO = TelemetryDTO::fromArray($sanitizedPayload);
                
                $this->telemetryService->ingest($device->id, $telemetryDTO);
                Log::channel('mqtt')->debug(" [FASE 4] Ingesta en PostgreSQL exitosa");

                // ---------------------------------------------------------
                //  FASE 5: Métricas y Cierre
                // ---------------------------------------------------------
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                $this->metricsService->recordMessage(true);
                $this->metricsService->recordProcessingTime($durationMs);

                // Log con sampling para no saturar en producción
                Lottery::odds(env('APP_ENV') === 'production' ? 1 : 10, 10)->winner(function () use ($deviceUuid, $durationMs) {
                    Log::channel('mqtt')->info(" Pipeline completado exitosamente", [
                        'device_uuid' => $deviceUuid,
                        'duration_ms' => $durationMs
                    ]);
                })->choose();

                $this->line(" OK: [{$deviceUuid}] procesado en {$durationMs}ms");
            });

        } catch (\Exception $e) {
            // ==========================================
            // MANEJO DE ERRORES (Aislado, no detiene el loop)
            // ==========================================
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $errorType = (new \ReflectionClass($e))->getShortName();

            // Métricas de error
            $this->metricsService->recordMessage(false);
            $this->metricsService->recordError($errorType);

            // Log de error del pipeline
            Log::channel('mqtt')->error(" [ERROR] Pipeline interrumpido", [
                'device_uuid' => $deviceUuid,
                'duration_ms' => $durationMs,
                'error_type' => $errorType,
                'error_message' => $e->getMessage()
            ]);

            $this->error(" Error en pipeline para [{$deviceUuid}]: {$errorType}");
            
            // Enviar a la Dead Letter Queue (DLQ)
            $attempts = str_contains($e->getMessage(), 'Non-retriable') ? 1 : 3;
            $this->dlqService->push($topic, $message, $e, $attempts);
        }
    }
}
