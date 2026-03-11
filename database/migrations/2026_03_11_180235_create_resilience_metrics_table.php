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
        Schema::create('resilience_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_name')->index();
            $table->float('value')->default(0);
            $table->string('type');
            $table->json('labels')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resilience_metrics');
    }
};
