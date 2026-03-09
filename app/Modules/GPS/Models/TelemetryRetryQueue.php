<?php

namespace App\Modules\GPS\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryRetryQueue extends Model
{
    protected $table = 'telemetry_retry_queue';

    protected $fillable = [
        'device_id',
        'payload',
        'error_message',
        'attempts'
    ];
    protected $casts = [
        'payload' => 'array', //Laravel lo convierte de/a JSON automaticamente
        'attempts' => 'integer'
    ];
}
