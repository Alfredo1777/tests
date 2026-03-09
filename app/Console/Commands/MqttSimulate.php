<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Modules\GPS\Models\Device; // <-- IMPORTAMOS EL MODELO

class MqttSimulate extends Command
{
    protected $signature = 'mqtt:simulate {--devices=10 : Número de dispositivos a simular} {--interval=30 : Segundos entre ráfagas}';
    protected $description = 'Simula tráfico usando dispositivos reales de la BD';

    public function handle()
    {
        $count = (int) $this->option('devices');
        $interval = (int) $this->option('interval');
        
        $server   = env('MQTT_HOST', 'localhost');
        $port     = env('MQTT_PORT', 1883);
        $clientId = 'simulator_master_' . uniqid();

        // 1. OBTENER DISPOSITIVOS REALES DE LA BD
        $devicesArray = Device::limit($count)->pluck('uuid')->toArray();

        if (empty($devicesArray)) {
            $this->error(" ¡No hay dispositivos en la base de datos! Crea uno primero antes de simular.");
            return 1;
        }

        $this->info(" Iniciando Simulador con " . count($devicesArray) . " dispositivos reales...");

        try {
            // 2. USAR CREDENCIALES LEGALES DEL .ENV
            $settings = (new ConnectionSettings)
                ->setUsername(env('MQTT_USERNAME'))
                ->setPassword(env('MQTT_PASSWORD'));

            $mqtt = new MqttClient($server, $port, $clientId);
            $mqtt->connect($settings);
            $this->info(" Simulador conectado al broker.");

            $ciclo = 1;
            while (true) {
                $this->info("\n--- Iniciando Ráfaga #{$ciclo} ---");
                $start_time = microtime(true);
                
                foreach ($devicesArray as $uuid) {
                    $lat = 19.05 + (mt_rand(-100, 100) / 10000);
                    $lng = -104.31 + (mt_rand(-100, 100) / 10000);
                    
                    // JSON exacto que espera nuestro Validador
                    $payload = json_encode([
                        'latitude' => round($lat, 6),
                        'longitude' => round($lng, 6),
                        'speed' => mt_rand(0, 100),
                        'battery' => mt_rand(10, 100),
                        'satellite' => now()->toIso8601String() // Fecha ISO8601
                    ]);

                    $topic = "gps/devices/{$uuid}/telemetry";
                    
                    $mqtt->publish($topic, $payload, 0);
                    $this->line("📡 Enviado -> {$topic}");
                }

                $end_time = microtime(true);
                $latency_sec = $end_time - $start_time;
                
                $this->info(" Ráfaga enviada en " . round($latency_sec, 4) . " segundos.");
                $this->comment("Esperando {$interval} segundos...");
                
                sleep($interval);
                $ciclo++;
            }

        } catch (\Exception $e) {
            $this->error(" Error de simulación: " . $e->getMessage());
            return 1;
        }
    }
}