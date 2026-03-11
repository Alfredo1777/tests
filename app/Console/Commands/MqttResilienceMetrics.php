<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MqttResilienceMetrics extends Command
{
    protected $signature = 'mqtt:resilience-metrics {--period=day : hour, day, o week}';
    protected $description = 'Muestra estadísticas de manejo de errores y recuperación';

    public function handle()
    {
        $period = $this->option('period');
        $dateFrom = match($period) {
            'hour' => Carbon::now()->subHour(),
            'week' => Carbon::now()->subWeek(),
            default => Carbon::now()->subDay(),
        };

        $this->info(" MÉTRICAS DE RESILIENCIA (Último/a: {$period})");
        $this->line("==================================================");

        // Agrupando desde PostgreSQL
        $stats = DB::table('resilience_metrics')
            ->select('metric_name', DB::raw('SUM(value) as total_val'), DB::raw('AVG(value) as avg_val'))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('metric_name')
            ->get()
            ->keyBy('metric_name');

        $this->table(
            ['Métrica de Resiliencia', 'Valor Histórico', 'Estado en Tiempo Real (Redis)'],
            [
                [' Reconexiones al Broker', $stats['reconnections_total']->total_val ?? 0, Cache::get('mqtt:resilience:reconnections_total', 0)],
                [' Circuit Breaker Abiertos', $stats['circuit_breaker_opens_total']->total_val ?? 0, Cache::get('mqtt:resilience:circuit_breaker_opens_total', 0)],
                [' Restarts por Memoria (Preventivo)', $stats['memory_restarts_total']->total_val ?? 0, Cache::get('mqtt:resilience:memory_restarts_total', 0)],
                [' Graceful Shutdowns Exitosos', $stats['graceful_shutdowns_total']->total_val ?? 0, Cache::get('mqtt:resilience:graceful_shutdowns_total', 0)],
                [' Tiempo Promedio Reconexión', round($stats['reconnection_time_ms']->avg_val ?? 0, 2) . ' ms', '-'],
                [' Uso de Memoria Promedio', round($stats['memory_usage_mb']->avg_val ?? 0, 2) . ' MB', round(Cache::get('mqtt:resilience:memory_usage_mb', 0), 2) . ' MB'],
            ]
        );
        
        return 0;
    }
}
