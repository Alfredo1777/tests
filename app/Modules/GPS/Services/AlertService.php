<?php

namespace App\Modules\GPS\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class AlertService
{
    /**
     * Dispara una alerta si no se ha enviado una del mismo tipo en la ultima hora
     */

    public function trigger(string $type, string $reason, array $metrics, string $troubleshooting): void
    {
        $cacheKey = "mqtt:alert:{type}";

        // --- RATE LIMITING (Maximo 1 por hora) ---
        if (Cache::has($cacheKey)) {
            return; //Evita el spam de alertas
        }
        $context = [
            'type' => $type,
            'reason' => $reason,
            'metrics' => $metrics,
            'troubleshooting' => $troubleshooting,
            'recent_logs' => $this->getLastLogs()
        ];

        //Log en archivo especifico (Nivel Emergency)
        Log::channel('mqtt')->emergency("ALERTA DE OPERACIONES: {$reason}", $context);

        // OPCIÓN 1: Enviar por Email (Descomentar y configurar cuando tengas SMTP)
        /*
        Mail::raw(" ALERTA CRÍTICA SIGMA GPS\n\nRazón: {$reason}\n\nTroubleshooting: {$troubleshooting}\n\nMétricas: " . json_encode($metrics, JSON_PRETTY_PRINT), function ($msg) {
            $msg->to(env('ALERTS_EMAIL', 'ops@tudominio.com'))->subject("Alerta SIGMA: {$reason}");
        });
        */

        // OPCIÓN 2: Enviar por WhatsApp API (Descomentar y configurar token)
        /*
        Http::withToken(env('WHATSAPP_TOKEN'))->post('https://graph.facebook.com/v17.0/YOUR_PHONE_ID/messages', [
            'messaging_product' => 'whatsapp',
            'to' => env('ALERTS_WHATSAPP_NUMBER'),
            'type' => 'text',
            'text' => ['body' => " *ALERTA SIGMA GPS*\n{$reason}\nRevisar consola de servidor."]
        ]);
        */

        //Bloqueamos este tipo de alerta por 1 hora (3600)
        Cache::put($cacheKey, true, 3600);
    }

    /**
     * Obtiene las ultimas 10 lineas de log para dar contexto.
     */
    private function getLastLogs(): string
    {
        $logFile = storage_path('logs/laravel.log');

        if(file_exists($logFile)) {
            $tail = @shell_exec("tail -n 10 " . escapeshellarg($logFile));
            return $tail ?: "No se pudieron obtener los logs (Comando tail no soportado en este SO).";
        }
        return "Archivo de log no encontrado.";
    }
}
