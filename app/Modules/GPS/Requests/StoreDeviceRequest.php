<?php

namespace App\Modules\GPS\Requests;

use Illuminate\Foundation\Http\FormRequest;
/**
 * @OA\Schema(
 * schema="StoreDeviceRequest",
 * required={"imei", "brand", "model", "status_id"},
 * @OA\Property(property="imei", type="string", example="123456789012345", description="Identificador único de 15 dígitos"),
 * @OA\Property(property="brand", type="string", example="Teltonika"),
 * @OA\Property(property="model", type="string", example="FMB920"),
 * @OA\Property(property="status_id", type="integer", example=1, description="ID del estado inicial (core.status)"),
 * @OA\Property(property="frequency", type="integer", example=30, description="Reporte en segundos (min 10)"),
 * @OA\Property(property="metadata", type="object", example={"installation_date": "2024-01-01"})
 * )
 */
class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; //La autenticacion se maneja a nivel de rutas o middleware, no aquí
    }
    public function rules(): array
    {
        return [
            //Identificacion
            'imei' => [
                'required',
                'digits:15',
                'unique:gps.devices,imei', //Validar unicidad en el esquema gps
            ],
            'brand' => 'required|string|max:50',
            'model' => 'required|string|max:50',
            'serial_number' => 'nullable|string|max:50',
            'name' => 'nullable|string|max:100',

            //Configuracion
            'frequency' => 'integer|min:10', //Validar que sea un entero y al menos 10 segundos
            'metadata' => 'nullable|array',

            //Estado (debe existir en core.status)
            'status_id' => 'required|integer|exists:core.status,id',

            //Relaciones Opcionales
            'company_id' => 'nullable|integer',
            'group_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer',
        ];
    }
    public function messages(): array
    {
        return [
            'imei.required' => 'El IMEI es obligatorio.',
            'imei.digits' => 'El IMEI debe tener exactamente 15 dígitos.',
            'imei.unique' => 'El IMEI ya existe en el sistema.',
            'brand.required' => 'La marca es obligatoria.',
            'model.required' => 'El modelo es obligatorio.',
            'frequency.min' => 'La frecuencia mínima es de 10 segundos.',
            'status_id.required' => 'El estado es obligatorio.',
            'status_id.exists' => 'El estado seleccionado no existe.',
        ];
    }
}