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
        Schema::create('mqtt_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->string('topic');
            $table->jsonb('raw_payload')->nullable(); //Para guardar el json crudo
            $table->string('error_type');
            $table->text('error_message');
            $table->integer('attempts')->default(1);
            $table->timestamp('failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mqtt_dead_letters');
    }
};
