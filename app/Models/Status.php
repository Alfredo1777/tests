<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    use HasFactory;

    // ESTO ES CRÍTICO: Fuerza a usar la tabla en singular
    protected $table = 'status';

    // Campos que permitimos llenar masivamente
    protected $fillable = [
        'table_name', 
        'key', 
        'name', 
        'emoji', 
        'text_color', 
        'background_color'
    ];
}
