<?php

namespace App\Modules\GPS\Services;

use App\Modules\GPS\DTOs\CreateDeviceDTO;
use App\Modules\GPS\DTOs\UpdateDeviceDTO;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\Repositories\Contracts\DeviceRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Exception;

class DeviceService
{
    public function __construct(
        protected DeviceRepositoryInterface $deviceRepository
    ){}

    public function create(CreateDeviceDTO $dto): Device
    {
        return DB::transaction(function () use ($dto) {
            try {
                return $this->deviceRepository->create($dto);
            } catch (QueryException $e) {
                $this->handlePostgresException($e);
                throw $e; // Si no es manejada, relanzar
            }
        });
    }
    public function update(Device $device, UpdateDeviceDTO $dto): Device
    {
        return DB::transaction(function () use ($device, $dto){
            try {
                return $this->deviceRepository->update($device,$dto);
            } catch (QueryException $e){
                $this->handlePostgresExceptions($e);
                throw $e;
            }
        });
    }
    /**
     * Traduce codigos de error de PostgreSQL a excepciones de validación de Laravel
     */
    protected function handlePostgresExceptions(QueryException $e): void
    {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();

        //Codigo 23505 es unique_violation, lo que significa que se intento crear o actualizar un dispositivo con un imei o uuid que ya existe
        if ($errorCode === '23505') {
            if (str_contains($errorMessage, 'devices_imei_key') || str_contains($errorMessage, '_uq_devices_imei')) {
                throw ValidationException::withMessages([
                    'imei' => 'El IMEI ingresado ya existe en la base de datos.'
                ]);
            }
        }
        //Codigo 23514: Check constraint violation, lo que significa que se intento crear o actualizar un dispositivo con una frecuencia menor a 10 segundos
        if ($errorCode === '23514') {
            if (str_contains($errorMessage, '_ck_devices_frequency')) {
                throw ValidationException::withMessages([
                    'frequency' => 'La frecuencia debe ser mayor o igual a 10 segundos.'
                ]);
            }
        }
    }
}