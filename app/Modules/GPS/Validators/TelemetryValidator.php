<?php

namespace App\Modules\GPS\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TelemetryValidator
{
    /**
     * Valida los datos de telemetría entrantes.
     * @throws ValidationException
     */
    public static function validate(array $data): array
    {
        $rules = [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'altitude' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'course' => ['nullable', 'integer', 'between:0,360'],
            'accuracy' => ['nullable', 'numeric'],
            'hdop' => ['nullable', 'numeric'],
            'battery' => ['nullable', 'integer', 'between:0,100'],
            'rssi' => ['nullable', 'integer'],
            'connected' => ['nullable', 'boolean'],
            'ignition' => ['nullable', 'boolean'],
            'satellite' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'date_format:Y-m-d\TH:i:s.vP'],
            'server' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'package' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
        ];
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        return $validator->validated();
    }
}