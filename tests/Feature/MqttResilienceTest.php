<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Modules\GPS\Services\CircuitBreakerService;
use App\Console\Commands\MqttSubscribe;
use PhpMqtt\Client\MqttClient;
use Mockery;
use ReflectionClass;

class MqttResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Limpiamos la caché antes de cada test para no arrastrar métricas
        Cache::flush();
    }

    /** @test */
    public function circuit_breaker_se_abre_con_alta_tasa_de_error()
    {
        $cb = app(CircuitBreakerService::class);
        
        // Simulamos 100 mensajes: 55 fallan, 45 tienen éxito (55% de error)
        for ($i = 0; $i < 55; $i++) $cb->recordFailure();
        for ($i = 0; $i < 45; $i++) $cb->recordSuccess();

        // El circuito debe abrirse y rechazar nuevos mensajes
        $this->assertFalse($cb->checkConnection(), "El circuito debería estar ABIERTO y rechazar mensajes.");
        $this->assertEquals('OPEN', Cache::get('circuit_breaker:state'));
    }

    /** @test */
    public function circuit_breaker_permite_half_open_despues_de_cooldown()
    {
        $cb = app(CircuitBreakerService::class);
        for ($i = 0; $i < 100; $i++) $cb->recordFailure(); // Forzamos estado OPEN

        // Simulamos que viajamos en el tiempo 31 segundos (El cooldown es de 30s)
        Cache::put('circuit_breaker:opened_at', time() - 31);

        // El primer mensaje debe pasar (HALF_OPEN)
        $this->assertTrue($cb->checkConnection(), "El circuito debería permitir 1 mensaje (HALF_OPEN).");
        
        // El segundo mensaje inmediato debe ser rechazado
        $this->assertFalse($cb->checkConnection(), "El circuito debería bloquear mensajes concurrentes en HALF_OPEN.");
    }

    /** @test */
    public function errores_aislados_json_no_detienen_el_demonio()
    {
        // Espiamos los logs para asegurar que registre el error
        Log::spy();

        // Instanciamos el comando con sus dependencias
        $command = app(MqttSubscribe::class);
        
        // Usamos Reflection para acceder al método privado 'procesarMensajePipeline'
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('procesarMensajePipeline');
        $method->setAccessible(true);

        // Ejecutamos el pipeline con un JSON totalmente destruido
        $badJson = '{"uuid": "123", "baterry": 100, faltan_comillas}';
        $method->invokeArgs($command, ['gps/test/telemetry', $badJson]);

        // Verificamos que el error se atrapó, el contador subió y no hubo Exception fatal
        $this->assertEquals(1, Cache::get('mqtt:errors:error'));
        Log::shouldHaveReceived('error')->once(); // Validar que se loggeó la caída del mensaje
    }

    /** @test */
    public function graceful_shutdown_ejecuta_la_secuencia_completa()
    {
        $command = app(MqttSubscribe::class);
        
        // Mockeamos el MqttClient para no conectarnos a la red de verdad
        $mockMqtt = Mockery::mock(MqttClient::class);
        $mockMqtt->shouldReceive('isConnected')->andReturn(true);
        $mockMqtt->shouldReceive('unsubscribe')->once();
        $mockMqtt->shouldReceive('publish')->once(); // El LWT offline
        $mockMqtt->shouldReceive('interrupt')->once();
        $mockMqtt->shouldReceive('disconnect')->once();

        // Usamos Reflection para acceder a 'initiateGracefulShutdown'
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('initiateGracefulShutdown');
        $method->setAccessible(true);

        // Interceptamos la llamada a exit() para que PHPUnit no muera
        // Nota: En la vida real exit() detiene el script, aquí solo validaremos hasta antes del exit
        // simulando un catch de una excepción de salida si la hubiéramos creado, 
        // pero validaremos el estado de Redis que es el "Paso 4" del shutdown.
        
        try {
            $method->invokeArgs($command, [$mockMqtt, 'TEST_SHUTDOWN']);
        } catch (\Exception $e) {
            // Ignorar el exit() si estuviera envuelto en excepción
        }

        // Verificar que el estado final se inyectó en Redis (Paso 4)
        $this->assertTrue(Cache::has('mqtt:subscriber:last_shutdown'));
        $estadoFinal = Cache::get('mqtt:subscriber:last_shutdown');
        $this->assertEquals('TEST_SHUTDOWN', $estadoFinal['reason']);
    }
}