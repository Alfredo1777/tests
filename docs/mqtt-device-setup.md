# Guía de Integración para Dispositivos GPS

## 1. Parámetros de Conexión
* **Protocolo:** MQTT v3.1.1 o v5.0
* **Host/URL:** `mqtt://[IP_DEL_SERVIDOR_PRODUCCION]`
* **Puerto:** `1883` (o `8883` si se habilita TLS/SSL)
* **Client ID:** Debe ser exactamente igual al UUID asignado.
* **Keep Alive:** 60 segundos.

## 2. Autenticación
* **Username:** `<UUID_DEL_DISPOSITIVO>` (ej. `550e8400-e29b-41d4-a716-446655440000`)
* **Password:** Proveída por el administrador del sistema.

## 3. Estructura del Payload (Telemetría)
El dispositivo debe publicar en el topic: `gps/devices/<UUID>/telemetry`
Formato requerido (JSON estricto):
```json
{
  "lat": 19.0521,
  "lng": -104.3155,
  "speed": 65,
  "battery": 98,
  "ts": 1708942000
}