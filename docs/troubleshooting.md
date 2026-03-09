# Guía de Troubleshooting (Resolución de Problemas MQTT)

### 1. El Subscriber se desconecta constantemente
* **Causa Posible:** El `clientId` está duplicado (otro worker está usando el mismo ID).
* **Solución:** Asegurar que el `.env` use `MQTT_CLIENT_ID` único, o verificar que el código concatene `uniqid()` al iniciar la conexión.

### 2. Mensajes válidos no aparecen en la Base de Datos
* **Causa Posible:** El UUID del dispositivo no ha sido dado de alta en la tabla `devices`.
* **Solución:** Ejecutar `php artisan mqtt:dlq:list`. Si el error dice "UUID no registrado", dar de alta el camión en el panel web y luego ejecutar `php artisan mqtt:dlq:retry {id}` para recuperar el mensaje.

### 3. La Dead Letter Queue crece muy rápido
* **Causa Posible:** Un cambio de firmware en los GPS modificó los nombres de las variables (ej. de `speed` a `velocidad`).
* **Solución:** Actualizar el `MessageParserService` para soportar la nueva convención de nombres y normalizar el JSON entrante.

### 4. Lentitud extrema (Alto tiempo de procesamiento)
* **Causa Posible:** La base de datos PostgreSQL está bloqueada o saturada.
* **Solución:** Revisar el comando `php artisan mqtt:metrics`. Si el tiempo promedio excede los 500ms, revisar los índices de la tabla `telemetry` en PostgreSQL. El `MessageRetryService` aguantará hasta 30 segundos usando Backoff Exponencial antes de descartar el mensaje.