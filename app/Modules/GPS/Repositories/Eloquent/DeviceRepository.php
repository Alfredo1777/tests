<?php

namespace App\Modules\GPS\Repositories\Eloquent;

use App\Modules\GPS\Models\Device;
use App\Modules\GPS\DTOs\CreateDeviceDTO;
use App\Modules\GPS\DTOs\UpdateDeviceDTO;
use App\Modules\GPS\Repositories\Contracts\DeviceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class DeviceRepository implements DeviceRepositoryInterface
{
    public function findAll(): Collection
    {
        //Eager loading del status para evitar N+1 queries
        return Device::with('status')->get();
    }
    public function findById(int $id): ?Device
    {
        return Device::find($id);
    }
    public function findByUuid(string $uuid): ?Device
    {
        return Device::where('uuid', $uuid)->first();
    }
    public function findByImei(string $imei): ?Device
    {
        return Device::where('imei', $imei)->first();
    }
    public function create(CreateDeviceDTO $data): Device
    {
        //El observer se encargara de validaciones extra
        return Device::create($data->toArray());
    }
    public function update(Device $device, UpdateDeviceDTO $data): Device
    {
        //El observer se encargara de validaciones extra
        $device->update($data->toArray());
        return $device->fresh(['status']);
    }
    public function delete(Device $device): bool
    {
        return $device->delete();
    }
}