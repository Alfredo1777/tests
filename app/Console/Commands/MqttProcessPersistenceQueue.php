<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\GPS\Models\TelemetryRetryQueue;
use App\Modules\GPS\Services\TelemetryService;
use App\Modules\GPS\Services\DlqService;
use App\Modules\GPS\DTOs\TelemetryDTO;
use Illuminate\Support\Facades\Log;
use Exception;

class MqttProcessPersistenceQueue extends Command
{
    // El nombre del comando exacto que pidió el requerimiento
    protected $signature = 'mqtt:process-persistence-queue';
    protected $description = 'Procesa los mensajes atascados en la cola de persistencia (Max 5 intentos)';

    protected $telemetryService;
    protected $dlqService;

    public function __construct(TelemetryService $telemetryService, DlqService $dlqService)
    {
        parent::__construct();
        $this->telemetryService = $telemetryService;
        $this->dlqService = $dlqService;
    }

    public function handle()
    {
        $this->info("Buscando mensajes en la queue de persistencia...");

        // Batch size: 100 mensajes, ordenados por los más viejos primero
        $mensajes = TelemetryRetryQueue::where('attempts', '<', 5)
            ->orderBy('created_at', 'asc')
            ->limit(100)
            ->get();

        if ($mensajes->isEmpty()) {
            $this->info("La queue está vacía. Todo en orden. ✅");
            return 0;
        }

        $this->info("Procesando {$mensajes->count()} mensajes en batch...");

        foreach ($mensajes as $item) {
            try {
                // 1. Reconstruimos el DTO desde el JSON guardado
                $dto = TelemetryDTO::fromArray($item->payload);

                // 2. Intentamos el ingest usando tu Procedimiento Almacenado
                $this->telemetryService->ingest($item->device_id, $dto);

                // 3. Si tuvo éxito, lo borramos de la cola
                $item->delete();
                $this->line("✅ Mensaje [ID: {$item->id}] procesado y recuperado exitosamente.");

            } catch (Exception $e) {
                // 4. Si falla, incrementamos el contador
                $item->increment('attempts');
                $item->update(['error_message' => substr($e->getMessage(), 0, 1000)]);

                $this->warn("⚠️ Falló reintento {$item->attempts}/5 para mensaje [ID: {$item->id}].");

                // 5. Límite alcanzado: Mover a Dead Letter Queue
                if ($item->attempts >= 5) {
                    $this->error("🚨 Límite de 5 intentos alcanzado. Moviendo a DLQ...");
                    
                    // Recreamos el topic y el payload crudo para la DLQ
                    $topicFallback = "gps/devices/queue-recovery/telemetry";
                    $payloadJson = json_encode($item->payload);
                    
                    $this->dlqService->push($topicFallback, $payloadJson, $e, 5);
                    
                    // Lo eliminamos de esta queue porque ya está en la morgue
                    $item->delete(); 
                }
            }
        }

        $this->info("Procesamiento de Queue finalizado.");
        return 0;
    }
}
