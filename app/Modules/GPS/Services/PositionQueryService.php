<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\Repositories\Contracts\PositionRepositoryInterface;
use Illuminate\Support\Collection;

class PositionQueryService
{
    public function __construct(
        protected PositionRepositoryInterface $positionRepository
    ){}
    /**
     * Obtiene la ruta historica
     * Util para dibujar polineas en mapas
     */
    public function getHistoricalRoute(int $deviceId, string $start, string $end): Collection
    {
        return $this->positionRepository->getRoute($deviceId, $start, $end);
    }
    /**
     * Obtener el rastro del dia actual
     */
    public function getDailyBreadcrumb(int $deviceId): Collection
    {
        return $this->positionRepository->getTodayPositions($deviceId);
    }
    //Obtiene la ultima posicion conocida, util para mostrar el marcador en el mapa
    public function getLivePosition(int $deviceId): ?object
    {
        return $this->positionRepository->getLastPosition($deviceId);
    }
    //Reporte de distancia recorrida
    public function getDailyOdometer(int $deviceId, string $date): float
    {
        return $this->positionRepository->getTotalDistance($deviceId, $date);
    }
    //Radar de proximidad
    public function findNearby(float $lat, float $lng, int $radiusMeters): Collection
    {
        return $this->positionRepository->getPositionsInRadius($lat, $lng, $radiusMeters);
    }
}