# Monitoreo de SIGMA GPS Subscriber

El Worker de MQTT soporta monitoreo activo y pasivo para garantizar su uptime 24/7.

## 1. Nagios / Icinga (Active Checks)
Nagios puede ejecutar el comando Artisan directamente y leer el `Exit Code`. Si el código es `1`, Nagios disparará una alerta roja.
**Comando NRPE configurado:**
`command[check_mqtt_subscriber]=/usr/bin/php /var/www/sigma/artisan mqtt:health-check --quiet`

## 2. Prometheus / Grafana (Exporters)
Prometheus puede leer directamente el archivo de estado en formato JSON usando un `JSON Exporter` o un script en Bash a través de `node_exporter` textfile collector.
**Ruta del archivo de métricas:**
`storage/app/mqtt-subscriber-status.json`

## 3. Datadog (Agent Integration)
Para Datadog, se recomienda usar la integración de **Redis** o la de **Checks Nativos**.
- **Vía Redis:** Configurar el agente para observar el key `mqtt:subscriber:heartbeat`. Si el key desaparece (por el TTL de 90s), Datadog lanza alerta P1.
- **Vía Archivo:** Configurar un script de python en `conf.d` que lea el archivo `/tmp/mqtt-subscriber-status.json` y envíe `messages_processed` como un *Gauge metric*.