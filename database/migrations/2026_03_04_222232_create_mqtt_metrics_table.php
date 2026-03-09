<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mqtt_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->integer('total_received')->default(0);
            $table->integer('total_valid')->default(0);
            $table->integer('total_invalid')->default(0);
            $table->float('avg_processing_time_ms')->default(0);
            $table->integer('unique_devices')->default(0);
            $table->jsonb('errors_by_type')->nullable(); // Guardar el conteo de cada error
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mqtt_metrics');
    }
};
