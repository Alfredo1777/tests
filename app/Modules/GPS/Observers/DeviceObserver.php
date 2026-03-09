<?php

namespace App\Modules\GPS\Observers;

use App\Modules\GPS\Models\Device;
use Illuminate\Validation\ValidationException;

class DeviceObserver
{
    /**
     * Handle the Device "creating" event.
     *
     * @param  \App\Modules\GPS\Models\Device  $device
     * @return void
     * @throws ValidationException
     */
    public function creating(Device $device): void
    {
        // Validar que el IMEI sea único en la tabla gps.devices
        if ($device->frequency < 10) {
            throw ValidationException::withMessages([
                'frequency' => 'La frecuencia debe ser mayor o igual a 10 segundos.'
            ]);
        }

        //Asegurar defaults si no vienen en el request
        if (empty($device->metadata)){
            $device->metadata = [];
        }
    }
    public function updating(Device $device): void
    {
        //Como $timestamps = false, debemos manejar manualmente el campo 'updated' para registrar la última modificación
        $device->updated = now();
    }
}