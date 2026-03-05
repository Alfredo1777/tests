<?php

namespace App\Modules\GPS\Models;

use Illuminate\Database\Eloquent\Model;

class MqttMetric extends Model
{
    protected $table = 'mqtt_metrics';
    protected $fillable = [
        'date', 'total_received', 'total_valid', 'total_invalid', 
        'avg_processing_time_ms', 'unique_devices', 'errors_by_type'
    ];
    protected $casts = ['errors_by_type' => 'array'];
}