# Arquitectura del Sistema MQTT - Rastreo GPS

Este documento describe la topología y el flujo de datos del sistema de telemetría vehicular.

## 1. Componentes Principales
* **Dispositivos GPS (Productores):** Clientes remotos que publican datos de ubicación y estado.
* **Broker MQTT (Eclipse Mosquitto):** El núcleo del sistema. Se encarga de recibir, filtrar y enrutar los mensajes basándose en reglas de seguridad (ACL).
* **Laravel Backend (Consumidor/Worker):** Proceso en segundo plano que se suscribe a los topics relevantes, procesa la telemetría y la almacena/distribuye.

## 2. Flujo de Datos
1.  El camión enciende e inicia conexión TCP con el Broker en el puerto 1883 usando su UUID y contraseña única.
2.  El GPS publica un payload JSON en su topic específico (ej. `gps/devices/{uuid}/telemetry`).
3.  El Broker valida que el dispositivo tenga permisos de escritura (ACL) para ese topic.
4.  El Worker de Laravel, que mantiene una conexión persistente (suscrito a `gps/devices/+/telemetry`), recibe el mensaje instantáneamente.
5.  Laravel procesa el JSON, dispara los eventos del sistema y guarda el registro en la base de datos PostgreSQL.

## 3. Estructura de Topics
El sistema utiliza un patrón jerárquico para el enrutamiento de mensajes:
* `gps/devices/{uuid}/telemetry`: (Lectura Laravel / Escritura GPS) Coordenadas, velocidad, batería.
* `gps/devices/{uuid}/status`: (Lectura Laravel / Escritura GPS) Encendido, apagado, alarmas SOS.
* `gps/commands/{uuid}/+`: (Escritura Laravel / Lectura GPS) Comandos remotos enviados desde el servidor al vehículo (ej. apagar motor).
* `gps/events/stats`: (Escritura Laravel / Lectura Admin) Métricas de salud del sistema, publicadas por el comando de monitoreo.