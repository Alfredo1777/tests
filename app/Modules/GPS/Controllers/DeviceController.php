<?php

namespace App\Modules\GPS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\Services\DeviceService;
use App\Modules\GPS\Requests\StoreDeviceRequest;
use App\Modules\GPS\Requests\UpdateDeviceRequest;
use App\Modules\GPS\DTOs\CreateDeviceDTO;
use App\Modules\GPS\DTOs\UpdateDeviceDTO;
use App\Modules\GPS\Resources\DeviceResource;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Http\JsonResponse;
/**
     * @OA\Get(
     * path="/api/v1/gps/devices",
     * operationId="getDevicesList",
     * tags={"Devices"},
     * summary="Listar dispositivos",
     * description="Retorna lista paginada de dispositivos con filtros (Spatie Query Builder)",
     * @OA\Parameter(name="filter[brand]", in="query", description="Filtrar por marca", @OA\Schema(type="string")),
     * @OA\Parameter(name="filter[imei]", in="query", description="Filtrar por IMEI", @OA\Schema(type="string")),
     * @OA\Response(response=200, description="Operación exitosa")
     * )
     */
class DeviceController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService
    ) {}

    /**
     * Listar dispositivos con filtros y paginación.
     * GET /api/v1/devices?filter[brand]=Teltonika&sort=-creation
     */
    public function index()
    {
        $devices = QueryBuilder::for(Device::class)
            ->allowedFilters([
                'imei', 
                'brand', 
                'model',
                'serial_number',
                AllowedFilter::exact('status_id'),
                AllowedFilter::exact('company_id'),
            ])
            ->allowedSorts(['creation', 'updated', 'brand'])
            ->with('status') // Eager loading vital
            ->paginate(15)
            ->appends(request()->query());

        return DeviceResource::collection($devices);
    }

    /**
     * @OA\Post(
     * path="/api/v1/gps/devices",
     * operationId="storeDevice",
     * tags={"Devices"},
     * summary="Registrar dispositivo",
     * description="Crea un nuevo dispositivo en el inventario",
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(ref="#/components/schemas/StoreDeviceRequest")
     * ),
     * @OA\Response(response=201, description="Dispositivo creado"),
     * @OA\Response(response=422, description="Error de validación (IMEI duplicado, etc)")
     * )
     */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        // 1. Request -> DTO
        $dto = CreateDeviceDTO::fromArray($request->validated());

        // 2. Service -> Device
        $device = $this->deviceService->create($dto);

        // 3. Response
        return response()->json([
            'message' => 'Dispositivo registrado exitosamente',
            'data' => new DeviceResource($device)
        ], 201);
    }
    /**
     * @OA\Get(
     * path="/api/v1/gps/devices/{device_uuid}",
     * operationId="getDeviceById",
     * tags={"Devices"},
     * summary="Obtener detalles del dispositivo",
     * @OA\Parameter(name="device_uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     * @OA\Response(response=200, description="Detalles del dispositivo"),
     * @OA\Response(response=404, description="Dispositivo no encontrado")
     * )
     */
    /**
     * Ver detalle de un dispositivo.
     * El modelo $device ya viene inyectado y resuelto por el Middleware 'resolve.device.uuid'
     */
    public function show(Device $device): DeviceResource
    {
        return new DeviceResource($device->load('status'));
    }

    /**
     * Actualizar dispositivo.
     */
    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $dto = UpdateDeviceDTO::fromArray($request->validated());
        
        $updatedDevice = $this->deviceService->update($device, $dto);

        return response()->json([
            'message' => 'Dispositivo actualizado',
            'data' => new DeviceResource($updatedDevice)
        ]);
    }
}