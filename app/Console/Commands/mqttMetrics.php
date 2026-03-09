<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\GPS\Services\MetricsService;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttMetrics extends Command
{
    // Agregamos la opción --publish para enviar las métricas por MQTT
    protected $signature = 'mqtt:metrics {--publish : Publicar métricas en el topic gps/events/stats}';
    protected $description = 'Muestra las métricas de procesamiento MQTT en tiempo real';

    public function handle(MetricsService $metricsService)
    {
        $metrics = $metricsService->getTodayMetrics();

        $this->info(" Métricas en Tiempo Real (Redis) - {$metrics['date']}");
        
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Mensajes Recibidos', $metrics['total_received']],
                ['Mensajes Válidos', "<fg=green>{$metrics['total_valid']}</>"],
                ['Mensajes Inválidos', "<fg=red>{$metrics['total_invalid']}</>"],
                ['Tasa de Error', "{$metrics['error_rate_percent']}%"],
                ['Tiempo Promedio', "{$metrics['avg_processing_time_ms']} ms"],
                ['Dispositivos Únicos', $metrics['unique_devices']],
            ]
        );

        if (!empty($metrics['errors_by_type'])) {
            $this->warn("\n Desglose de Errores:");
            foreach ($metrics['errors_by_type'] as $type => $count) {
                $this->line(" - {$type}: {$count}");
            }
        }

        // Subtarea: Considerar publicar métricas en topic MQTT (opcional)
        if ($this->option('publish')) {
            $this->publishToMqtt($metrics);
        }

        return 0;
    }

    private function publishToMqtt(array $metrics): void
    {
        try {
            $server = env('MQTT_HOST', 'localhost');
            $port = env('MQTT_PORT', 1883);
            $clientId = 'laravel_metrics_publisher_' . uniqid();
            
            $mqtt = new MqttClient($server, $port, $clientId);
            $settings = (new ConnectionSettings)
                ->setUsername(env('MQTT_USERNAME'))
                ->setPassword(env('MQTT_PASSWORD'));

            $mqtt->connect($settings);
            
            // Publicamos el JSON de métricas al broker
            $mqtt->publish('gps/events/stats', json_encode($metrics), 0);
            $mqtt->disconnect();
            
            $this->info("\n ¡Métricas publicadas exitosamente en 'gps/events/stats'!");
        } catch (\Exception $e) {
            $this->error("\n Error al publicar métricas: " . $e->getMessage());
        }
    }
}