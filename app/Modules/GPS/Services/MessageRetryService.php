<?php

namespace App\Modules\GPS\Services;

use Exception;
use Illuminate\Database\QueryException;
use PDOException;
use Illuminate\Support\Facades\Log;
use App\Exceptions\app\Modules\GPS\Exceptions\InvalidJsonException;
use App\Exceptions\app\Modules\GPS\Exceptions\SchemaValidationException;
use App\Exceptions\app\Modules\GPS\Exceptions\BusinessRuleException;

class MessageRetryService
{
    /**
     * Ejecuta una operacion con politica de reintentos, backoff exponencial y timeout
     * Mantiene el estado en memoria durante la ejecucion del loop
     */
    public function processWithRetry(string $messageId, callable $operation)
    {
        $maxAttempts = 3;
        $attempt = 1;
        $startTime = time();

        while ($attempt <= $maxAttempts) {
            try {
                //Timeout total de 30 segundos
                if ((time() - $startTime) > 30){
                    throw new Exception("Timeout total: el procesamiento de este mensaje excedio los 30 segundos.");
                }
                //Intentamos ejecutar la operacion (parseo, validacion, BD)
                return $operation();
            } catch (Exception $e){
                //1. Clasificacion de errores
                if (!$this->isRetriable($e)) {
                    Log::error("[NON-RETRIABLE] Error lógico irreversible. Abortando msg [{$messageId}].");
                    throw $e;
                }
                //3. BACKOFF EXPONENCIAL
                //Intento 1 fallido -> espera 2^1 = 2 segundos
                //Intento 2 fallido -> espera 2^2 = 4 segundos
                $sleepSeconds = pow(2, $attempt);

                //Logs de intentos realizados
                Log::warning("Falla de infraestructura en msg [{$messageId}]. Intento {$attempt}/{$maxAttempts} fallido. Reintentando en {$sleepSeconds}s...", [
                    'error' => $e->getMessage()
                ]);

                //Mantenemos el estado bloqueado en memoria el tiempo exacto
                sleep($sleepSeconds);
                $attempt++;
            }
        }
    }
    /**
     * Clasificacion de errores
     * Decide si el error es temporal o permanente
     */
    private function isRetriable(Exception $e): bool
    {
        //Errores NON-RETRIABLES: El mensaje esta mal construido, reintentar no lo arreglara.
        if (
            $e instanceof InvalidJsonException ||
            $e instanceof SchemaValidationException ||
            $e instanceof BusinessRuleException ||
            $e instanceof \JsonException
        ){
            return false;
        }
        //Errores RETRIABLES: La BD no responde, PostgreSQL esta bloqueado
        if (
            $e instanceof QueryException ||
            $e instanceof PDOException ||
            str_contains(strtolower($e->getMessage()), 'timeout') ||
            str_contains(strtolower($e->getMessage()), 'connection')
        ){
            return true;
        }
        return true;
    }
}
