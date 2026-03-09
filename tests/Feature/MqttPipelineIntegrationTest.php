<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\Models\MqttDeadLetter;
use Illuminate\Support\Facades\Artisan;

class MqttPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_message_goes_to_dlq_without_crashing()
    {
        //1. Preparamos el entorno
        $device = Device::factory()->create(['uuid' => 'truck-01']);
        //Un mensaje con Json roto (falta llave de cierre)
        $brokenJson = '{"latitude": 19.5, "longitude": -104.2';

        //2. Simulamos la inyeccion directa al metodo del comando (via reflexion o llamando al servicio)
        //Para este test, llamaremos directamente al parser que lanzara el error atrapado por el DLQ

        $dlqService = app(\App\Modules\GPS\Services\DlqService::class);

        try {
            $parser = app (\App\Modules\GPS\Services\MessageParserService::class);
            $parser->parse('gps/devices/truck-01/telemetry', $brokenJson, $e, 1);
        } catch (\Exception $e) {
            $dlqService->push('gps/devices/truck-01/telemetry', $brokenJson, $e, 1);
        }

        //3. Verificamos que no se cayo y que el mensaje esta en la tabla de muertos
        $this->assertDatabaseHas('mqtt_dead_letters', [
            'topic' => 'gps/devices/truck-01/telemetry',
            'error_type' => 'InvalidJsonException'
        ]);
    }
}
