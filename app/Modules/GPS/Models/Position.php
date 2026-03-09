<?php

namespace App\Modules\GPS\Models;

use Illuminate\Database\Eloquent\Model;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;
use MatanYadaev\EloquentSpatial\Objects\Point;

class Position extends Model
{
    use HasSpatial;

    //1. Vincular al esquema "gps"
    protected $table = 'positions';

    //2. Configuracion de PK y Timestamps
    protected $primaryKey = 'id';
    public $incrementing = false; // Si usas un ID manual
    public $timestamps = false; // Desactivamos timestamps automáticos

    //3. Casteos nativos (para convertir tipos automáticamente)
    protected $casts = [
        'location' => Point::class, //Convierte WKB de PostGIS a objeto Point de PHP
        'gps_time' => 'datetime',
        'creation' => 'datetime',
        'speed' => 'float',
        'ignition' => 'boolean',
    ];

    //Scope para optimizar consultas en TimescaleDB (Siempre filtrar por tiempo)
    public function scopeTimeRange($query, $start, $end)
    {
        return $query->whereBetween('gps_time', [$start, $end]);
    }
}