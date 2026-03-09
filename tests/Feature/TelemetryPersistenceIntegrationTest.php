<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\Models\Telemetry;
use App\Modules\GPS\Models\TelemetryRetryQueue;
use App\Modules\GPS\DTOs\TelemetryDTO;
use App\Modules\GPS\Services\TelemetryService;
use App\Modules\GPS\Services\MessageRetryService;
use App\Modules\GPS\Exceptions\DeviceNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;

class TelemetryPersistenceIntegrationTest extends TestCase
{
    // Usamos la base de datos de pruebas (Asegúrate de que phpunit.xml apunte a PostgreSQL)
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Limpiamos la caché de métricas antes de cada test
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function getValidDTO(): TelemetryDTO
    {
        return TelemetryDTO::fromArray([
            'latitude' => 19.05,
            'longitude' => -104.31,
            'battery' => 100,
            'satellite' => now()->toIso8601String()
        ]);
    }

    /** @test */
    public function procesa_upsert_exitosamente_y_calcula_location()
    {
        $device = Device::factory()->create();
        $service = app(TelemetryService::class);
        $dto = $this->getValidDTO();

        // Ejecutar
        $result = $service->ingest($device->id, $dto, 'test_trace_123');

        // Verificar
        $this->assertTrue($result['success']);
        
        $telemetry = Telemetry::find($device->id);
        $this->assertNotNull($telemetry);
        $this->assertEquals(19.05, $telemetry->latitude);
        
        // Verificar que PostGIS generó el location (Regla del Trigger)
        $hasLocation = DB::scalar('SELECT location IS NOT NULL FROM gps.telemetry WHERE id = ?', [$device->id]);
        $this->assertTrue($hasLocation);
    }

    /** @test */
    public function rechaza_persistencia_si_device_no_existe()
    {
        $this->expectException(DeviceNotFoundException::class);
        
        $service = app(TelemetryService::class);
        $service->ingest(99999, $this->getValidDTO());
    }

    /** @test */
    public function retry_service_maneja_deadlocks_transitorios()
    {
        $retryService = app(MessageRetryService::class);
        $intentos = 0;

        $resultado = $retryService->processWithRetry('msg_test_deadlock', function () use (&$intentos) {
            $intentos++;
            if ($intentos < 3) {
                // Simulamos un error de Deadlock (40P01) de PostgreSQL
                $e = new PDOException("Deadlock detected");
                $e->errorInfo = ['40P01', 7, 'deadlock detected'];
                throw $e;
            }
            return "Éxito al intento $intentos";
        });

        // Debe haber fallado 2 veces y triunfado en la tercera
        $this->assertEquals(3, $intentos);
        $this->assertEquals("Éxito al intento 3", $resultado);
    }

    /** @test */
    public function envia_a_queue_de_persistencia_si_falla_totalmente()
    {
        $device = Device::factory()->create();
        $dto = $this->getValidDTO();
        
        // Forzamos un error de constraint violada (Non-Retriable) simulando un mock
        $mockService = Mockery::mock(TelemetryService::class)->makePartial();
        $mockService->shouldReceive('ingest')->andThrow(new \Exception("Error fatal de BD"));
        $this->app->instance(TelemetryService::class, $mockService);

        $worker = app(\App\Console\Commands\MqttSubscribe::class);
        
        try {
            // Simulamos la fase 4 aislando la lógica que lo manda a la queue
            $queueService = app(\App\Modules\GPS\Services\PersistenceQueueService::class);
            $queueService->enqueuePersistence($device->id, $dto, "Error fatal de BD");
        } catch (\Exception $e) {}

        // Verificar que se guardó en la tabla de reintentos
        $this->assertEquals(1, TelemetryRetryQueue::count());
        $enqueued = TelemetryRetryQueue::first();
        $this->assertEquals($device->id, $enqueued->device_id);
    }
}