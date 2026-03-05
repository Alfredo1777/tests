# Reglas de Procesamiento, Validación y Sanitización (MQTT)

## 1. Formato de Mensaje Esperado
El sistema procesa telemetría en formato JSON estricto. El topic debe ser: `gps/devices/{UUID}/telemetry`

**Payload Base Ideal:**
```json
{
    "latitude": 19.0521,
    "longitude": -104.3155,
    "speed": 65,
    "battery": 98,
    "satellite": "2026-03-05T10:00:00+00:00"
}