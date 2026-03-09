<?php

namespace App\Modules\GPS\Models;

use Illuminate\Database\Eloquent\Model;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;
use MatanYadaev\EloquentSpatial\Objects\Point;

class Telemetry extends Model
{
    use HasSpatial;

    //1. Vincular al esquema "gps"
    protected $table = 'telemetry';

    //2. Configuracion de PK y Timestamps
    protected $primaryKey = 'id';
    public $incrementing = false; // Si usas un ID manual
    public $timestamps = false; // Desactivamos timestamps automáticos

    protected $fillable = [
        'id', 'battery_level', 'rssi', 'is_online', 'is_ignition_on'
        // 'location' no se llena manual, se genera en BD
    ];

    //4. Casteos nativos (para convertir tipos automáticamente)
    protected $casts = [
        'location' => Point::class, //Convierte WKB de PostGIS a objeto Point de PHP
        'creation' => 'datetime',
        'server' => 'datetime',
        'last_packet_at' => 'datetime',
        'is_online' => 'boolean',
        'is_ignition_on' => 'boolean',
    ];
}