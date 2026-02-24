<?php

namespace App\Modules\GPS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identificación Pública (El frontend usa el UUID como ID principal)
            'id' => $this->uuid, 
            'imei' => $this->imei,
            'name' => $this->name,
            
            // Agrupación Lógica: HARDWARE
            'hardware' => [
                'brand' => $this->brand,
                'model' => $this->model,
                'serial_number' => $this->serial_number,
                'firmware' => $this->firmware_version,
            ],

            // Agrupación Lógica: CONECTIVIDAD
            'sim' => [
                'phone' => $this->phone_number,
                'iccid' => $this->iccid,
                'apn' => $this->apn,
                'operator' => $this->network_operator,
            ],

            // Agrupación Lógica: CONFIGURACIÓN
            'config' => [
                'frequency' => $this->frequency,
                'metadata' => $this->metadata, // El cast del modelo ya lo convierte a array
            ],

            // Estado (Relación con core.status)
            'status' => [
                'code' => $this->status?->code, // Ej: DEV-ACT
                'label' => $this->status?->name,
                'icon' => $this->status?->emoji,
                'color' => $this->status?->color_font,
                'bg_color' => $this->status?->color_background,
            ],

            // Telemetría (Solo se incluye si se usó ->with('telemetry') en el controlador)
            'telemetry' => new TelemetryResource($this->whenLoaded('telemetry')),

            //Auditoria
            'created_at' => $this->creation?->toIso8601String(),
            'updated_at' => $this->updated?->toIso8601String(),
        ];
    }
}