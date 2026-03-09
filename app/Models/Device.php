<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'imei',
        'serial_number',
        'brand',
        'model',
        'name',
        'metadata',
        'frequency',
        'firmware_version',
        'phone_number',
        'iccid',
        'status_id'
    ];

    protected $casts = [
        'metadata' => 'array',
        'frequency' => 'integer',
        'created_at' => 'datetime',
    ];

    //Relaciones (Para conectar con otras tablas)
    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function telemetry()
    {
        return $this->hasOne(Telemetry::class, 'device_id');
    }

}


