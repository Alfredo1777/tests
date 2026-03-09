<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\Models\TelemetryRetryQueue;
use App\Modules\GPS\DTOs\TelemetryDTO;
use Illuminate\Support\Facades\Log;

class persistenceQueueService
{
    //Guarda un mensaje fallido en la tabla de reintentos
    public function enqueuePersistence(int $deviceId, TelemetryDTO $data, string $error): void
    {
        TelemetryRetryQueue::create([
            'device_id' => $deviceId,
            'payload' => $data->toArray(),
            'error_message' => substr($error, 0, 1000),
            'attempts' => 0
        ]);

        Log::channel('mqtt')->warning("Mensaje para el dispositivo [{$deviceId}] enviado a la Queue de Persistencia.");
    }
}