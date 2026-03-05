<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\Models\Device;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class MessageValidatorService
{
    /**
     * Evalúa el payload en múltiples capas y devuelve un array con todos los problemas.
     * * @param string $uuid El identificador del dispositivo
     * @param string $rawPayload El JSON crudo recibido por MQTT
     * @return array Lista de errores encontrados. Si está vacío, el mensaje es 100% válido.
     */
    public function findErrors(string $uuid, string $rawPayload): array
    {
        $errors = [];

        // 1. Validar la Base de Datos (Regla de Negocio Crítica)
        $device = Device::where('uuid', $uuid)->first();
        if (!$device) {
            $errors[] = [
                'type' => 'business_rule',
                'severity' => 'critical',
                'message' => "El dispositivo con UUID [{$uuid}] no está registrado."
            ];
        }

        // 2. Validar estructura JSON
        $data = json_decode($rawPayload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = [
                'type' => 'syntax_error',
                'severity' => 'critical',
                'message' => "JSON mal formado: " . json_last_error_msg()
            ];
            // Si el JSON está roto, no podemos validar el resto de los campos
            return $errors; 
        }

        // 3. Validar Esquema y Tipos de Datos (Campos obligatorios)
        $rules = [
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'satellite' => 'required|date_format:Y-m-d\TH:i:sP', // Formato ISO8601
            'speed'     => 'nullable|numeric',
            'battery'   => 'nullable|integer',
            'course'    => 'nullable|integer',
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $errorMsg) {
                $errors[] = [
                    'type' => 'schema_error',
                    'severity' => 'high',
                    'message' => $errorMsg
                ];
            }
        }

        // Solo validamos rangos lógicos si los campos numéricos requeridos están presentes
        if (isset($data['latitude']) && isset($data['longitude'])) {
            // 4. Validar rangos de coordenadas
            if ($data['latitude'] < -90.0 || $data['latitude'] > 90.0) {
                $errors[] = ['type' => 'business_rule', 'severity' => 'high', 'message' => "Latitud ilógica: {$data['latitude']}"];
            }
            if ($data['longitude'] < -180.0 || $data['longitude'] > 180.0) {
                $errors[] = ['type' => 'business_rule', 'severity' => 'high', 'message' => "Longitud ilógica: {$data['longitude']}"];
            }
        }

        // 5. Validar lógica de tiempo (Timestamp 'satellite')
        if (isset($data['satellite'])) {
            try {
                $satelliteTime = Carbon::parse($data['satellite']);
                $now = Carbon::now();
                
                if ($satelliteTime->isFuture()) {
                    $errors[] = ['type' => 'business_rule', 'severity' => 'medium', 'message' => "El timestamp del GPS está en el futuro."];
                } elseif ($satelliteTime->diffInDays($now) > 30) {
                    $errors[] = ['type' => 'business_rule', 'severity' => 'medium', 'message' => "Datos demasiado antiguos (más de 30 días)."];
                }
            } catch (\Exception $e) {
                // Capturado por el validator de schema, se ignora aquí
            }
        }

        // 6. Validar rangos de sensores opcionales
        if (isset($data['speed']) && ($data['speed'] < 0 || $data['speed'] > 300)) {
            $errors[] = ['type' => 'business_rule', 'severity' => 'low', 'message' => "Velocidad fuera de rango operativo (0-300 km/h)."];
        }
        if (isset($data['battery']) && ($data['battery'] < 0 || $data['battery'] > 100)) {
            $errors[] = ['type' => 'business_rule', 'severity' => 'low', 'message' => "Batería fuera de rango (0-100%)."];
        }

        return $errors;
    }
}