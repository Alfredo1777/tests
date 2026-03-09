<?php

namespace App\Modules\GPS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\GPS\Services\PositionQueryService;
use App\Modules\GPS\Resources\PositionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    public function __construct(
        protected PositionQueryService $queryService
    ) {}

    // Helper para obtener el ID del request (inyectado por middleware)
    private function getDeviceId(Request $request): int
    {
        return (int) $request->input('device_id');
    }
    /**
     * Listado general de posiciones con paginación y filtros básicos.
     * GET /gps/devices/{uuid}/positions
     */
    public function index(Request $request)
    {
        // Validamos filtros básicos de fecha si vienen
        $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
        ]);

        $deviceId = $this->getDeviceId($request);
        $start = $request->input('start');
        $end = $request->input('end');

        // Si no hay fechas, traemos las últimas 50 por defecto para no saturar
        if (!$start || !$end) {
             // Usamos el repositorio (asumiendo que agregamos un método simple paginate o usamos raw)
             // Para simplificar y no modificar el repositorio ahora, usamos una query directa rápida
             // aprovechando que es TimescaleDB (ordenar por tiempo es rápido)
             $positions = \Illuminate\Support\Facades\DB::table('gps.positions')
                ->where('id', $deviceId)
                ->orderBy('satellite', 'desc')
                ->paginate(50);
        } else {
             // Si hay fechas, usamos la lógica de rango
             $positions = \Illuminate\Support\Facades\DB::table('gps.positions')
                ->where('id', $deviceId)
                ->whereBetween('satellite', [$start, $end])
                ->orderBy('satellite', 'desc')
                ->paginate(50);
        }

        return PositionResource::collection($positions);
    }
    /**
     * @OA\Get(
     * path="/api/v1/gps/devices/{device_uuid}/positions/last",
     * operationId="getLastPosition",
     * tags={"Positions"},
     * summary="Última posición conocida",
     * @OA\Parameter(name="device_uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     * @OA\Response(response=200, description="Snapshot de la última ubicación")
     * )
     */
    /**
     * Última posición conocida (Snapshot).
     */
    public function last(Request $request)
    {
        $position = $this->queryService->getLivePosition($this->getDeviceId($request));

        if (!$position) {
            return response()->json(['message' => 'Sin posición conocida'], 404);
        }

        return new PositionResource($position);
    }
    /**
     * @OA\Get(
     * path="/api/v1/gps/devices/{device_uuid}/positions/route",
     * operationId="getRoute",
     * tags={"Positions"},
     * summary="Obtener ruta histórica",
     * description="Consulta optimizada a TimescaleDB para obtener el recorrido entre dos fechas.",
     * @OA\Parameter(name="device_uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     * @OA\Parameter(name="start", in="query", required=true, @OA\Schema(type="string", format="date-time")),
     * @OA\Parameter(name="end", in="query", required=true, @OA\Schema(type="string", format="date-time")),
     * @OA\Response(response=200, description="Colección de puntos GeoJSON")
     * )
     */
    /**
     * Ruta histórica entre dos fechas.
     * GET /.../route?start=2024-01-01T00:00:00Z&end=...
     */
    public function route(Request $request)
    {
        $request->validate([
            'start' => 'required|date|before:end',
            'end' => 'required|date'
        ]);

        $positions = $this->queryService->getHistoricalRoute(
            $this->getDeviceId($request),
            $request->input('start'),
            $request->input('end')
        );

        return PositionResource::collection($positions);
    }

    /**
     * Breadcrumb del día de hoy.
     */
    public function today(Request $request)
    {
        $positions = $this->queryService->getDailyBreadcrumb($this->getDeviceId($request));
        return PositionResource::collection($positions);
    }

    /**
     * Odómetro virtual (Distancia recorrida).
     */
    public function distance(Request $request)
    {
        $request->validate(['date' => 'required|date']);

        $km = $this->queryService->getDailyOdometer(
            $this->getDeviceId($request),
            $request->input('date')
        );

        return response()->json(['date' => $request->input('date'), 'total_km' => $km]);
    }

    /**
     * Radar Geoespacial (Dispositivos cercanos).
     * GET /api/v1/positions/radius?lat=...&lng=...&radius=5000
     * NOTA: Este endpoint no requiere UUID de dispositivo, es global.
     */
    public function radius(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius' => 'required|integer|min:100|max:50000', // Metros
        ]);

        $positions = $this->queryService->findNearby(
            $request->input('lat'),
            $request->input('lng'),
            $request->input('radius')
        );

        return PositionResource::collection($positions);
    }
}