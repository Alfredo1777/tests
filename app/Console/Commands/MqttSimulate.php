<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttSimulate extends Command
{
    //  CAMBIO AQUÍ: Ahora usamos --devices en lugar de --count
    protected $signature = 'mqtt:simulate {--devices=10 : Número de dispositivos a simular} {--interval=30 : Segundos entre ráfagas}';
    protected $description = 'Simula tráfico de N dispositivos GPS y mide el performance';

    public function handle()
    {
        //  CAMBIO AQUÍ: Leemos la opción 'devices'
        $count = (int) $this->option('devices');
        $interval = (int) $this->option('interval');
        
        $server   = env('MQTT_HOST', 'localhost');
        $port     = env('MQTT_PORT', 1883);
        $clientId = 'simulator_master_' . uniqid();

        $this->info(" Iniciando Simulador de Carga: {$count} dispositivos...");

        try {
            $settings = (new ConnectionSettings)
                ->setUsername('simulator')
                ->setPassword('sim123'); // Usamos el Pase VIP

            $mqtt = new MqttClient($server, $port, $clientId);
            $mqtt->connect($settings);
            $this->info(" Simulador conectado al broker.");

            // Generar UUIDs falsos para los dispositivos
            $devicesArray = [];
            for ($i = 1; $i <= $count; $i++) {
                $devicesArray[] = 'sim-uuid-' . str_pad($i, 4, '0', STR_PAD_LEFT);
            }

            // Loop infinito de envíos
            $ciclo = 1;
            while (true) {
                $this->info("\n--- Iniciando Ráfaga #{$ciclo} ---");
                
                $start_time = microtime(true); // Cronómetro INICIO
                
                foreach ($devicesArray as $uuid) {
                    // Generamos coordenadas falsas (Cerca de Manzanillo, Colima)
                    $lat = 19.05 + (mt_rand(-100, 100) / 10000);
                    $lng = -104.31 + (mt_rand(-100, 100) / 10000);
                    
                    $payload = json_encode([
                        'lat' => round($lat, 6),
                        'lng' => round($lng, 6),
                        'speed' => mt_rand(0, 100),
                        'battery' => mt_rand(10, 100),
                        'ts' => time()
                    ]);

                    $topic = "gps/devices/{$uuid}/telemetry";
                    
                    // Publicamos sin esperar confirmación (QoS 0) para máxima velocidad
                    $mqtt->publish($topic, $payload, 0);
                }

                $end_time = microtime(true); // Cronómetro FIN
                
                // Calcular métricas
                $latency_sec = $end_time - $start_time;
                $msg_per_sec = $latency_sec > 0 ? $count / $latency_sec : 0;

                // Imprimir resultados
                $this->table(
                    ['Métrica', 'Resultado'],
                    [
                        ['Dispositivos simulados', $count],
                        ['Tiempo Total (Latencia)', round($latency_sec, 4) . ' segundos'],
                        ['Rendimiento (Throughput)', round($msg_per_sec, 2) . ' msgs/segundo']
                    ]
                );

                $this->comment("Esperando {$interval} segundos para la siguiente ráfaga...");
                sleep($interval);
                $ciclo++;
            }

        } catch (\Exception $e) {
            $this->error(" Error de simulación: " . $e->getMessage());
            return 1;
        }
    }
}
