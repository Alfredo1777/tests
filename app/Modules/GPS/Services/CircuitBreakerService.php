<?php

namespace App\Modules\GPS\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    const STATE_CLOSED = 'CLOSED';
    const STATE_OPEN = 'OPEN';
    const STATE_HALF_OPEN = 'HALF_OPEN';

    private int $windowSize = 100;
    private float $threshold = 0.50;
    private int $cooldown = 30;

    /**
     * Verifica si el sistema tiene permiso para procesar el mensaje actual
     */

    public function checkConnection(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }
        if ($state === self::STATE_OPEN){
            $openedAt = Cache::get('circuit_breaker:opened_at', 0);

            //Si ya paso el tiempo de cooldown (30s)
            if (time() - $openedAt >= $this->cooldown) {
                Log::channel('mqtt')->info("Circuit Breaker: OPEN -> HALF_OPEN (Cooldown finalizado, probando 1 mensaje)");
                $this->transitionTo(self::STATE_HALF_OPEN);
                return true; //Dejamos pasar 1 solo mensaje
            }

            return false;
        }

        if ($state === self::STATE_HALF_OPEN){
            //Ya dejamos pasar 1 mensaje y estamos esperando su resultado
            //Rechazamos cualquier otro mensaje concurrente
            return false;
        }
        return true;
    }

    public function recordSuccess(): void
    {
        $this->pushResult(true);

        if ($this->getState() === self::STATE_HALF_OPEN){
            Log::channel('mqtt')->info("Circuit Breaker: HALF_OPEN -> CLOSED (Mensaje de prueba exitoso. Sistema reanudado)");
            $this->transitionTo(self::STATE_CLOSED);
            $this->resetWindow();
        }
    }

    public function recordFailure(): void
    {
        $this->pushResult(false);

        if ($this->getState() === self::STATE_CLOSED && $this->shouldOpen()) {
            //Lanzar alerta
            app(\App\Modules\GPS\Services\AlertService::class)->trigger(
                'circuit_breaker_open',
                "Tasa de error supera el umbral. Circuito ABIERTO.",
                ['window_size' => $this->windowSize, 'threshold' => $this->threshold],
                'Revisa logs de base de datos. Posible disco lleno o credenciales inválidas masivas.'
            );
            $this->transitionTo(self::STATE_OPEN);
        }

        if ($this->getState() === self::STATE_HALF_OPEN){
            Log::channel('mqtt')->warning("Circuit Breaker: HALF_OPEN -> OPEN (Mensaje de prueba fallido. Pausando 30s más)");
            $this->transitionTo(self::STATE_OPEN);
            return;
        }

        if ($this->getState() === self::STATE_CLOSED && $this->shouldOpen()){
            Log::channel('mqtt')->critical("ALERTA SISTÉMICA (CIRCUIT BREAKER): Tasa de error supera el 50% en los últimos {$this->windowSize} mensajes. Estado: CLOSED -> OPEN. Pausando procesamiento
            por {$this->cooldown} segundos.");
            $this->transitionTo(self::STATE_OPEN);
        }
    }

    private function getState(): string
    {
        return Cache::get('circuit_breaker:state', self::STATE_CLOSED);
    }

    private function transitionTo(string $newState): void
    {
        Cache::put('circuit_breaker:state', $newState);
        if ($newState === self::STATE_OPEN) {
            Cache::put('circuit_breaker:opened_at', time());
        }
    }

    private function pushResult(bool $isSuccess): void
    {
        $window = Cache::get('circuit_breaker:window', []);
        $window[] = $isSuccess;

        if (count($window) > $this->windowSize){
            array_shift($window);
        }

        Cache::put('circuit_breaker:window', $window);
    }

    private function shouldOpen(): bool
    {
        $window = Cache::get('circuit_breaker:window', []);

        //Solo evaluamos la tasa de error si ya acumulamos 100 mensajes
        if (count($window) < $this->windowSize){
            return false;
        }
        $failures = count(array_filter($window, fn($result) => $result === false));
        $rate = $failures / $this->windowSize;

        return $rate > $this->threshold;
    }

    private function resetWindow(): void
    {
        Cache::forget('circuit_breaker:window');
    }
}