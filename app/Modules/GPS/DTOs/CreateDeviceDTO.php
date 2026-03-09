<?php

namespace App\Modules\GPS\DTOs;

readonly class CreateDeviceDTO
{
    public function __construct(
        // Campos Obligatorios (NOT NULL en BD)
        public string $imei,
        public string $brand,
        public string $model,
        public int $status_id,

        //Campos Opcionales (NULLABLE en BD)
        public ?string $serial_number = null,
        public ?string $name = null,
        public array $metadata = [], //JSONB default '{}'
        public ?int $frequency = 30, //default 30 segundos
        public ?string $firmware_version = null,

        //Datos de Conectividad (opcional, pero útil para diagnóstico)
        public ?string $phone_number = null,
        public ?string $iccid = null,
        public ?string $apn = null,
        public ?string $network_operator = null,

        //Relaciones (IDs de otras tablas, opcionales al crear)
        public ?int $company_id = null,
        public ?int $group_id = null,
        public ?int $vehicle_id = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            imei: $data['imei'],
            brand: $data['brand'],
            model: $data['model'],
            status_id: (int) $data['status_id'],
            serial_number: $data['serial_number'] ?? null,
            name: $data['name'] ?? null,
            metadata: $data['metadata'] ?? [],
            frequency: (int) ($data['frequency'] ?? 30),
            firmware_version: $data['firmware_version'] ?? null,
            phone_number: $data['phone_number'] ?? null,
            iccid: $data['iccid'] ?? null,
            apn: $data['apn'] ?? null,
            network_operator: $data['network_operator'] ?? null,
            company_id: isset($data['company_id']) ? (int) $data['company_id'] : null,
            group_id: isset($data['group_id']) ? (int) $data['group_id'] : null,
            vehicle_id: isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
        );
    }
    public function toArray(): array
    {
        //Filtramos nulos para no enviar basura, pero mantenemos defaults como metadata = [] o frequency = 30
        return array_filter(get_object_vars($this), fn($value) => !is_null($value));
    }
}