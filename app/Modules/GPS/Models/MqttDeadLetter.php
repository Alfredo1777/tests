<?php

namespace App\Modules\GPS\Models;

use Illuminate\Database\Eloquent\Model;

class MqttDeadLetter extends Model
{
    protected $table = 'mqtt_dead_letters';
    public $timestamps = false;

    protected $fillable = [
        'topic', 'raw_payload', 'error_type', 'error_message', 'attempts', 'failed_at'
    ];

    protected $casts = [
        'failed_at' => 'datetime',
        'raw_payload' => 'array', //Casteo automatico a array
    ];
}