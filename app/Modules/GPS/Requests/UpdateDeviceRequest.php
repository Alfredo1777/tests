<?php

namespace App\Modules\GPS\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        //Recuperamos el modelo inyectado por el middleware
        //Puede venir como 'device_uuid' o 'uuid' dependiendo de la ruta, así que soportamos ambos
        $device = $this->route('device_uuid') ?? $this->route('uuid');
        $device = $device?->id; //Obtenemos el ID numérico para las reglas de unicidad

        return [
            'imei' => [
                'sometimes',
                'digits:15',
                //Validacion unica ignorando el ID actual para permitir updates sin cambiar el IMEI
                Rule::unique('gps.devices', 'imei')->ignore($deviceId),
            ],
            'brand' => 'sometimes|string|max:50',
            'model' => 'sometimes|string|max:50',
            'serial_number' => 'nullable|string|max:50',
            'name' => 'nullable|string|max:100',
            'frequency' => 'sometimes|integer|min:10',
            'metadata' => 'nullable|array',
            'status_id' => 'sometimes|integer|exists:core.status,id',
            'company_id' => 'nullable|integer',
        ];
    }
    public function messages(): array
    {
        return [
            'imei.digits' => 'El IMEI debe tener exactamente 15 dígitos.',
            'imei.unique' => 'El IMEI ya existe en el sistema.',
            'frequency.min' => 'La frecuencia mínima es de 10 segundos.',
            'status_id.exists' => 'El estado seleccionado no existe.',
        ];
    }
}