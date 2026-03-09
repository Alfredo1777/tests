# Protocolo MQTT - Proyecto GPS

## 1. Estructura de Topics (Jerarquía)
Todos los topics comienzan con el prefijo `gps/`.

### A. Comunicación de Dispositivos (Upstream)
El dispositivo ENVÍA datos al servidor.

- **Telemetría (Ubicación):**
  - Topic: `gps/devices/{device_uuid}/telemetry`
  - Payload: JSON `{lat, lng, speed, batt...}`
  - QoS: 1 (Para asegurar entrega)
  - Retain: FALSE (No guardar datos viejos)

- **Estado (Conectividad):**
  - Topic: `gps/devices/{device_uuid}/status`
  - Payload: `"online"` o `"offline"`
  - QoS: 1
  - Retain: TRUE (Para saber el estado aunque el servidor se reinicie)

### B. Comandos al Dispositivo (Downstream)
El servidor ENVÍA órdenes al dispositivo.

- **Reiniciar:**
  - Topic: `gps/commands/{device_uuid}/reboot`
  - Payload: `1`
  - QoS: 1
  - Retain: FALSE (¡Muy importante! Si es TRUE, el GPS se reiniciará en bucle infinito)

- **Configuración:**
  - Topic: `gps/commands/{device_uuid}/config`
  - Payload: JSON `{frequency: 60, ...}`

## 2. Comodines (Wildcards) para el Servidor
Laravel se suscribirá usando estos patrones:

- Escuchar telemetría de TODOS los GPS:
  `gps/devices/+/telemetry`
  *(El signo `+` reemplaza al UUID, permitiendo recibir todo)*.

  ## 3. EJEMPLOS DE USO

 # A. Telemetría (Reporte de Ubicación)
Topic: gps/devices/550e8400-e29b.../telemetry

Payload (JSON):
{
  "lat": 19.2433,
  "lng": -103.7244,
  "speed": 45.5,
  "batt": 98,
  "ts": 1709123456
}
Descripción: El dispositivo envía esto cada 10 segundos. El servidor lo guarda en la base de datos gps.telemetry.

# B. Status (Control de Conexión)
Topic: gps/devices/550e8400-e29b.../status

Payload: online o offline

Descripción:

Al conectarse, el dispositivo envía online (Retained: True).
Si el dispositivo pierde conexión abruptamente (se queda sin batería o sin señal), el Broker envía automáticamente offline (gracias a la configuración Last Will & Testament).

# C. Comandos (Reinicio Remoto)
Topic: gps/commands/550e8400-e29b.../reboot

Payload: {"force": true}

Descripción: El servidor envía este mensaje. El dispositivo lo recibe, se reinicia y confirma la acción en el topic de respuesta.

# D. Respuesta de Comandos (Ack)
Topic: gps/devices/550e8400-e29b.../cmd_res

Payload:
{
  "cmd": "reboot",
  "result": "success",
  "ts": 1709123500
}
## 4. GUIA DE WILDCARDS

# El Signo Más (+) - Nivel Único
Reemplaza un solo nivel de la jerarquía. Es el más seguro y útil para tu servidor Laravel.

Patrón: gps/devices/+/telemetry

¿Qué captura?:

-gps/devices/UUID-1/telemetry (✅ SÍ)
-gps/devices/UUID-2/telemetry (✅ SÍ)
-gps/devices/UUID-1/status (❌ NO - El final no coincide)

Uso: Tu "Worker" de Laravel usará esto para recibir coordenadas de todos los camiones con una sola conexión.

# El Numeral (#) - Multinivel
Reemplaza todo lo que sigue hasta el final. Es muy potente pero peligroso.

Patrón: gps/devices/#

¿Qué captura?:

-gps/devices/UUID-1/telemetry (✅ SÍ)
-gps/devices/UUID-1/status (✅ SÍ)
-gps/devices/UUID-2/cmd_res (✅ SÍ)

Uso: Solo para Debugging manual.

Peligro: Si usas esto en producción, tu servidor recibirá toneladas de basura que no necesita procesar, saturando la memoria.

## Matriz de Permisos y Control de Acceso (ACL)
Esta seccion define los privilegios estrictos configurados en el broker Mosquitto (acl.conf). El sistema utiliza el principio de mínimo privilegio.
1. Rol: Super Admin
Usuario: admin

Descripción: Administrador del sistema y desarrolladores.
Nivel de Acceso: TOTAL (Read/Write #)
Tiene acceso irrestricto a todos los topics para depuración y mantenimiento.

2. Rol: Backend (Laravel)
Usuario: laravel_backend

Descripción: La aplicación Laravel (Worker) que procesa los datos.
Nivel de Acceso: ESPECÍFICO
Lectura: Lee toda la telemetría de la flota (gps/devices/+/telemetry), estados de conexión y respuestas de comandos.
Escritura: Puede enviar comandos a cualquier dispositivo (gps/commands/#).
Restricción: No puede suplantar la identidad de un GPS (no puede escribir telemetría falsa).

3. Rol: Dispositivo GPS
Usuario: %u (El UUID del dispositivo)

Descripción: Los dispositivos físicos (LilyGo, Teltonika, etc.).
Nivel de Acceso: RESTRICTIVO (Silo)
Escritura: Solo puede publicar en SU propio topic de telemetría (gps/devices/%u/telemetry), status y respuesta.
Lectura: Solo puede escuchar SUS propios comandos (gps/commands/%u/+).
Restricción: No puede ver ni afectar a ningún otro dispositivo de la flota. Aislamiento total.

## LIMITES ENCONTRADOS EN LA FASE DE TESTING
Se realizaron pruebas de estrés con un script maestro inyectando mensajes concurrentes. Con 10 dispositivos, la latencia de inyección fue de 0.0008 segundos. Al escalar a 100 dispositivos, el sistema mantuvo la estabilidad alcanzando un throughput de 24673.83 mensajes/segundo sin bloqueos en el broker, validando la eficiencia del protocolo MQTT frente a HTTP para telemetría de alta frecuencia