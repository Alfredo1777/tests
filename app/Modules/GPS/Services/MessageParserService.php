<?php

namespace App\Modules\GPS\Services;

use App\Exceptions\app\Modules\GPS\Exceptions\InvalidJsonException;
use Carbon\Carbon;

Class MessageParserService
{
    /**
     * Orquestador principal de parseo y normalizacion
     * Retorna el UUID extraido y el payload completamente limpio
     */

    public function parse(string $topic, string $rawPayload): array
    {
        //1. Extraer el UUID del topic MQTT
        $uuid = $this->extractUuidFromTopic($topic);

        //2. Decodificar JSON con manejo de errores
        $rawData = $this->decodeJson($rawPayload);

        //3. Detectar version  de firmware
        $version = $this->detectFirmwareVersion($rawData);

        //4. Normalizar nombres de campos y convertir unidades segun la version 
        $normalizedData = $this->normalizeByVersion($rawData, $version);

        //5. Aplicar valores por defecto y metadatos del servidor
        $enrichedData = $this->applyDefaultsAndMetadata($normalizedData, $version);

        //6. Limpiar campos no reconocidos (Solo lo que espera TelemetryDTO)
        $cleanPayload = $this->filterAllowedFields($enrichedData);

        return [
            'uuid' => $uuid,
            'payload' => $cleanPayload
        ];
    }
    private function extractUuidFromTopic(string $topic): string
    {
        $parts = explode('/', $topic);
        if (count($parts) < 4 || $parts[2] === '+'){
            throw new \Exception("Estructura de topic invalida: {$topic}");
        }
        return $parts[2];
    }

    private function decodeJson(string $payload): array
    {
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidJsonException("JSON mal formado: " . json_last_error_msg());
        }
        return $data;
    }

    private function detectFirmwareVersion(array $data): string
    {
        //Detectar version explicita enviada por el GPS
        if (isset($data['fw_ver'])) {
            return $data['fw_ver'];
        }

        //Inferencia por convencion de campos
        if (isset($data['lat']) && isset($data['lng'])) {
            return 'v1.0';
        }
        //Firmware moderno
        return 'v2.0';
    }

    private function normalizeByVersion(array $data, string $version): array
    {
        $normalized = $data;

        //reglas especificas para gps con firmware v1.0
        if ($version === 'v1.0') {
            //Mapeo de nombres cortos a nombres estandarizados
            $normalized['latitude'] = $data['lat'] ?? null;
            $normalized['longitude'] = $data['lng'] ?? null;

            //Conversion de unidades
            if (isset($data['spd_mph'])) {
                $normalized['speed'] = round($data['spd_mph'] * 1.60934, 2);
            }
            //Conversion de Timestamp Unix a ISO08601
            if (isset($data['ts'])) {
                $normalized['satellite'] = Carbon::createFromTimestamp($data['ts'])->toIso8601String();
            }
        }

        return $normalized;
    }

    private function applyDefaultsAndMetadata(array $data, string $version): array
    {
        //Aplicar valores por defecto para campos opcionales
        $data['connected'] = $data['connected'] ?? true;
        $data['ignition'] = $data['ignition'] ?? false;
        $data['speed'] = $data['speed'] ?? 0.0;
        $data['course'] = $data['course'] ?? 0;

        //Agregar metadatos del servidor
        $data['server'] = Carbon::now()->toIso8601String();

        //Si no viene fecha del satelite, usamos la del servidor como fallback
        $data['satellite'] = $data['satellite'] ?? $data['server'];

        return $data;
    }

    private function filterAllowedFields(array $data): array
    {
        //Lista estricta de campos permitidos por la base de datos
        $allowedFields = [
            'latitude', 'longitude', 'altitude', 'speed', 'course',
            'accuracy', 'hdop', 'battery', 'rssi', 'connected',
            'ignition', 'satellite', 'server', 'package'
        ];

        return array_intersect_key($data, array_flip($allowedFields));
    }

}