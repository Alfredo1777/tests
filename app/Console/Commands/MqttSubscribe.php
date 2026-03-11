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
use App\Modules\GPS\Services\PersistenceQueueService;
use Illuminate\Support\Facades\Cache;
use App\Modules\GPS\Services\TelemetryBatchService;
use App\Modules\GPS\Services\CircuitBreakerService;
use App\Exceptions\app\Modules\GPS\Exceptions\CircuitBreakerException;
use App\Modules\GPS\Services\AlertService;
use App\Modules\GPS\Services\ResilienceMetricsService;
use Exception;

class MqttSubscribe extends Command
{
    protected $signature = 'mqtt:subscribe {--max-runtime= : Tiempo máximo de ejecución en segundos antes de reinicio preventivo}';
    protected $description = 'Inicia el subscriber MQTT con el pipeline completo de validación';

    // Inyección de dependencias del Pipeline
    protected $telemetryService;
    protected $parserService;
    protected $sanitizerService;
    protected $validatorService;
    protected $retryService;
    protected $dlqService;
    protected $metricsService;
    protected $persistenceQueue;
    protected $batchService;
    protected $circuitBreaker;
    protected ?MqttClient $mqttClient = null;
    protected bool $shouldExit = false;
    protected int $processStartTime = 0;
    protected int $lastMemoryCheck = 0;
    protected int $lastHeartbeat = 0;
    protected int $processedMessagesCount = 0;
    protected $alertService;
    protected $resilienceMetrics;

    public function __construct(
        TelemetryService $telemetryService,
        MessageParserService $parserService,
        MessageSanitizerService $sanitizerService,
        MessageValidatorService $validatorService,
        MessageRetryService $retryService,
        DlqService $dlqService,
        MetricsService $metricsService,
        PersistenceQueueService $persistenceQueue,
        TelemetryBatchService $batchService,
        CircuitBreakerService $circuitBreaker,
        AlertService $alertService,
        ResilienceMetricsService $resilienceMetrics
    ) {
        parent::__construct();
        $this->telemetryService = $telemetryService;
        $this->parserService = $parserService;
        $this->sanitizerService = $sanitizerService;
        $this->validatorService = $validatorService;
        $this->retryService = $retryService;
        $this->dlqService = $dlqService;
        $this->metricsService = $metricsService;
        $this->persistenceQueue = $persistenceQueue;
        $this->batchService = $batchService;
        $this->circuitBreaker = $circuitBreaker;
        $this->alertService = $alertService;
        $this->resilienceMetrics = $resilienceMetrics;
    }

