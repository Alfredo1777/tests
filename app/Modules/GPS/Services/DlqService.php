<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\Models\MqttDeadLetter;
use Illuminate\Support\Facades\Log;
use Exception;

class DlqService
{
    const MAX_DLQ_SIZE = 1000;

    public function push(string $topic, string $payload, Exception $e, int $attempts): void
    {
        try{
            //Implementar limite de tamaño
            if (MqttDeadLetter::count() >= self::MAX_DLQ_SIZE) {
                MqttDeadLetter::orderBy('failed_at', 'asc')->first()?->delete();
            }
            //Obtener el nombre corto de la clas del error
            $errorType = (new \ReflectionClass($e))->getShortName();

            MqttDeadLetter::create([
                'topic' => $topic,
                'raw_payload' => json_decode($payload, true) ?? ['raw_string' => $payload],
                'error_type' => $errorType,
                'error_message' => $e->getMessage(),
                'attempts' => $attempts,
                'failed_at' => now(),
            ]);

            Log::info("Mensaje enviado a DQL. Topic: {$topic}");
        } catch (Exception $ex) {
            Log::critical("Falla crítica al guardar en DQL: " . $ex->getMessage());
        }
    }
}