<?php

namespace App\Modules\GPS\DTOs;

readonly class UpdateDeviceDTO
{
    public function __construct(
        // Campos Actualizables (todos opcionales para permitir actualizaciones parciales)
        public ?string $imei = null,
        public ?string $serial_number = null,
        public ?string $brand = null,
        public ?string $model = null,
        public ?string $name = null,
        public ?array $metadata = null, // Si es null, no se actualiza. Si es [], se vacía.
        public ?int $frequency = null,
        public ?string $firmware_version = null,
        public ?string $phone_number = null,
        public ?string $iccid = null,
        public ?string $apn = null,
        public ?string $network_operator = null,
        public ?int $status_id = null,
        public ?int $company_id = null,
        public ?int $group_id = null,
        public ?int $vehicle_id = null,
    ) {}

    public static function fromArray(array $data): self
    {
        //Usamos null coalescing para permitir actualizaciones parciales, y casteamos enteros donde corresponde
        return new self(
            imei: $data['imei'] ?? null,
            serial_number: $data['serial_number'] ?? null,
            brand: $data['brand'] ?? null,
            model: $data['model'] ?? null,
            name: $data['name'] ?? null,
            metadata: $data['metadata'] ?? null,
            frequency: isset($data['frequency']) ? (int) $data['frequency'] : null,
            firmware_version: $data['firmware_version'] ?? null,
            phone_number: $data['phone_number'] ?? null,
            iccid: $data['iccid'] ?? null,
            apn: $data['apn'] ?? null,
            network_operator: $data['network_operator'] ?? null,
            status_id: isset($data['status_id']) ? (int) $data['status_id'] : null,
            company_id: isset($data['company_id']) ? (int) $data['company_id'] : null,
            group_id: isset($data['group_id']) ? (int) $data['group_id'] : null,
            vehicle_id: isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
        );
    }
    public function toArray(): array
    {
        //Filtramos nulos para no enviar basura, pero permitimos campos opcionales como metadata o frequency si se quieren actualizar
        return array_filter(get_object_vars($this), fn($value) => !is_null($value));
    }
}