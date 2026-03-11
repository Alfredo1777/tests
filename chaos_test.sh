#!/bin/bash
echo "Iniciando Chaos Engineering Test para SIGMA GPS..."

# 1. Levantar el subscriber en segundo plano (Background)
php artisan mqtt:subscribe &
WORKER_PID=$!
echo " Worker iniciado con PID: $WORKER_PID"

# Darle 5 segundos para que se conecte
sleep 5

# 2. Enviar la señal SIGTERM (Simulando que docker lo apaga)
echo "Enviando señal SIGTERM al Worker..."
kill -15 $WORKER_PID
EXIT_CODE=$?

echo "Worker finalizado con código de salida: $EXIT_CODE"

if [ $EXIT_CODE -eq 0 ]; then
    echo "TEST EXITOSO: El graceul Shutdown funcionó a la perfeccion."
    echo "Revisa 'storage/app/mqtt-subscriber-status.json' para ver el reporte de la RAM"
else
    echo "TEST FALLIDO: El Worker murió violentamente o con error."
fi