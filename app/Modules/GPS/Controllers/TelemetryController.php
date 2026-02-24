<?php

namespace App\Modules\GPS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\GPS\Services\TelemetryService;
use App\Modules\GPS\Requests\StoreTelemetryRequest;
use App\Modules\GPS\DTOs\TelemetryDTO;
use Illuminate\Http\JsonResponse;

class TelemetryController extends Controller
{
    public function __construct(
        protected TelemetryService $telemetryService
    ){}
    /**
     * @OA\Post(
     * path="/api/v1/gps/devices/{device_uuid}/telemetry",
     * operationId="ingestTelemetry",
     * tags={"Telemetry"},
     * summary="Ingesta de Telemetría (High Frequency)",
     * description="Procesa datos de sensores. NO asigna location manualmente, delega a PostGIS.",
     * @OA\Parameter(name="device_uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(ref="#/components/schemas/StoreTelemetryRequest")
     * ),
     * @OA\Response(response=200, description="Telemetría procesada correctamente"),
     * @OA\Response(response=429, description="Too Many Requests (Throttle 1000/min)")
     * )
     */
    /**
     * Ingesta de Telemetria
     * POST /api/v1/devices/{uuid}/telemetry
     */
    public function store(StoreTelemetryRequest $request): JsonResponse
    {
        //1. Convertir Request validado a DTO
        $dto = TelemetryDTO::fromArray($request->validated());
        //2. Obtener el ID numerico inyectado por el middleware
        //El middleware puso 'device_id' en el request para que lo usemos aqui
        $deviceId = $request->input('device_id');
        //3. Llamar al Procedure via Servicio
        $result = $this->telemetryService->ingest($deviceId, $dto);
        //4. Retornar respuesta estandar (200 OK si el SP dijo OK)
        return response()->json([
            'message' => 'Telemetria procesada exitosamente',
            'details' => $result
        ], 200);
    }
}