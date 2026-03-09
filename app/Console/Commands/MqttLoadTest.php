<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\DTOs\TelemetryDTO;
use App\Modules\GPS\Services\TelemetryBatchService;

class MqttLoadTest extends Command
{
    protected $signature = 'mqtt:load-test {--devices=50} {--duration=60}';
    protected $description = 'Ejecuta un test de estrés de persistencia (100 msgs/sec)';

    public function handle(TelemetryBatchService $batchService)
    {
        $numDevices = (int) $this->option('devices');
        $durationSeconds = (int) $this->option('duration');
        
        $this->info(" Iniciando Test de Performance SIGMA GPS");
        $this->line("Dispositivos concurrentes: {$numDevices}");
        $this->line("Duración: {$durationSeconds} segundos");

        // Pre-cargar/Crear dispositivos de prueba en RAM
        $deviceIds = Device::limit($numDevices)->pluck('id')->toArray();
        if (empty($deviceIds)) {
            $this->error("No hay dispositivos en la BD para probar.");
            return 1;
        }

        $endTime = microtime(true) + $durationSeconds;
        $totalMessages = 0;
        $latencies = [];

        $this->output->progressStart($durationSeconds);

        while (microtime(true) < $endTime) {
            $loopStart = microtime(true);
            $currentSecond = floor($loopStart);

            // Simulamos la ráfaga de 100 mensajes por segundo
            for ($i = 0; $i < 100; $i++) {
                $deviceId = $deviceIds[array_rand($deviceIds)];
                
                $dto = TelemetryDTO::fromArray([
                    'latitude'  => 19.05 + (mt_rand(-100, 100) / 10000),
                    'longitude' => -104.31 + (mt_rand(-100, 100) / 10000),
                    'altitude'  => 0,
                    'accuracy'  => 1.0,
                    'speed'     => mt_rand(40, 80), // Velocidad realista
                    'course'    => 90.0,
                    'hdop'      => 1.0,
                    'battery'   => mt_rand(10, 100),
                    'rssi'      => -70,
                    'conected'  => true,
                    'ignition'  => true,
                    'satellite' => now()->toIso8601String()
                ]);

                $msgStart = microtime(true);
                
                // Usamos el Batch Service para simular la carga real del Worker
                $batchService->push($deviceId, $dto, "load_test_" . uniqid());
                
                $latencies[] = (microtime(true) - $msgStart) * 1000; // ms
                $totalMessages++;
            }

            // Forzamos el flush del batch restante
            $batchService->flush();

            // Esperar a que termine el segundo actual para mantener la tasa de 100 msgs/sec
            $sleepTime = 1 - (microtime(true) - $loopStart);
            if ($sleepTime > 0) {
                usleep($sleepTime * 1000000);
            }
            
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        // Calcular métricas
        $avgLatency = array_sum($latencies) / count($latencies);
        $maxLatency = max($latencies);
        $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;

        $this->info("\n RESULTADOS DEL LOAD TEST:");
        $this->table(
            ['Métrica', 'Valor', 'Estado'],
            [
                ['Mensajes Procesados', number_format($totalMessages), 'Ok'],
                ['Latencia Promedio', round($avgLatency, 2) . ' ms', $avgLatency < 100 ? ' Pasa' : ' Falla'],
                ['Latencia Máxima', round($maxLatency, 2) . ' ms', '-'],
                ['Pico de Memoria RAM', round($memoryPeak, 2) . ' MB', $memoryPeak < 128 ? ' Sin Leaks' : ' Alto'],
            ]
        );

        return 0;
    }
}