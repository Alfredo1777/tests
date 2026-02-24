<?php

namespace App\Modules\GPS\Repositories\Eloquent;

use App\Modules\GPS\Repositories\Contracts\PositionRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PositionRepository implements PositionRepositoryInterface
{
    /**
     * Recupera la ruta optimizada.
     */
    public function getRoute(int $deviceId, string $start, string $end): Collection
    {
        $sql = "
            SELECT 
                satellite as time,
                speed,
                ignition,
                ST_Y(location::geometry) as lat,
                ST_X(location::geometry) as lng
            FROM gps.positions
            WHERE id = ? 
              AND satellite BETWEEN ? AND ?
            ORDER BY satellite ASC
        ";

        return collect(DB::select($sql, [$deviceId, $start, $end]));
    }

    /**
     * Obtiene la última posición conocida.
     */
    public function getLastPosition(int $deviceId): ?object
    {
        $sql = "
            SELECT 
                satellite as time,
                speed,
                ignition,
                ST_Y(location::geometry) as lat,
                ST_X(location::geometry) as lng
            FROM gps.positions
            WHERE id = ?
            ORDER BY satellite DESC
            LIMIT 1
        ";

        $result = DB::selectOne($sql, [$deviceId]);
        return $result ?: null;
    }

    /**
     * Obtiene posiciones del día actual.
     */
    public function getTodayPositions(int $deviceId): Collection
    {
        $sql = "
            SELECT 
                satellite as time,
                speed,
                ignition,
                ST_Y(location::geometry) as lat,
                ST_X(location::geometry) as lng
            FROM gps.positions
            WHERE id = ? 
              AND satellite >= NOW()::DATE
            ORDER BY satellite ASC
        ";

        return collect(DB::select($sql, [$deviceId]));
    }

    /**
     * Calcula distancia total recorrida en un día.
     */
    public function getTotalDistance(int $deviceId, string $date): float
    {
        $sql = "
            SELECT COALESCE(ROUND(
                SUM(
                    ST_Distance(
                        location,
                        LAG(location) OVER (ORDER BY satellite)
                    )
                )::NUMERIC / 1000, 
            2), 0) AS total_km
            FROM gps.positions
            WHERE id = ?
              AND satellite::DATE = ?::DATE
        ";

        $result = DB::selectOne($sql, [$deviceId, $date]);
        return (float) $result->total_km;
    }

    /**
     * Búsqueda espacial (Radar).
     * ESTE ERA EL MÉTODO QUE FALTABA
     */
    public function getPositionsInRadius(float $latitude, float $longitude, int $radiusMeters, int $hoursBack = 1): Collection
    {
        $sql = "
            SELECT 
                id as device_id,
                satellite as time,
                speed,
                ST_Y(location::geometry) as lat,
                ST_X(location::geometry) as lng
            FROM gps.positions
            WHERE satellite >= NOW() - INTERVAL '1 hour' * ?
              AND ST_DWithin(
                    location,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::GEOGRAPHY,
                    ?
                )
            ORDER BY satellite DESC
        ";

        // Importante: El orden de parámetros en SQL es: hoursBack, lng, lat, radius
        return collect(DB::select($sql, [$hoursBack, $longitude, $latitude, $radiusMeters]));
    }
}

