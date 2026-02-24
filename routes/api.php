<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\DeviceController;
use App\Modules\GPS\Controllers\PositionController;
use App\Modules\GPS\Controllers\TelemetryController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefix aplicado automáticamente: /api
| Estructura definida: /v1/gps/...
|
*/
/*
 Endpoint para validar el estado del servicio y la conexión a BD.
*/
Route::prefix('v1')->group(function () {
    
    // MÓDULO GPS
    Route::prefix('gps')->group(function () {

        // =================================================================
        // 1. RUTAS GLOBALES DE DISPOSITIVOS (Sin UUID en URL)
        // =================================================================
        
        // Listar todos los dispositivos (Spatie Query Builder)
        Route::get('/devices', [DeviceController::class, 'index']);
        
        // Registrar nuevo dispositivo
        Route::post('/devices', [DeviceController::class, 'store']);


        // =================================================================
        // 2. RUTAS DE DISPOSITIVO ESPECÍFICO
        // Middleware 'resolve.device.uuid' busca el dispositivo en BD
        // e inyecta el modelo y el 'device_id' en el request.
        // =================================================================
        
        Route::middleware(['resolve.device.uuid'])->group(function () {
            
            // --- GESTIÓN DE DISPOSITIVO ---
            
            // Ver detalle (El controller recibe el modelo inyectado)
            Route::get('/devices/{device_uuid}', [DeviceController::class, 'show']);
            
            // Actualizar dispositivo
            Route::put('/devices/{device_uuid}', [DeviceController::class, 'update']);


            // --- TELEMETRÍA (Ingesta) ---
            
            // Ingesta de datos (High Frequency)
            // Throttle: 1000 peticiones por minuto para evitar DDOS
            Route::post('/devices/{device_uuid}/telemetry', [TelemetryController::class, 'store'])
                ->middleware('throttle:1000,1');


            // --- POSICIONES (Consultas Históricas) ---
            
            Route::prefix('/devices/{device_uuid}/positions')->group(function () {
                
                // Listado paginado
                Route::get('/', [PositionController::class, 'index']);
                
                // Última posición conocida (Snapshot live)
                Route::get('/last', [PositionController::class, 'last']);
                
                // Ruta histórica entre fechas (Polyline)
                Route::get('/route', [PositionController::class, 'route']);
                
                // Rastro del día actual (Breadcrumb)
                Route::get('/today', [PositionController::class, 'today']);
                
                // Reporte de distancia (Odómetro virtual)
                Route::get('/distance', [PositionController::class, 'distance']);
                
                // Búsqueda radial (Radar)
                // Nota: Aunque busca globalmente, lo mantenemos aquí por consistencia de URL
                Route::get('/radius', [PositionController::class, 'radius']);
            });

        });
    });

});
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