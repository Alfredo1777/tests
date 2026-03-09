<?php

namespace App\Modules\GPS\Requests;

use Illuminate\Foundation\Http\FormRequest;
/**
 * @OA\Schema(
 * schema="StoreTelemetryRequest",
 * required={"satellite", "latitude", "longitude"},
 * @OA\Property(property="latitude", type="number", format="float", example=19.2433),
 * @OA\Property(property="longitude", type="number", format="float", example=-103.7244),
 * @OA\Property(property="speed", type="number", format="float", example=60.5),
 * @OA\Property(property="course", type="integer", example=180),
 * @OA\Property(property="battery", type="integer", example=98),
 * @OA\Property(property="ignition", type="boolean", example=true),
 * @OA\Property(property="satellite", type="string", format="date-time", example="2024-03-20T10:00:00Z"),
 * )
 */
class StoreTelemetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            //Coordenadas: Validacion Cruzada
            //Si viene latitude, longitude es required. Si no viene latitude, longitude no se valida.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'altitude' => 'nullable|numeric',
            'speed' => 'nullable|numeric|min:0',
            'course' => 'nullable|integer|min:0|max:360',

            //Sensores y Estado
            'accuracy' => 'nullable|numeric|min:0',
            'hdop' => 'nullable|numeric|min:0',
            'battery' => 'nullable|integer|between:0,100',
            'rssi' => 'nullable|integer|between:-120,100',
            'connected' => 'boolean',
            'ignition' => 'boolean',

            //Timestamps: ISO 8601 o null (el SP gps.telemetry_process se encargará de validar esto)
            'satellite' => 'required|date_format:Y-m-d\TH:i:s\Z', //Ejemplo: 2024-06-01T12:00:00Z
            'server' => 'nullable|date',
            'package' => 'nullable|date',
        ];
    }
    public function messages(): array
    {
        return [
            'latitude.between' => 'La latitud debe estar entre -90 y 90 grados',
            'longitude.between' => 'La longitud debe estar entre -180 y 180 grados',
            'latitude.required_with' => 'La latitud es requerida cuando se proporciona longitud',
            'longitude.required_with' => 'La longitud es requerida cuando se proporciona latitud',
            'battery.between' => 'El nivel de batería debe estar entre 0 y 100%',
            'rssi.between' => 'El nivel de señal (RSSI) debe estar entre -120 y 100 dBm',
            'satellite.required' => 'La fecha y hora de la señal satelital es obligatoria',
            'satellite.date_format' => 'La fecha y hora de la señal satelital debe estar en formato ISO 8601 (Ejemplo: 2024-06-01T12:00:00Z)',
        ];
    }
    //Preparar datos para validadcion
    //Util si el hardware envia '1' o '0' en vez de booleanos, o si queremos convertir campos antes de la validacion
    protected function prepareForValidation()
    {
        if ($this->has('ignition')){
            $this->merge(['ignition' => filter_var($this->input('ignition'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }
}