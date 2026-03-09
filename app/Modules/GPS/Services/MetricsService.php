<?php

namespace App\Modules\GPS\Services;

use Illuminate\Support\Facades\Redis;
use App\Modules\GPS\Models\MqttMetric;

class MetricsService
{
    private string $prefix = 'mqtt:metrics:';

    public function recordMessage(bool $isValid): void
    {
        $date = date('Y-m-d');
        Redis::incr($this->prefix . "received:{$date}");
        
        if ($isValid) {
            Redis::incr($this->prefix . "valid:{$date}");
        } else {
            Redis::incr($this->prefix . "invalid:{$date}");
        }
    }

    public function recordError(string $errorType): void
    {
        $date = date('Y-m-d');
        // hIncrBy incrementa el valor de una llave dentro de un hash (diccionario)
        Redis::hincrby($this->prefix . "errors:{$date}", $errorType, 1);
    }

    public function recordProcessingTime(float $milliseconds): void
    {
        $date = date('Y-m-d');
        Redis::incrbyfloat($this->prefix . "time_total:{$date}", $milliseconds);
        Redis::incr($this->prefix . "time_count:{$date}");
    }

    public function recordActiveDevice(string $uuid): void
    {
        $date = date('Y-m-d');
        // SADD agrega a un Set. Si el UUID ya existe, no hace nada (garantiza unicidad)
        Redis::sadd($this->prefix . "devices:{$date}", $uuid);
    }

    public function getTodayMetrics(): array
    {
        $date = date('Y-m-d');
        
        $received = (int) Redis::get($this->prefix . "received:{$date}") ?: 0;
        $invalid = (int) Redis::get($this->prefix . "invalid:{$date}") ?: 0;
        $valid = (int) Redis::get($this->prefix . "valid:{$date}") ?: 0;
        
        $timeTotal = (float) Redis::get($this->prefix . "time_total:{$date}") ?: 0;
        $timeCount = (int) Redis::get($this->prefix . "time_count:{$date}") ?: 0;
        $avgTime = $timeCount > 0 ? round($timeTotal / $timeCount, 2) : 0;
        
        $uniqueDevices = Redis::scard($this->prefix . "devices:{$date}") ?: 0;
        $errors = Redis::hgetall($this->prefix . "errors:{$date}") ?: [];
        
        $errorRate = $received > 0 ? round(($invalid / $received) * 100, 2) : 0;

        return [
            'date' => $date,
            'total_received' => $received,
            'total_valid' => $valid,
            'total_invalid' => $invalid,
            'avg_processing_time_ms' => $avgTime,
            'unique_devices' => $uniqueDevices,
            'error_rate_percent' => $errorRate,
            'errors_by_type' => $errors
        ];
    }

    /**
     * Guarda el estado actual en PostgreSQL (Para ejecutar en un Cronjob a medianoche)
     */
    public function snapshotToDatabase(): void
    {
        $metrics = $this->getTodayMetrics();
        MqttMetric::updateOrCreate(
            ['date' => $metrics['date']],
            $metrics
        );
    }
}