<?php

namespace App\Modules\GPS\DTOs;

readonly class TelemetryDTO
{
    public function __construct(
        // Datos Geoespaciales
        public ?float $latitude,
        public ?float $longitude,
        public ?float $altitude = null,
        public ?float $speed = 0.0,
        public ?int $course = 0,
        
        // Calidad y Sensores
        public ?float $accuracy = null,
        public ?float $hdop = null,
        public ?int $battery = null,
        public ?int $rssi = null,
        
        // Estados Booleanos
        public ?bool $connected = true,
        public ?bool $ignition = false,
        
        // Timestamps (Strings ISO8601 para pasar directo a SQL/JSON)
        public string $satellite,  // OBLIGATORIO por trigger telemetry_position
        public ?string $server = null,
        public ?string $package = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            altitude: isset($data['altitude']) ? (float) $data['altitude'] : null,
            speed: isset($data['speed']) ? (float) $data['speed'] : 0.0,
            course: isset($data['course']) ? (int) $data['course'] : 0,
            accuracy: isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            hdop: isset($data['hdop']) ? (float) $data['hdop'] : null,
            battery: isset($data['battery']) ? (int) $data['battery'] : null,
            rssi: isset($data['rssi']) ? (int) $data['rssi'] : null,
            connected: $data['connected'] ?? true,
            ignition: $data['ignition'] ?? false,
            // Si no viene fecha satelital, usamos NOW (aunque el SP validará esto)
            satellite: $data['satellite'] ?? now()->toIso8601String(),
            server: $data['server'] ?? now()->toIso8601String(),
            package: $data['package'] ?? now()->toIso8601String(),
        );
    }
    public function toArray(): array
    {
        //Filtramos nulos para no enviar basura, pero mantenemos defaults como connected = true o ignition = false
        return array_filter(get_object_vars($this), fn($value) => !is_null($value));
    }
    //Preparar la estructura exacta que pide el Stored Procedure gps.telemetry_process
    //El SP espera: {"telemetry": { ... }}
    public function toProcedurePayload(): string
    {
        return json_encode(['telemetry' => $this->toArray()]);
    }
}