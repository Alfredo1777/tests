## Visión General
El módulo de resiliencia de SIGMA GPS implementa un sistema de telemetría interna para auditar la salud, estabilidad y capacidad de recuperación del Subscriber MQTT. Estas métricas permiten a los equipos de operaciones (DevOps) detectar fugas de memoria, inestabilidad en la red y tasas de error anómalas en tiempo real.

1. Diccionario de Métricas
Las métricas están clasificadas según el estándar de la industria (Prometheus) en tres tipos: Counters (incrementales), Histograms (promedios/tiempos) y Gauges (valores instantáneos).

# Contadores (Counters)
Registran eventos acumulativos que ocurren en el sistema. Nunca decrecen.

# Métricas:

reconnections_total: Número total de reconexiones exitosas al broker Mosquitto. | Valores altos indican inestabilidad en la red o reinicios constantes del contenedor MQTT.
circuit_breaker_opens_total: Veces que el Circuit Breaker ha cambiado a estado OPEN. | Indica caídas sistémicas (ej. BD caída) o un envío masivo de payloads corruptos.
memory_restarts_total: Cantidad de reinicios preventivos ejecutados por alcanzar el Hard Limit de RAM. | Múltiples eventos en un día son un síntoma claro de Memory Leak grave.
graceful_shutdowns_total: Apagados controlados sin pérdida de datos en tránsito. | Verifica que las actualizaciones del sistema o reinicios de Supervisor fueron limpios.

# Medidores de Estado y Tiempo (Gauges & Histograms)

memory_usage_mb = Gauge = Uso de memoria RAM actual del proceso Worker (actualizado cada 5 mins).
processing_time_ms = Histogram = Tiempo promedio que toma procesar un mensaje (Pipeline de Fase 1 a 4).
reconnection_time_ms = Histogram = Tiempo promedio que le toma al sistema recuperar la conexión tras una caída.

## Estrategia de Almacenamiento (Dual Storage)
Para garantizar velocidad sin perder el historial, el sistema utiliza un enfoque de doble escritura:

1. Memoria en Tiempo Real (Redis): Todas las métricas se actualizan instantáneamente en caché utilizando el prefijo mqtt:resilience:*. Esto permite consultas ultrarrápidas para los Health Checks y exposición a Prometheus sin tocar la base de datos.

2. Histórico Auditoría (PostgreSQL): Cada actualización registra un evento en la tabla resilience_metrics. Esta tabla es de tipo append-only (solo inserciones) y permite realizar agregaciones (SUM, AVG) por rangos de fechas (horas, días, semanas).

## Endpoints y Visualización
Interfaz de Línea de Comandos (CLI)
Para uso de desarrolladores y administradores de sistemas en la terminal del servidor.

# Ver métricas del último día (Por defecto)
php artisan mqtt:resilience-metrics

# Ver métricas de la última hora
php artisan mqtt:resilience-metrics --period=hour

# Ver métricas de la última semana
php artisan mqtt:resilience-metrics --period=week

## Integración con Prometheus / Grafana
Las métricas están expuestas nativamente para ser raspadas (scraped) por herramientas de monitoreo externas mediante el formato estándar de Prometheus.

Endpoint: GET /api/v1/metrics (o la ruta configurada).
Content-Type: text/plain; version=0.0.4

Ejemplo de salida:
# TYPE sigma_mqtt_reconnections_total counter
sigma_mqtt_reconnections_total 14
# TYPE sigma_mqtt_memory_usage_mb gauge
sigma_mqtt_memory_usage_mb 128.5
# TYPE sigma_mqtt_circuit_breaker_opens_total counter
sigma_mqtt_circuit_breaker_opens_total 2