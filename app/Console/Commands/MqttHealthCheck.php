<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MqttHealthCheck extends Command
{
    protected $signature = 'mqtt:health-check';
    protected $description = 'Verifica la salud del demonio MQTT Subscriber (Retorna 0 si OK, 1 si Unhealthy)';

    public function handle()
    {
        $now = time();
        $isHealthy = true;
        $errors = [];
        $alertService = app(\App\Modules\GPS\Services\AlertService::class);

        // 1. Verificar Heartbeat reciente (< 90s)
        $lastHeartbeat = Cache::get('mqtt:subscriber:heartbeat');
        if (!$lastHeartbeat || ($now - $lastHeartbeat) > 90) {
            $isHealthy = false;
            $alertService->trigger('worker_dead', 'Heartbeat ausente. El proceso Subscriber está detenido o bloqueado.', [], 'Ejecutar: supervisorctl restart mqtt-worker');
        }

        // 2. Verificar Memoria (< 90% del hard limit de 512MB = ~460MB)
        $memoryUsage = Cache::get('mqtt:subscriber:memory_usage', 0);
        if ($memoryUsage > 460) {
            $isHealthy = false;
            $errors[] = "ADVERTENCIA: Uso de memoria al límite (" . round($memoryUsage, 2) . " MB / 512 MB).";
        }

        // 3. Verificar Tasa de Error (< 20%) usando la ventana del Circuit Breaker
        $window = Cache::get('circuit_breaker:window', []);
        $errorRate = 0;
        if (count($window) > 0) {
            $failures = count(array_filter($window, fn($result) => $result === false));
            $errorRate = ($failures / count($window)) * 100;
        }

        if ($errorRate >= 20) {
            $isHealthy = false;
            $alertService->trigger('high_error_rate', "Tasa de error altísima: {$errorRate}%", ['error_rate' => $errorRate], 'Revisa la Dead Letter Queue y los logs de validación JSON.');
        }
        //Guardamos el conteo previo en caché para comparar
        $lastCount = Cache::get('mqtt:subscriber:last_msg_count', 0);
        $currentCount = $this->processedMessagesCount ?? Cache::get('mqtt:subscriber:messages_processed_total', 0);

        if ($currentCount === $lastCount){
            $alertService->trigger('zero_messages', 'No se han procesado nuevos mensajes en los últimos 10 minutos.', [], 'Verifica que los camiones tengan señal o que el APN celular esté activo.');
        }
        Cache::put('mqtt:subscriber:last_msg_count', $currentCount, 600);

        // IMPRESIÓN DE RESULTADOS
        if (!$isHealthy) {
            $this->error(" UNHEALTHY - El Subscriber presenta problemas:");
            foreach ($errors as $error) {
                $this->line(" - " . $error);
            }
            return 1; // Exit code 1 indica error al sistema operativo/Nagios
        }

        $this->info("HEALTHY - Subscriber operando correctamente.");
        $this->line("Último latido: Hace " . ($now - $lastHeartbeat) . " segundos.");
        $this->line("Memoria: " . round($memoryUsage, 2) . " MB.");
        $this->line("Tasa de error: " . round($errorRate, 2) . "%.");

        return 0; // Exit code 0 indica éxito
    }
}