    public function handle()
    {
        if (extension_loaded('pcntl')) {
            $this->trap([\SIGINT, \SIGTERM], function ($signal) {
                $reason = $signal === \SIGINT ? 'SIGINT (Interrupción Manual)' : 'SIGTERM (Reinicio del Sistema/Docker)';
                $this->initiateGracefulShutdown($this->mqttClient, $reason);
            });
        } else {
            // Si estamos en Windows, simplemente avisamos sin quebrar el código
            $this->warn(" Extensión PCNTL no detectada (normal en Windows). El Graceful Shutdown por señales OS no está activo localmente.");
        }
        $server   = env('MQTT_HOST', 'localhost');
        $port     = env('MQTT_PORT', 1883);
        $clientId = env('MQTT_CLIENT_ID', 'laravel_worker_') . uniqid();
        $user     = env('MQTT_USERNAME');
        $password = env('MQTT_PASSWORD');
        $topic = env('MQTT_TOPIC', 'gps/devices/+/telemetry');

        $attempt = 0;
        $firstDisconnectTime = null;
        $this->processStartTime = time();
        $this->lastMemoryCheck = time();

        $this->info("Iniciando Demonio MQTT (SIGMA GPS) con protección de reconexión");
        // BUCLE INFINITO DE RECONEXIÓN
        while (!$this->shouldExit) {
            try {
                $this->mqttClient = new MqttClient($server, $port, $clientId);

                $connectionSettings = (new ConnectionSettings)
                    ->setUsername($username)
                    ->setPassword($password)
                    ->setKeepAliveInterval(60) // Detecta caídas cada 60s
                    // OPCIONAL: LWT del propio Backend para avisar si se muere
                    ->setLastWillTopic('gps/backend/status')
                    ->setLastWillMessage('offline')
                    ->setLastWillQualityOfService(1)
                    ->setRetainLastWill(true);

                $mqtt->connect($connectionSettings, true); // true = Clean Session

                // ---  SI LLEGAMOS AQUÍ: CONEXIÓN EXITOSA ---
                if ($attempt > 0) {
                    $this->info(" Reconexión MQTT exitosa.");
                    Log::channel('mqtt')->info(" Reconexión MQTT exitosa después de {$attempt} intentos.");
                    $this->resilienceMetrics->incrementCounter('reconnections_total');
                    $attempt = 0; // Reiniciar contadores
                    $firstDisconnectTime = null;
                }

                // Publicamos que el backend está vivo
                $mqtt->publish('gps/backend/status', 'online', 1, true);

                $this->info("Suscrito a: {$topic}");
                
                // Suscripción al topic principal
                $this->mqttClient->subscribe($topic, function ($topic, $message) {
                    $this->procesarMensajePipeline($topic, $message);
                }, 1);

                // Mantenemos el Batch Timer de la tarea anterior
                $this->mqttClient->registerLoopEventHandler(function (MqttClient $client, float $elapsedTime) {
                    //1. Batch flush logic
                    if ((microtime(true) - $this->batchService->getLastFlushTime()) > 0.1) {
                        $this->batchService->flush();
                    }
                    //2. Monitoreo de Ram y Runtime
                    $this->checkMemoryAndRuntime($client);

                    //3. Emitir latidos de vida
                    $this->sendHeartbeat($client);
                });

                // Bloquea el proceso y escucha infinitamente.
                // Si la red cae o Mosquitto se reinicia, esto lanzará una excepción.
                $this->mqttClient->loop(true);
                if ($this->shouldExit) {
                    $mqtt->disconnect();
                    $this->info(" Graceful shutdown completado limpiamente. Supervisor reiniciará el proceso.");
                    return 0; // Código 0 indica salida sin error
                } 

            } catch (MqttClientException | Exception $e) {
                // ---  SI LLEGAMOS AQUÍ: ERROR DE RED O DESCONEXIÓN ---
                
                if ($firstDisconnectTime === null) {
                    $firstDisconnectTime = time();
                }

                $attempt++;

                // CÁLCULO DE BACKOFF EXPONENCIAL
                // Intento 1: 0s, Intento 2: 1s, Intento 3: 2s, Intento 4: 4s, Intento 5: 8s, Intento 6: 16s...
                $wait = 0;
                if ($attempt > 1) {
                    $wait = pow(2, $attempt - 2); 
                    if ($wait > 30) {
                        $wait = 30; // Máximo wait solicitado: 30 segundos
                    }
                }

                $errorMsg = $e->getMessage();
                Log::channel('mqtt')->warning(" Conexión MQTT perdida. Intento #{$attempt} en {$wait}s. Causa: {$errorMsg}");
                $this->warn("Desconectado del Broker. Reintentando en {$wait}s... (Intento {$attempt})");

                // ALERTA DE 5 MINUTOS DE CAÍDA
                $downtime = time() - $firstDisconnectTime;
                if ($downtime >= 300) {
                    $this->alertService->trigger(
                        'broker_disconnect',
                        'El Worker no ha podido reconectarse al Broker Mosquitto por más de 5 minutos.',
                        ['downtime_seconds' => $downtime, 'attempt' => $attempt],
                        '1. Verifica si el contenedor de Docker "Mosquitto" esta corriendo. 2. Revisa reglas de firewall en el puerto 1883. 3. Revisa credenciales.'
                    );
                }

                // Esperamos el tiempo definido antes de la siguiente vuelta del while
                if ($wait > 0) {
                    sleep($wait);
                }
            }
        }
        return 0;
    }

