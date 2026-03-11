## 📘 RUNBOOK DE OPERACIONES: SIGMA GPS Subscriber
Este documento contiene los procedimientos operativos estándar (SOP), resolución de problemas y guías de despliegue para el demonio (worker) del Subscriber MQTT.

# 1. Operaciones Básicas (Runbook)
El Subscriber debe ejecutarse bajo un gestor de procesos en producción, preferiblemente Supervisor o Docker.

# Iniciar el Subscriber:

    -Manual: php artisan mqtt:subscribe --max-runtime=3600
    -Supervisor: sudo supervisorctl start sigma-mqtt-worker:*

# Detener el Subscriber (Gracefully):

    -Manual: Presionar Ctrl+C (El proceso atrapará la señal SIGINT, vaciará las colas y cerrará conexiones).
    -Supervisor: sudo supervisorctl stop sigma-mqtt-worker:* (Envía SIGTERM, esperando hasta 60s antes de forzar el apagado).

# Verificar Salud (Health Check):

    -Ejecutar: php artisan mqtt:health-check
    -Salida esperada: Exit code 0 (Healthy) y métricas de RAM/Errores en verde.

# Reiniciar el Proceso (Hard Restart):

    -Supervisor: sudo supervisorctl restart sigma-mqtt-worker:*

**

# 2. Guía de Troubleshooting (Resolución de Problemas)
Sigue esta matriz si el sistema presenta un comportamiento anómalo:

Síntoma 1: El Subscriber no inicia
    -Verificar A: Archivo .env. ¿Las credenciales de MQTT_HOST, MQTT_PORT, MQTT_USERNAME son correctas?
    -Verificar B: ¿Está corriendo Mosquitto? docker ps | grep mosquitto o systemctl status mosquitto.
    -Verificar C: Conexión a Base de Datos. El Worker requiere PostgreSQL para el mapeo de UUIDs al arrancar.

Síntoma 2: El Subscriber se detiene o reinicia constantemente
    -Revisar Logs: Busca la palabra "Graceful Shutdown" en storage/logs/laravel.log.
    -Revisar Memoria: Ejecuta php artisan mqtt:resilience-metrics. Si los "Restarts por Memoria" están subiendo, hay un Memory Leak. Revisa si la cola de Batching se está      atorando y no libera la RAM.
    -Revisar Supervisor: Verifica si Supervisor lo está matando por superar el tiempo límite.

Síntoma 3: El Subscriber corre, pero no procesa mensajes (No hay datos en BD)
    -Verificar Conexión Broker: El servidor MQTT podría estar rechazando el topic.
    -Verificar Base de Datos: ¿Está caída la BD principal? Si la BD no responde, el Worker encolará internamente pero no podrá guardar.
    -Verificar Dispositivos: Revisa el monitor de tráfico (mosquitto_sub -t "gps/devices/#" -d) para confirmar que los camiones realmente están transmitiendo.

Síntoma 4: Alta Tasa de Error (> 20%)
    -Revisar DLQ: Los mensajes defectuosos van a la tabla de Dead Letter Queue o logs. Revisa qué está fallando (¿JSON mal formado? ¿Faltan coordenadas?).
    -Validaciones: Asegúrate de que los UUIDs de los dispositivos que transmiten estén previamente registrados en la tabla devices.

# 3. Matriz de Alertas y Respuesta (Incident Response)

Si el `AlertService` dispara notificaciones (Email/WhatsApp/Log), aplica estas acciones:

| Alerta Recibida                     | Significado                                                               | Acción Inmediata (Troubleshooting)                                                                     |
|:------------------------------------|:--------------------------------------------------------------------------|:-------------------------------------------------------------------------------------------------------|
| `worker_dead` / `broker_disconnect` | El proceso lleva >5 mins sin emitir latidos o sin conexión a Mosquitto.   | 1. Entrar por SSH. 2. `supervisorctl status`. 3. Revisar conectividad de red entre backend y broker.   |
| `circuit_breaker_open`              | Fallo sistémico. >50% de mensajes rechazados en la última ventana.        | 1. Revisar si PostgreSQL se quedó sin espacio en disco. 2. Revisar si cambiaron los esquemas del JSON. |
| `memory_leak`                       | El Hard Limit (512MB) se alcanzó 3 veces en < 1 hora.                     | 1. Revisar tamaño de los payloads MQTT. 2. Reducir el tamaño del Batching de BD temporalmente.         |
| `zero_messages`                     | 10 minutos sin tráfico procesado.                                         | 1. Verificar si los SIM Cards de la flota tienen saldo/datos. 2. Verificar APN de la operadora.        |

# 4. Checklist de Deployment (Despliegues a Producción)
Para garantizar Zero-Downtime (cero pérdida de mensajes) durante actualizaciones de código:

Fase 1: Pre-Deployment Checks
    -[ ] Revisar que las pruebas unitarias y de resiliencia pasen: php artisan test.
    -[ ] Verificar que el Circuit Breaker esté CLOSED. No desplegar durante una falla sistémica.
    -[ ] Verificar si hay cambios en el archivo .env o nuevas variables requeridas.

Fase 2: Deployment Steps
    -Hacer pull del nuevo código: git pull origin main
    -Instalar dependencias si las hay: composer install --no-dev --optimize-autoloader
    -Correr migraciones de base de datos: php artisan migrate --force
    -REINICIO ELEGANTE: Enviar señal SIGTERM a los workers actuales para que terminen de procesar su lote y mueran pacíficamente:
        -php artisan queue:restart (Si usas colas nativas)
        -sudo supervisorctl restart sigma-mqtt-worker:*

Fase 3: Post-Deployment Verification
    -[ ] Monitorear los logs en vivo por 2 minutos: tail -f storage/logs/laravel.log
    -[ ] Ejecutar php artisan mqtt:health-check para confirmar que el nuevo proceso levantó y emite latidos.
    -[ ] Verificar en Grafana/Métricas que la tasa de procesamiento de mensajes se restableció.

Fase 4: Rollback Procedure (Si algo sale mal)
    -Revertir al commit anterior: git checkout HEAD~1 (o el hash específico).
    -Deshacer migraciones recientes si es estrictamente necesario (peligroso en producción, usar con cautela).
    -Reiniciar Supervisor: sudo supervisorctl restart sigma-mqtt-worker:*
    -Notificar al equipo de desarrollo con los logs del fallo.