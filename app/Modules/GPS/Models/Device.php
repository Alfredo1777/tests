<?php

namespace App\Modules\GPS\Models;

use Illuminate\Database\Eloquent\Model;
use illuminate\Database\Eloquent\Relations\BelongsTo;
use illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Core\Models\Status;

class Device extends Model
{
    //1. Vincular al esquema "core"
    protected $table = 'core.devices';

    //2. Configuracion de PK y Timestamps
    protected $primaryKey = 'id';
    public $timestamps = false; // Desactivamos timestamps automáticos
    //3. Asignacion masiva (para crear/actualizar registros)
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
        'apn',
        'network_operator',
        'company_id',
        'group_id',
        'vehicle_id',
        'status_id'
    ];
    //4. Casteos nativos (para convertir tipos automáticamente)
    protected $casts = [
        'metadata' => 'array',
        'frequency' => 'integer',
        'creation' => 'datetime',
        'updated' => 'datetime',
    ];

    //Relaciones (Para conectar con otras tablas)
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function telemetry(): HasOne
    {
        return $this->hasOne(Telemetry::class, 'id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'device_id');
    }

}