<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\DTOs\TelemetryDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class TelemetryBatchService
{
    protected $batch = [];
    protected $lastFlushTime;
    protected $telemetryService;

    public function __construct(TelemetryService $telemetryService)
    {
        $this->telemetryService = $telemetryService;
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Acumula el mensaje en memoria.
     */
    public function push(int $deviceId, TelemetryDTO $dto, string $traceId): void
    {
        $this->batch[] = [
            'device_id' => $deviceId,
            'dto' => $dto,
            'trace_id' => $traceId
        ];

        // Regla: 10 mensajes (No verificamos el tiempo aquí porque el EventLoop lo hará)
        if (count($this->batch) >= 10) {
            $this->flush();
        }
    }

    /**
     * Envía todo el paquete a PostgreSQL en una sola transacción.
     */
    public function flush(): void
    {
        if (empty($this->batch)) {
            return;
        }

        $currentBatch = $this->batch;
        $this->batch = []; // Limpiamos la memoria inmediatamente
        $this->lastFlushTime = microtime(true);

        $successCount = 0;

        foreach ($currentBatch as $item) {
            try {
                // El SP ya es una transacción atómica segura en PostgreSQL
                $this->telemetryService->ingest($item['device_id'], $item['dto'], $item['trace_id']);
                $successCount++;
            } catch (Exception $e) {
                Log::channel('mqtt')->error(" Error aislando mensaje del Batch: " . $e->getMessage());
                // No lanzamos el error hacia arriba para que el resto del lote siga procesándose
            }
        }
        
        if ($successCount > 0) {
            Log::channel('mqtt')->info(" Batch completado: {$successCount} procesados.");
        }
    }

    public function getLastFlushTime(): float
    {
        return $this->lastFlushTime;
    }
}