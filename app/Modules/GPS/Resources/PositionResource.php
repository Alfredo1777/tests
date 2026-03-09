<?php

namespace App\Modules\GPS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Database\Eloquent\Model;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        //Detectar si viene de un objeto stdClass (Raw SQL) o Modelo Eloquent
        $isEloquent = $this->resource instanceof Model;

        //Normalizamos los datos
        $lat = $isEloquent ? $this->location?->latitude : $this->lat;
        $lng = $isEloquent ? $this->location?->longitude : $this->lng;
        
        //Eloquent devuelve objeto Carbon, SQL devuelve string. Normalizamos a ISO8601
        $timeRaw = $isEloquent ? $this->gps_time : $this->time;
        $timeIso = is_string($timeRaw) ? $timeRaw : $timeRaw?->toIso8601String();

        return [
            'type' => 'Feature', // Formato estándar GeoJSON
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float)$lng, (float)$lat] // GeoJSON siempre es [lng, lat]
            ],
            'properties' => [
                'speed' => (float) $this->speed,
                'course' => isset($this->course) ? (int) $this->course : null,
                'ignition' => (bool) $this->ignition,
                'time' => $timeIso,
                
                // Campo opcional para reporte de distancia
                'total_km' => isset($this->total_km) ? (float) $this->total_km : null,
            ]
        ];
    }
}