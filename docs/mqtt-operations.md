# Manual de Operaciones y Mantenimiento

## 1. Gestión del Broker
El broker Mosquitto se ejecuta en un contenedor Docker.
* **Iniciar:** `docker compose up -d mqtt`
* **Detener:** `docker compose stop mqtt`
* **Reiniciar (aplica nuevos permisos):** `docker compose restart mqtt`
* **Ver logs en tiempo real:** `docker compose logs -f mqtt`

## 2. Gestión de Dispositivos (Alta)
Para agregar un nuevo vehículo al sistema:
1. Generar un UUID único para el dispositivo.
2. Generar una contraseña segura.
3. Registrar la credencial en el broker ejecutando:
   `docker compose exec mqtt mosquitto_passwd -b /mosquitto/config/passwd <UUID> <CONTRASEÑA>`
4. Los permisos de lectura/escritura se aplican automáticamente gracias a las reglas dinámicas (Pattern) en el archivo `acl.conf`.

## 3. Monitoreo de Salud
Para verificar el estado del broker, conexiones concurrentes y uso de memoria:
* Ejecutar en el servidor: `php artisan mqtt:health`
* Este comando inyecta un reporte JSON en el topic `gps/events/stats` para su consumo en dashboards.

## 4. Troubleshooting (Problemas Comunes)
* **Mensajes fantasmas (Laravel dice conectado pero no recibe nada):** Generalmente causado por un conflicto de "Client ID". Asegurarse de que el worker genere un ID único o reiniciar el contenedor del worker.
* **Dispositivo se conecta y se desconecta inmediatamente:** Error de credenciales o el archivo `acl.conf` no le otorga permisos para el topic al que intenta publicar.
* **Broker no inicia tras editar ACLs:** Errores de sintaxis en `acl.conf` o el archivo perdió los permisos de usuario `mosquitto`. Validar los logs del contenedor.