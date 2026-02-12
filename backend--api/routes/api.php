<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;

/*
 Endpoint para validar el estado del servicio y la conexión a BD.
*/

Route::get('/system/healthcheck', function (): JsonResponse {
    try {
        // Intentamos una consulta simple para verificar la conexión
        // Si la BD está caída, esto lanzará una excepción.
        DB::connection()->getPdo();
        
        return response()->json([
            'status' => 'ok',
            'service' => 'api-backend',
            'database' => 'connected',
            'timestamp' => now()->toIso8601String(),
        ], 200);

    } catch (\Exception $e) {
        // Si falla, reportamos error 500 y el mensaje (útil para debugging local)
        // En producción podrías querer ocultar el $e->getMessage() por seguridad.
        return response()->json([
            'status' => 'error',
            'service' => 'api-backend',
            'database' => 'disconnected',
            'error' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
});