    /**
     * PIPELINE DE PROCESAMIENTO 
     * Parser -> Sanitizer -> Validator -> TelemetryService
     */
    protected function procesarMensajePipeline($topic, $message)
    {
        if (!$this->circuitBreaker->checkConnection()){
            throw new CircuitBreakerException("El sistema está en pausa temporal (Circuit Breaker Activo).");
        }
        $this->info("Recibido de mosquitto: {$topic}");
        $startTime = microtime(true);
        $parts = explode('/', $topic);
        $messageId = uniqid('msg_');
        $deviceUuid = $parts[2] ?? 'unknown';
        $messageTrackerId = "msg_" . $deviceUuid . "_" . time();
        $payloadExcerpt = substr($message, 0, 500); // Cortamos a 500 chars para no saturar logs

        // Registrar métrica inicial
        $this->metricsService->recordActiveDevice($deviceUuid);
        
        Log::channel('mqtt')->debug(" [FASE 0] Inicia pipeline para mensaje", ['uuid' => $deviceUuid]);
        $this->processedMessagesCount++;
        try {
            $this->retryService->processWithRetry($messageTrackerId, function () use ($topic, $message, $deviceUuid, $startTime, $messageTrackerId) {
                
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
                //  FASE 4: Persistencia (Caché + Batching)
                // ---------------------------------------------------------
                $traceId = $messageTrackerId;
                $deviceId = Cache::remember("device_uuid:{$deviceUuid}", 300, function () use ($deviceUuid) {
                    $device = Device::where('uuid', $deviceUuid)->first();
                    return $device ? $device->id : null;
                });

                if (!$deviceId) {
                    throw new \App\Exceptions\app\Modules\GPS\Exceptions\DeviceNotFoundException("UUID [{$deviceUuid}] no existe en BD.");
                }

                $telemetryDTO = TelemetryDTO::fromArray($sanitizedPayload);
                
                try {
                    $this->batchService->push($deviceId, $telemetryDTO, $traceId);
                    Log::channel('mqtt')->debug(" [{$traceId}] Mensaje encolado en memoria (Batching)");
                } catch (\Exception $dbError) {
                    $this->persistenceQueue->enqueuePersistence($deviceId, $telemetryDTO, $dbError->getMessage());
                    throw $dbError;
                }

                // ---------------------------------------------------------
                //  FASE 5: Métricas y Cierre
                // ---------------------------------------------------------
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                $this->metricsService->recordMessage(true);
                $this->metricsService->recordProcessingTime($durationMs);
                $this->circuitBreaker->recordSuccess();

                Lottery::odds(env('APP_ENV') === 'production' ? 1 : 10, 10)->winner(function () use ($deviceUuid, $durationMs) {
                    Log::channel('mqtt')->info(" Pipeline completado exitosamente", [
                        'device_uuid' => $deviceUuid,
                        'duration_ms' => $durationMs
                    ]);
                })->choose();

                $this->line(" OK: [{$deviceUuid}] procesado en {$durationMs}ms");
            });

            // --- AUDITORÍA DE TIEMPO (TIMEOUT LOG) ---
            $duration = microtime(true) - $startTime;
            if ($duration > 30) {
                Log::channel('mqtt')->warning("[{$messageId}]  ALERTA DE TIMEOUT: El procesamiento tomó {$duration}s", [
                    'topic' => $topic
                ]);
            }
        } catch (CircuitBreakerException $e) {
            //  PAUSA SISTÉMICA: Interrumpimos el loop sin mandar a la DLQ
            $this->warn("-" . $e->getMessage());
            $this->resilienceMetrics->incrementCounter('circuit_breaker_opens_total');
            sleep(5); // Pausa corta para no saturar CPU en ráfagas MQTT
            throw $e; // Lo lanzamos hacia la Capa 1 para que reinicie la conexión con el broker

        } catch (\App\Exceptions\app\Modules\GPS\Exceptions\SchemaValidationException | \App\Exceptions\app\Modules\GPS\Exceptions\BusinessRuleException $e) {
            //  WARNING: Error recuperable o de validación (Ej. Batería en 200%)
            $this->logMessageError('WARNING', $e, $topic, $payloadExcerpt, $messageId);
            $this->handlePipelineFailure($e, $deviceUuid, $startTime, $topic, $message, 'warning');

        } catch (\JsonException | \App\Exceptions\app\Modules\GPS\Exceptions\InvalidJsonException $e) {
            //  ERROR: Mensaje individual defectuoso (Ej. JSON malformado)
            $this->logMessageError('ERROR', $e, $topic, $payloadExcerpt, $messageId);
            $this->handlePipelineFailure($e, $deviceUuid, $startTime, $topic, $message, 'error');

        } catch (\PDOException | \Illuminate\Database\QueryException $e) {
            //  ERROR: Fallo de Base de Datos
            $this->logMessageError('ERROR', $e, $topic, $payloadExcerpt, $messageId);
            $this->handlePipelineFailure($e, $deviceUuid, $startTime, $topic, $message, 'database');

        } catch (\Exception $e) {
            //  CRITICAL: Error grave e inesperado (Ej. Falta de RAM o Configuración rota)
            $this->logMessageError('CRITICAL', $e, $topic, $payloadExcerpt, $messageId);
            $this->handlePipelineFailure($e, $deviceUuid, $startTime, $topic, $message, 'critical');
            
            // Si el error menciona memoria o configuración, relanzamos la excepción
            // para detener el subscriber y dejar que Docker (Supervisor) lo reinicie limpio.
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'memory') || str_contains($msg, 'config')) {
                throw $e; 
            }
        }
    }

    /**
     * Helper para formatear y guardar el error con el contexto completo
     */
    private function logMessageError(string $level, \Exception $e, string $topic, string $payload, string $msgId)
    {
        $context = [
            'topic' => $topic,
            'payload_snippet' => $payload,
            'timestamp' => now()->toIso8601String(),
            // Truncamos el Stack Trace a 1000 caracteres para no agotar el disco
            'trace' => substr($e->getTraceAsString(), 0, 1000) 
        ];

        $logMessage = "[{$msgId}] Fallo aislado en pipeline: " . $e->getMessage();

        // Clasificación de logs
        match ($level) {
            'WARNING' => Log::channel('mqtt')->warning($logMessage, $context),
            'ERROR'   => Log::channel('mqtt')->error($logMessage, $context),
            'CRITICAL'=> Log::channel('mqtt')->critical($logMessage, $context),
            default   => Log::channel('mqtt')->error($logMessage, $context),
        };
    }

    /**
     * Helper unificado para métricas, consola y envío a DLQ
     */
    private function handlePipelineFailure(\Exception $e, string $deviceUuid, float $startTime, string $topic, string $message, string $category)
    {
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $errorType = (new \ReflectionClass($e))->getShortName();
        $this->circuitBreaker->recordFailure();

        // Incrementamos contadores en Caché y usamos tu metricsService
        Cache::increment("mqtt:errors:{$category}");
        Cache::increment("mqtt:errors:total");
        $this->metricsService->recordMessage(false);
        $this->metricsService->recordError($errorType);

        $this->error(" Error en pipeline para [{$deviceUuid}]: {$errorType}");
        
        // Enviar a la Dead Letter Queue (DLQ) usando tu servicio existente
        $attempts = str_contains($e->getMessage(), 'Non-retriable') ? 1 : 3;
        $this->dlqService->push($topic, $message, $e, $attempts);
    }
    /**
     * Monitorea la memoria RAM y el tiempo de ejecución.
     * Se ejecuta muy frecuentemente, por lo que usamos temporizadores para no saturar el CPU.
     */
    private function checkMemoryAndRuntime(MqttClient $mqtt): void
    {
        $now = time();
        $maxRuntime = $this->option('max-runtime');

        // 1. EVALUAR MAX RUNTIME
        if ($maxRuntime && ($now - $this->processStartTime) >= (int) $maxRuntime) {
            $this->info(" Max runtime alcanzado ({$maxRuntime}s). Iniciando reinicio preventivo...");
            $this->initiateGracefulShutdown($mqtt, 'max_runtime');
            return;
        }

        // 2. EVALUAR MEMORIA RAM (Sólo cada 5 minutos = 300 segundos)
        if (($now - $this->lastMemoryCheck) >= 300) {
            $this->lastMemoryCheck = $now;
            
            $memoryUsage = memory_get_usage(true) / 1024 / 1024; // Convertir a MB
            $peakMemory = memory_get_peak_usage(true) / 1024 / 1024; // Convertir a MB
            $this->resilienceMetrics->recordGauge('memory_usage_mb', $memoryUsage);
            $limit = 512; // Hard limit en MB

            Log::channel('mqtt')->debug(" [Health Check] Memoria Actual: " . round($memoryUsage, 2) . " MB | Pico: " . round($peakMemory, 2) . " MB | Límite: {$limit} MB");

            if ($memoryUsage >= 512) {
                Log::channel('mqtt')->error(" HARD LIMIT de memoria alcanzado (" . round($memoryUsage, 2) . " MB). Iniciando Graceful Restart para liberar RAM.");
                $this->initiateGracefulShutdown($mqtt, 'memory_limit');
            } elseif ($memoryUsage >= 256) {
                Log::channel('mqtt')->warning(" SOFT LIMIT de memoria alcanzado (" . round($memoryUsage, 2) . " MB). Vigilando posible memory leak.");
            }
        }
    }

    /**
     * Secuencia de 7 pasos para un apagado perfecto sin pérdida de datos.
     */
    private function initiateGracefulShutdown(?MqttClient $mqtt, string $reason): void
    {
        // Evitar que el shutdown se dispare dos veces
        if ($this->shouldExit) {
            return; 
        }

        $this->shouldExit = true;
        $this->info("\n Iniciando Súper Graceful Shutdown motivado por: {$reason}");
        Log::channel('mqtt')->info(" Iniciando Graceful Shutdown motivado por: {$reason}");

        // --- TIMEOUT DE SHUTDOWN (60 SEGUNDOS MAX) ---
        // Si el proceso de apagado se congela por culpa de la red o BD, 
        // pcntl_alarm enviará una señal de muerte fulminante a los 60 segundos.
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(60); 
        }
        if ($reason === 'memory_limit') {
            $this->resilienceMetrics->incrementCounter('memory_restarts_total');
        }

        try {
            // PASO 1: Dejar de aceptar nuevos mensajes
            if ($mqtt && $mqtt->isConnected()) {
                $topic = env('MQTT_TOPIC', 'gps/devices/+/telemetry');
                $mqtt->unsubscribe($topic);
                $this->info("✓ 1. Unsubscribed de topics (Dejando de recibir mensajes).");
            }

            // PASO 2 y 3: Esperar y Flush de queues en tránsito
            // Aquí vaciamos todos los arreglos de la memoria RAM (Batching) a PostgreSQL
            $this->batchService->flush();
            $this->info("✓ 2/3. Flush de memoria (Batching) persistido en BD correctamente.");

            // PASO 4: Preservar estado en Redis antes de salir (Para Debugging posterior)
            $estadoFinal = [
                'reason' => $reason,
                'timestamp' => now()->toIso8601String(),
                'processed_messages' => $this->processedMessagesCount,
                'uptime_seconds' => time() - $this->processStartTime,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ];
            Cache::put('mqtt:subscriber:last_shutdown', $estadoFinal, 86400); // Guardar por 24 horas
            $this->info("✓ 4. Estado final inyectado en Redis para debugging.");

            // PASO 5: Desconectar del broker MQTT limpiamente
            if ($mqtt && $mqtt->isConnected()) {
                // Retirar el Testamento (LWT) publicando offline limpio
                $mqtt->publish('gps/backend/status', 'offline', 1, true);
                $mqtt->interrupt(); // Rompe el loop bloqueante de forma segura
                $mqtt->disconnect();
                $this->info("✓ 5. Desconectado del Broker Mosquitto elegantemente.");
            }

            // PASO 6: Cerrar conexiones de BD
            // Esto evita dejar conexiones "Zombies" o "Idle" en el Pool de PostgreSQL
            \Illuminate\Support\Facades\DB::disconnect();
            $this->info("✓ 6. Conexiones a PostgreSQL cerradas.");

            // PASO 7: Loggear estadísticas finales y salir
            Log::channel('mqtt')->info(" Shutdown completado al 100%.", $estadoFinal);
            $this->info("✓ 7. Shutdown completado. Saliendo de forma segura...");
            $this->resilienceMetrics->incrementCounter('graceful_shutdowns_total');

            // Desactivar la alarma de muerte fulminante ya que terminamos con éxito
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0); 
            }

            exit(0); // Código 0 (Success) -> Supervisor lo reiniciará pacíficamente

        } catch (\Exception $e) {
            Log::channel('mqtt')->critical(" Falla crítica durante el Graceful Shutdown: " . $e->getMessage());
            $this->error("Falla durante el apagado: " . $e->getMessage());
            exit(1); // Salir con error
        }
    }

    /**
     * Emite un latido cada 60 segundos hacia Reids, MQTT y un archivo de estado
     */
    private function sendHeartbeat(MqttClient $mqtt): void
    {
        $now = time();
        if (($now - $this->lastHeartbeat) >= 60) {
            $this->lastHeartbeat = $now;

            //1. Almacenar en Redis con TTL de 90 segundos
            Cache::put('mqtt:subscriber:heartbeat', $now, 90);

            //Guardamos el uso de RAM para que el comando Health check lo pueda leer
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            Cache::put('mqtt:subscriber:memory_usage', $memoryUsage, 90);

            // 2. Publicar en topic MQTT
            $payload = json_encode([
                'status' => 'alive',
                'timestamp' => now()->toIso8601String(),
                'messages_processed' => $this->processedMessagesCount,
                'memory_mb' => round($memoryUsage, 2)
            ]);
            $mqtt->publish('gps/system/subscriber/heartbeat', $payload, 1, false);

            //3. Crear archivo de estado en el sistema local
            //Usamos storage_path() de Laravel para compatbilidad entre Windows/Linux
            $statusFile = storage_path('app/mqtt-subscriber-status.json');
            file_put_contents($statusFile, json_encode([
                'status' => 'alive',
                'last_heartbeat' => $now,
                'last_heartbeat_human' => now()->toIso8601String(),
                'memory_usage_mb' => round($memoryUsage, 2),
                'messages_processed' => $this->processedMessagesCount,
                'uptime_seconds' => $now - $this->processStartTime
            ], JSON_PRETTY_PRINT));

            Log::channel('mqtt')->debug(" Heartbeat emitido exitosamente.");
        }
    }
}
