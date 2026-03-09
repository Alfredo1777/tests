<?php

namespace App\Modules\GPS\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Modules\GPS\Models\Device;

class ResolveDeviceUuid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        //1. Identificar el Parametro UUID en la ruta
        //Buscamos 'device_uuid' en los parámetros de la ruta
        $uuid = $request->route('device_uuid') ?? $request->route('uuid'); // Soporte para ambos nombres

        //Si la ruta no tiene este parametro, dejamos pasar la peticion
        if (!$uuid) {
            return $next($request);
        }
        //2. Buscar en Base de Datos
        //Usar el modelo Device para buscar por UUID
        $device = Device::where('uuid', $uuid)->first();
        //3. Si no se encuentra, responder con 404
        if (!$device) {
            abort(404, 'Dispositivo no encontrado');
        }
        //4. Reemplazar el parametro con el modelo Eloquent
        //Esto permite que en el controlador recibamos un Device en lugar de un UUID
        if ($request->route('device_uuid')){
            $request->route()->setParameter('device_uuid', $device);
        } elseif ($request->route('uuid')) {
            $request->route()->setParameter('uuid', $device);
        }
        //Inyectar tambien el ID numerico en el request
        $request->merge(['device_id' => $device->id]);
        return $next($request);
    }
}