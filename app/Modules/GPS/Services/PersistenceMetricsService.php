<?php

namespace App\Modules\GPS\Services;

use Illuminate\Support\Facades\Cache;
use App\Modules\GPS\Models\TelemetryRetryQueue;
use App\Modules\GPS\Models\MqttMetric;
use Illuminate\Support\Facades\DB;

class PersistenceMetricsService
{
    private $prefix = 'persistence:metrics:';

    public function recordAttempt(): void
    {
        Cache::increment($this->prefix . 'attempts');
    }

    public function recordSuccess(float $durationMs): void
    {
        Cache::increment($this->prefix . 'success');

        //Acumulamos el tiempo para sacar el promedio después
        $currentTotal = Cache::get($this->prefix . 'time_total', 0);
        Cache::put($this->prefix . 'time_total', $currentTotal + $durationMs);
    }

    public function recordError(string $errorType): void
    {
        Cache::increment($this->prefix . 'errors');

        //Guardamos el tipo de error en un array para poder desglosarlo luego
        $types = Cache::get($this->prefix . 'error_types', []);
        if (!in_array($errorType, $types)){
            $types[] = $errorType;
            Cache::put($this->prefix . 'error_types', $types);
        }
        Cache::increment($this->prefix . 'error_' . $errorType);
    }
    public function recordTriggerResult(bool $success): void
    {
        Cache::increment('telemetry:triggers:total');
        if (!$success){
            Cache::increment('telemetry:triggers:fails');
        }
    }

    public function getStats(): array
    {
        $attempts = (int) Cache::get($this->prefix . 'attempts', 0);
        $success = (int) Cache::get($this->prefix . 'success', 0);
        $totalTime = (float) Cache::get($this->prefix . 'time_total', 0);

        $triggerTotal = (int) Cache::get('telemetry:triggers:total', 0);
        $triggerFails = (int) Cache::get('telemetry:triggers:fails', 0);

        //Calculos
        $avgTime = $success > 0 ? ($totalTime / $success) : 0;
        $triggerRate = $triggerTotal > 0
            ? (($triggerTotal - $triggerFails) / $triggerTotal) * 100
            : 100;
        
        //Desglose de errores
        $types = Cache::get($this->prefix . 'error_types', []);
        $errors = [];
        foreach ($types as $type){
            $errors[$type] = (int) Cache::get($this->prefix . 'error_' . $type, 0);
        }

        return [
            'attempts' => $attempts,
            'success' => $success,
            'failed' => $attempts - $success,
            'avg_time_ms' => round($avgTime, 2),
            'queue_size' => TelemetryRetryQueue::count(),
            'trigger_rate' => round($triggerRate, 2),
            'errors' => $errors
        ];
    }
}