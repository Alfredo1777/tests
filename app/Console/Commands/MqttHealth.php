<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;
use Exception;

class MqttHealth extends Command
{
    protected $signature = 'mqtt:health';
    protected $description = 'Verifica la salud del Broker MQTT y publica estadísticas';

    public function handle()
    {
        $server   = env('MQTT_HOST', 'localhost');
        $port     = env('MQTT_PORT', 1883);
        $user     = env('MQTT_USERNAME');
        $password = env('MQTT_PASSWORD');
        $clientId = 'health_monitor_' . uniqid();

        $this->info(" Iniciando diagnóstico del Broker en {$server}:{$port}...");

        $stats = [
            'status' => 'offline',
            'clients_connected' => 0,
            'messages_queued' => 0,
            'memory_bytes' => 0,
            'timestamp' => now()->toDateTimeString()
        ];

        try {
            $settings = (new \PhpMqtt\Client\ConnectionSettings)
                ->setUsername($user)
                ->setPassword($password)
                ->setConnectTimeout(5);

            $stats['status'] = 'online';

            // ========================================================
            // FASE 1: EL ESCUCHA (Recopila los datos de salud)
            // ========================================================
            $mqttListener = new \PhpMqtt\Client\MqttClient($server, $port, $clientId . '_listener');
            $mqttListener->connect($settings);
            
            $mqttListener->subscribe('$SYS/broker/clients/connected', function ($t, $message) use (&$stats) {
                $stats['clients_connected'] = (int) $message;
            }, 0);

            $mqttListener->subscribe('$SYS/broker/messages/stored', function ($t, $message) use (&$stats) {
                $stats['messages_queued'] = (int) $message;
            }, 0);

            $mqttListener->subscribe('$SYS/broker/heap/current', function ($t, $message) use (&$stats) {
                $stats['memory_bytes'] = (int) $message;
            }, 0);

            $this->comment(" Recopilando métricas (esperando pulso del broker)...");

            $mqttListener->registerLoopEventHandler(function ($mqtt, $elapsedTime) use (&$stats) {
                if ($stats['memory_bytes'] > 0 || $elapsedTime >= 10) {
                    $mqtt->interrupt(); 
                }
            });

            $mqttListener->loop(true);
            $mqttListener->disconnect(); // Cerramos limpiamente el escucha

            // ========================================================
            // FASE 2: EL VOCERO (Publica el JSON final)
            // ========================================================
            $stats['timestamp'] = now()->toDateTimeString();
            $payload = json_encode($stats);
            
            $this->info(" Conectando nuevo cliente para publicar...");
            
            $mqttPublisher = new \PhpMqtt\Client\MqttClient($server, $port, $clientId . '_publisher');
            $mqttPublisher->connect($settings);
            $mqttPublisher->publish('gps/events/stats', $payload, 0);
            $mqttPublisher->disconnect(); // El paquete sale limpio y directo
            
            $this->info(" JSON inyectado en la red con éxito.");

            // ========================================================
            // FASE 3: DASHBOARD EN CONSOLA
            // ========================================================
            $this->newLine();
            $this->info(' DASHBOARD DE SALUD MQTT');
            $this->table(
                ['Métrica', 'Valor', 'Estado'],
                [
                    ['Estado del Broker', $stats['status'], $stats['status'] === 'online' ? ' OK' : ' CAÍDO'],
                    ['Clientes Conectados', $stats['clients_connected'], $stats['clients_connected'] > 0 ? ' Activos' : ' Cero'],
                    ['Mensajes en Cola', $stats['messages_queued'], ' Info'],
                    ['Uso de Memoria RAM', round($stats['memory_bytes'] / 1024 / 1024, 2) . ' MB', ' Info'],
                    ['Última Verificación', $stats['timestamp'], '-']
                ]
            );
            $this->newLine();

        } catch (\Exception $e) {
            $this->error(" FALLO CRÍTICO: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function mostrarDashboard($stats)
    {
        $this->newLine();
        $this->info(' DASHBOARD DE SALUD MQTT');
        $this->table(
            ['Métrica', 'Valor', 'Estado'],
            [
                ['Estado del Broker', $stats['status'], $stats['status'] === 'online' ? ' OK' : ' CAÍDO'],
                ['Clientes Conectados', $stats['clients_connected'], $stats['clients_connected'] > 0 ? ' Activos' : ' Cero'],
                ['Mensajes en Cola', $stats['messages_queued'], ' Info'],
                ['Uso de Memoria RAM', round($stats['memory_bytes'] / 1024 / 1024, 2) . ' MB', ' Info'],
                ['Última Verificación', $stats['timestamp'], '-']
            ]
        );
        $this->newLine();
    }
}
