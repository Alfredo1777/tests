<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Modules\GPS\Services\TelemetryService;
use Illuminate\Support\Facades\Log;
use Exception;

class MqttSubscribe extends Command
{
    /**
     * El nombre del comando para ejecutar en terminal.
     */
    protected $signature = 'mqtt:subscribe';

    /**
     * Descripción del comando.
     */
    protected $description = 'Inicia el proceso de escucha MQTT para telemetría GPS';

    /**
     * El servicio que guardará los datos en la BD.
     */
    protected $telemetryService;

    public function __construct(TelemetryService $telemetryService)
    {
        parent::__construct();
        $this->telemetryService = $telemetryService;
    }

    /**
     * Ejecución del comando.
     */
    public function handle()
    {
        $server   = env('MQTT_HOST', 'localhost');
        $port     = env('MQTT_PORT', 1883);
        $clientId = env('MQTT_CLIENT_ID', 'laravel_worker_01');
        $user     = env('MQTT_USERNAME');
        $password = env('MQTT_PASSWORD');

        $this->info("Iniciando conexión MQTT a {$server}:{$port}...");

        try {
            // 1. Configuración de conexión (Keep Alive y Autenticación)
            $connectionSettings = (new ConnectionSettings)
                ->setUsername($user)
                ->setPassword($password)
                ->setKeepAliveInterval(60)
                ->setLastWillTopic('sigma/sys/alerts/worker')
                ->setLastWillMessage('Worker desconectado inesperadamente')
                ->setReconnectAutomatically(true);

            // 2. Crear instancia del cliente
            $mqtt = new MqttClient($server, $port, $clientId);
            $mqtt->connect($connectionSettings);

            $this->info("Conectado exitosamente como: {$user}");

            // 3. Suscribirse al topic con Wildcard (+)
            // Escuchamos 'gps/devices/+/telemetry'
            $topic = 'gps/devices/+/telemetry';
            
            $mqtt->subscribe($topic, function ($topic, $message) {
                $this->procesarMensaje($topic, $message);
            }, 1); // QoS 1

            $this->info("Escuchando en: {$topic}");
            $this->info("Presiona Ctrl+C para detener.");

            // 4. Iniciar el Loop Infinito
            $mqtt->loop(true);

        } catch (Exception $e) {
            $this->error("Error fatal en el cliente MQTT: " . $e->getMessage());
            Log::error("MQTT Fatal Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Lógica de procesamiento de cada mensaje recibido
     */
    protected function procesarMensaje($topic, $message)
    {
        try {
            // A. Extraer UUID del Topic
            // El topic es: gps/devices/{UUID}/telemetry
            $parts = explode('/', $topic);
            
            // Validamos que el topic tenga la estructura correcta
            if (count($parts) < 4 || $parts[2] === '+') {
                throw new Exception("Estructura de topic inválida: {$topic}");
            }
            
            $deviceUuid = $parts[2]; // El UUID está en la posición 2

            // B. Parsear el JSON
            $payload = json_decode($message, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON inválido recibido: " . json_last_error_msg());
            }

            $this->line("Dato recibido de [{$deviceUuid}]");

            // C. Llamar al Servicio (Lo que ya programaste antes)
            // El Service espera (string $uuid, array $data)
            $this->telemetryService->process($deviceUuid, $payload);

            // D. Log de éxito (Opcional, para no saturar disco en producción)
            // Log::info("Telemetría procesada para {$deviceUuid}");

        } catch (Exception $e) {
            // E. Manejo de Errores (¡NO DETENER EL LOOP!)
            $this->error("Error procesando mensaje: " . $e->getMessage());
            Log::warning("MQTT Processing Error", [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'payload_preview' => substr($message, 0, 50)
            ]);
        }
    }
}
