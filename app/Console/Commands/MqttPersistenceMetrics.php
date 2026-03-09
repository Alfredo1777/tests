<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\GPS\Services\PersistenceMetricsService;

class MqttPersistenceMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:persistence-metrics';
    protected $description = 'Muestra las estadisticas de persistencia y salud de PostgreSQL en tiempo real';
    /**
     * Execute the console command.
     */
    public function handle(PersistenceMetricsService $metrics)
    {
        $this->info("\n Métricas de persistencia (SIGMA GPS)");
        $this->line("===========================================");

        $stats = $metrics->getStats();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Total UPSERTs Intentados', $stats['attempts']],
                ['UPSERTs Exitosos', $stats['success']],
                ['UPSERTs Fallidos', $stats['failed']],
                ['Timepo Prom. de Persistencia', $stats['avg_time_ms'] . ' ms'],
                ['Mensajes en Queue de Retry', $stats['queue_size']],
                ['Tasa de Éxito de Triggers', $stats['trigger_rate'] . '%'],
            ] 
        );

        if (!empty($stats['errors'])) {
            $this->line("\n Desglose de Errores:");
            $errorRows = [];
            foreach ($stats['errors'] as $type => $count) {
                $errorRows[] = [$type, $count];
            }
            $this->table(['Tipo de Error (Exception)', 'Cantidad'], $errorRows);
        } else {
            $this->info("\n Cero errores detectados. La base de datos esta operando al 100%.");
        }
        return 0;
    }
}
