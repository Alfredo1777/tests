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
                // Timeout total de 10 segundos para toda la operación
                if ((time() - $startTime) > 10) {
                    throw new Exception("Timeout total: el procesamiento de este mensaje excedió los 10 segundos.");
                }
                
                // Intentamos ejecutar la operación (parseo, validacion, BD)
                return $operation();
                
            } catch (Exception $e) {
                // 1. Clasificación estricta de errores
                if (!$this->isRetriable($e)) {
                    Log::channel('mqtt')->error("[NON-RETRIABLE] Error irreversible. Abortando msg [{$messageId}]: " . $e->getMessage());
                    throw $e; // Se lanza para que MqttSubscribe lo mande a la DLQ
                }

                // 2. Control de intentos agotados
                if ($attempt >= $maxAttempts) {
                    Log::channel('mqtt')->critical("[TIMEOUT/EXHAUSTED] Se agotaron los {$maxAttempts} intentos para msg [{$messageId}]. Enviando a Queue/DLQ.");
                    throw $e; 
                }

                // 3. BACKOFF EXPONENCIAL AJUSTADO
                // Falla Intento 1 -> espera 1 segundo
                // Falla Intento 2 -> espera 2 segundos
                $sleepSeconds = $attempt; 

                // Loggear cada intento con contexto
                Log::channel('mqtt')->warning("Falla transitoria en msg [{$messageId}]. Intento {$attempt}/{$maxAttempts} fallido. Reintentando en {$sleepSeconds}s...", [
                    'error_type' => (new \ReflectionClass($e))->getShortName(),
                    'error_message' => $e->getMessage()
                ]);

                // Mantenemos el estado bloqueado en memoria el tiempo exacto
                sleep($sleepSeconds);
                $attempt++;
            }
        }
    }

    /**
     * Clasificacion de errores
     * Decide si el error es temporal (retriable) o permanente (non-retriable)
     */
    private function isRetriable(Exception $e): bool
    {
        // Errores NON-RETRIABLES de la aplicación (Malformados, Reglas de Negocio)
        if (
            $e instanceof InvalidJsonException ||
            $e instanceof SchemaValidationException ||
            $e instanceof BusinessRuleException ||
            $e instanceof \JsonException
        ){
            return false;
        }

        // Análisis profundo de Errores de Base de Datos (PostgreSQL)
        if ($e instanceof QueryException || $e instanceof PDOException) {
            $sqlState = (string) $e->getCode();
            $msg = strtolower($e->getMessage());

            // LISTA NEGRA: NON-RETRIABLES (Errores de integridad o sintaxis)
            $nonRetriableStates = [
                '23503', // foreign_key_violation (El dispositivo no existe)
                '23505', // unique_violation (Duplicado)
                '23502', // not_null_violation (Faltan datos obligatorios)
                '23514', // check_violation (Datos fuera de rango en BD)
                '42601', // syntax_error
                '42P01', // undefined_table (Tabla no existe)
            ];

            if (in_array($sqlState, $nonRetriableStates)) {
                return false;
            }

            // LISTA BLANCA: RETRIABLES (Errores transitorios de infraestructura)
            if (
                $sqlState === '40P01' || // deadlock_detected
                $sqlState === '08006' || // connection_failure
                str_contains($msg, 'timeout') ||
                str_contains($msg, 'connection') ||
                str_contains($msg, 'too many clients') ||
                str_contains($msg, 'deadlock')
            ) {
                return true;
            }
            
            // Si es un error de BD desconocido, lo tratamos como retriable por seguridad
            return true;
        }

        // Cualquier otro error desconocido fuera de BD no se reintenta
        return false;
    }
}
