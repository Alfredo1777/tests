<?php

namespace App\Modules\GPS\Repositories\Contracts;

use App\Modules\GPS\DTOs\CreateDeviceDTO;
use App\Modules\GPS\DTOs\UpdateDeviceDTO;
use App\Modules\GPS\Models\Device;
use Illuminate\Database\Eloquent\Collection;

interface DeviceRepositoryInterface
{
    public function findAll(): Collection;
    public function findById(int $id): ?Device;
    public function findByUuid(string $uuid): ?Device;
    public function findByImei(string $imei): ?Device;
    public function create(CreateDeviceDTO $data): Device;
    public function update(Device $device, UpdateDeviceDTO $data): Device;
    public function delete(Device $device): bool;
}