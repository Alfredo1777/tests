<?php

namespace App\Modules\GPS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemetryResource extends JsonResource
{
    public function toArray(Request $request): array 
    {
        return [
            //Ubicacion
            'coordinates' => [
                'lat' => (float) $this->location->latitude,
                'lng' => (float) $this->location->longitude,
                'alt' => (float) $this->altitude,
                'accuracy' => (float) $this->accuracy,
                'hdop' => (float) $this->hdop,
            ],
            //Movimiento
            'movement' => [
                'speed' => (float) $this->speed,
                'course' => (int) $this->course,
                'ignition' => (bool) $this->ignition,
            ],
            //Sensores y Salud
            'sensors' => [
                'battery' => (int) $this->battery_level,
                'rssi' => (int) $this->rssi,
                'connected' => (bool) $this->is_online,
            ],
             //Tiempos
             'timestamps' => [
                'satellite' => $this->satellite?->toIso8601String(),
                'server' => $this->server?->toIso8601String(),
                'ago' => $this->satellite?->diffForHumans(),
             ],
        ];
    }
}