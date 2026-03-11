<?php

namespace App\Modules\GPS\Services;

use Illuminate\Support\Facades\Cache;
use App\Models\ResilienceMetric; // Ajusta el namespace si lo pusiste en tu módulo
use Illuminate\Support\Facades\Redis;

class ResilienceMetricsService
{
    private string $prefix = 'mqtt:resilience:';

    // --- CONTADORES ---
    public function incrementCounter(string $name, array $labels = []): void
    {
        $key = $this->prefix . $name . $this->formatLabels($labels);
        Cache::increment($key);
        
        // Guardar en histórico asíncronamente (o directo para este caso)
        ResilienceMetric::create([
            'metric_name' => $name,
            'value' => 1,
            'type' => 'counter',
            'labels' => json_encode($labels)
        ]);
    }

    // --- HISTOGRAMAS (Promedios y Tiempos) ---
    public function recordHistogram(string $name, float $value): void
    {
        $keyTotal = $this->prefix . "{$name}_total";
        $keyCount = $this->prefix . "{$name}_count";
        
        Cache::increment($keyCount);
        // Laravel cache increment no soporta floats nativamente bien, pero podemos usar put:
        $current = Cache::get($keyTotal, 0);
        Cache::put($keyTotal, $current + $value);

        ResilienceMetric::create([
            'metric_name' => $name,
            'value' => $value,
            'type' => 'histogram'
        ]);
    }

    // --- GAUGES (Valores instantáneos como la Memoria) ---
    public function recordGauge(string $name, float $value): void
    {
        Cache::put($this->prefix . $name, $value);
        
        ResilienceMetric::create([
            'metric_name' => $name,
            'value' => $value,
            'type' => 'gauge'
        ]);
    }

    private function formatLabels(array $labels): string
    {
        if (empty($labels)) return '';
        return ':' . md5(json_encode($labels));
    }

    // Generar formato Prometheus
    public function getPrometheusMetrics(): string
    {
        $output = "";
        $metrics = [
            'reconnections_total' => 'counter',
            'messages_processed_total' => 'counter',
            'messages_error_total' => 'counter',
            'circuit_breaker_opens_total' => 'counter',
            'memory_restarts_total' => 'counter',
            'graceful_shutdowns_total' => 'counter',
            'reconnection_time_ms' => 'histogram',
            'processing_time_ms' => 'histogram',
            'memory_usage_mb' => 'gauge',
        ];

        foreach ($metrics as $name => $type) {
            $output .= "# TYPE sigma_mqtt_{$name} {$type}\n";
            if ($type === 'gauge' || $type === 'counter') {
                $val = Cache::get($this->prefix . $name, 0);
                $output .= "sigma_mqtt_{$name} {$val}\n";
            }
            if ($type === 'histogram') {
                $count = Cache::get($this->prefix . $name . '_count', 0);
                $total = Cache::get($this->prefix . $name . '_total', 0);
                $avg = $count > 0 ? ($total / $count) : 0;
                $output .= "sigma_mqtt_{$name}_avg {$avg}\n";
            }
        }
        return $output;
    }
}