<?php

namespace App\Modules\GPS\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MessageSanitizerService
{
    /**
     * Aplica reglas de sanitización para evitar rechazos innecesarios.
     * Retorna el payload corregido.
     */
    public function sanitize(string $uuid, array $payload): array
    {
        $sanitized = $payload;
        $changes = [];

        // 1. Coordenadas fuera de rango -> null (Pérdida de fix GPS)
        if (isset($sanitized['latitude']) && ($sanitized['latitude'] < -90 || $sanitized['latitude'] > 90)) {
            $changes['latitude'] = ['before' => $sanitized['latitude'], 'after' => null];
            $sanitized['latitude'] = null;
        }
        if (isset($sanitized['longitude']) && ($sanitized['longitude'] < -180 || $sanitized['longitude'] > 180)) {
            $changes['longitude'] = ['before' => $sanitized['longitude'], 'after' => null];
            $sanitized['longitude'] = null;
        }

        // 2. Batería > 100 -> 100 | Batería < 0 -> 0
        if (isset($sanitized['battery'])) {
            if ($sanitized['battery'] > 100) {
                $changes['battery'] = ['before' => $sanitized['battery'], 'after' => 100];
                $sanitized['battery'] = 100;
            } elseif ($sanitized['battery'] < 0) {
                $changes['battery'] = ['before' => $sanitized['battery'], 'after' => 0];
                $sanitized['battery'] = 0;
            }
        }

        // 3. Velocidad (Speed) negativa -> Valor Absoluto
        if (isset($sanitized['speed']) && $sanitized['speed'] < 0) {
            $changes['speed'] = ['before' => $sanitized['speed'], 'after' => abs($sanitized['speed'])];
            $sanitized['speed'] = abs($sanitized['speed']);
        }

        // 4. Dirección (Course/Heading) > 360 -> Módulo 360
        if (isset($sanitized['course'])) {
            if ($sanitized['course'] > 360 || $sanitized['course'] < 0) {
                // Sacamos el valor absoluto y aplicamos módulo para que siempre quede entre 0 y 359
                $newCourse = abs($sanitized['course']) % 360; 
                $changes['course'] = ['before' => $sanitized['course'], 'after' => $newCourse];
                $sanitized['course'] = $newCourse;
            }
        }

        // 5. Timestamp futuro -> Usar server timestamp
        if (isset($sanitized['satellite'])) {
            try {
                $satTime = Carbon::parse($sanitized['satellite']);
                if ($satTime->isFuture()) {
                    $serverTime = Carbon::now()->toIso8601String();
                    $changes['satellite'] = ['before' => $sanitized['satellite'], 'after' => $serverTime];
                    $sanitized['satellite'] = $serverTime;
                }
            } catch (\Exception $e) {
                // Si la fecha es un texto basura (ej. "hola"), no la sanitizamos, 
                // dejamos que el Validador la rechace en la siguiente capa.
            }
        }

        // 6.Loggear cada sanitización con valores antes/después
        if (!empty($changes)) {
            Log::channel('mqtt')->warning("Datos sanitizados automaticamente", [
                'device_uuid' => $uuid,
                'timestamp' => now()->toIso8601String(),
                'changes' => $changes
            ]);
        }

        return $sanitized;
    }
}