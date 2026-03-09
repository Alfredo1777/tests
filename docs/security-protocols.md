# Protocolos de Seguridad y Rotación de Credenciales

## 1. Cambio de Contraseñas por Defecto
Antes del paso a producción, todas las credenciales generadas en el entorno de desarrollo deben ser destruidas y reemplazadas.

**Procedimiento para cambiar el password de un usuario existente (Ej. `admin` o `laravel_backend`):**
1. Acceder al servidor de producción.
2. Ejecutar el comando de Mosquitto para sobrescribir la contraseña sin borrar el usuario:
   `docker compose exec mqtt mosquitto_passwd -b /mosquitto/config/passwd <nombre_usuario> <NUEVA_CONTRASEÑA_FUERTE>`
3. Reiniciar el broker para invalidar sesiones activas con la contraseña vieja:
   `docker compose restart mqtt`
4. Actualizar el archivo `.env` del backend Laravel con la nueva credencial.

## 2. Rotación de Contraseñas de Dispositivos (GPS)
Por política de seguridad, si un vehículo es dado de baja o comprometido:
1. Eliminar el usuario del broker (revoca acceso inmediato):
   `docker compose exec mqtt mosquitto_passwd -D /mosquitto/config/passwd <UUID_DEL_GPS>`
2. Reiniciar el broker: `docker compose restart mqtt`

## 3. Auditoría de Intentos de Acceso
El sistema está configurado para registrar intrusiones. Para monitorear intentos de autenticación fallidos (fuerza bruta), revisar los logs con el siguiente comando:
`docker compose logs mqtt | grep "Not authorized"`