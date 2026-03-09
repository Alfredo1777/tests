# Esquemas de Mensajería MQTT (Telemetría)

Este documento define la estructura estricta y los tipos de datos permitidos para los mensajes entrantes en el topic `gps/devices/+/telemetry` antes de ser procesados por PostgreSQL.

| Campo | Tipo JSON | Requerido | Reglas de Validación (Laravel) | Descripción |
| :--- | :--- | :--- | :--- | :--- |
| `latitude` | Number | **Sí** | `numeric`, entre `-90` y `90` | Coordenada Geoespacial Y. |
| `longitude` | Number | **Sí** | `numeric`, entre `-180` y `180` | Coordenada Geoespacial X. |
| `altitude` | Number | No | `numeric`, nullable | Altura sobre el nivel del mar. |
| `speed` | Number | No | `numeric`, min `0` | Velocidad actual. Default: 0.0 |
| `course` | Integer | No | `integer`, entre `0` y `360` | Grados de dirección. Default: 0 |
| `accuracy` | Number | No | `numeric`, nullable | Precisión del fix GPS. |
| `hdop` | Number | No | `numeric`, nullable | Dilución de precisión horizontal. |
| `battery` | Integer| No | `integer`, entre `0` y `100` | Porcentaje de batería del hardware. |
| `rssi` | Integer| No | `integer`, nullable | Fuerza de la señal celular. |
| `connected`| Boolean| No | `boolean` | Estado de conexión. Default: true |
| `ignition` | Boolean| No | `boolean` | Estado del motor. Default: false |
| `satellite`| String | **Sí** | `date_format:Y-m-d\TH:i:sP` | Timestamp ISO8601 de captura en el GPS. |

## 1. Schemas JSON Documentados

El sistema espera un objeto JSON plano que represente una captura de telemetría en un instante de tiempo. Este payload es procesado por el DTO `TelemetryDTO` antes de su ingesta en PostgreSQL.

**Ejemplo de Payload Válido (Completo):**
```json
{
  "latitude": 19.052145,
  "longitude": -104.315512,
  "altitude": 15.5,
  "speed": 65.5,
  "course": 180,
  "accuracy": 2.5,
  "hdop": 1.2,
  "battery": 98,
  "rssi": -65,
  "connected": true,
  "ignition": true,
  "satellite": "2026-03-04T10:30:00+00:00"
}

## Ejemplo de Payload Válido (Mínimo requerido):

{
  "latitude": 19.052145,
  "longitude": -104.315512,
  "satellite": "2026-03-04T10:30:00+00:00"
}

## Rangos válidos para cada sensor (Reglas de Negocio)
Para evitar la contaminación de la base de datos con coordenadas imposibles o lecturas corruptas de hardware, el validador aplica las siguientes restricciones lógicas a los valores de los sensores:

Sensor / Dato,Rango Mínimo,Rango Máximo,Condición de Rechazo
Latitud (latitude),-90.0,90.0,Valor fuera de los polos geográficos.
Longitud (longitude),-180.0,180.0,Valor fuera del mapa cilíndrico.
Velocidad (speed),0.0,300.0,Valores negativos o superiores a 300 km/h (ilógicos para transporte).
Dirección (course),0,360,Grados de orientación fuera del compás.
Batería (battery),0,100,Porcentajes negativos o mayores a 100%.
Señal (rssi),-120,0,Niveles de atenuación de red celular irreales.
Timestamp (satellite),N/A,N/A,Formato de fecha distinto a Y-m-d\TH:i:sP (ISO8601).