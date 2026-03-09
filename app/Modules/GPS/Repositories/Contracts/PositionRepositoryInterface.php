<?php

namespace App\Modules\GPS\Repositories\Contracts;

use Illuminate\Support\Collection;

interface PositionRepositoryInterface
{
    /**
     * Obitene el historial de recorrido (ruta) en un rango de fechas
     * Retorna GeoJSON o array de coordenadas optimizadas
     */
    public function getRoute(int $deviceId, string $start, string $end): Collection;

    /**
     * Obtiene la ultima posicion conocida del historico (TimescaleDB)
     */
    public function getLastPosition(int $deviceId): ?object;

    //Obtiene todas las posiciones del dia actual
    public function getTodayPositions(int $deviceId): Collection;

    //Calcula la distancia total recorrida en un dia usando PostGIS
    public function getTotalDistance(int $deviceId, string $date): float;

    //Busca dispositivos/posiciones dentro de un radio geografico
    public function getPositionsInRadius(float $latitude, float $longitude, int $radiusMeters, int $hoursBack = 1): Collection;
}