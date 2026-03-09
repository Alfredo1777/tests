<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_retry_queue', function (Blueprint $table){
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->jsonb('payload'); //Guardamos el DTO en crudo
            $table->text('error_message');
            $table->integer('attempts')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('telemetry_retry_queue');
    }
};
