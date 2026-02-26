# Documentación de Hardening del Broker MQTT

Este documento detalla las estrategias de fortificación (hardening) aplicadas al broker Mosquitto para asegurar la integridad, confidencialidad y disponibilidad de los datos de telemetría vehicular.



## 1. Autenticación y Autorización Estricta
* **Denegación por defecto:** Se deshabilitó el acceso anónimo (`allow_anonymous false`). Ningún cliente puede establecer conexión sin credenciales válidas.
* **Control de Acceso Basado en Roles (RBAC):** Se implementó un archivo `acl.conf` con reglas granulares. Los dispositivos GPS solo tienen permisos de escritura (`write`) sobre su propio topic de telemetría utilizando la variable de coincidencia de patrón `%u` (UUID). El usuario backend (`laravel_backend`) tiene permisos de lectura globales limitados a los paths necesarios.

## 2. Prevención de Denegación de Servicio (Rate Limiting)
Para mitigar ataques de denegación de servicio (DDoS) o dispositivos defectuosos que envíen tráfico masivo:
* **`message_size_limit`:** Restringido a 5KB. Bloquea payloads maliciosos o excesivamente grandes.
* **`max_connections`:** Limitado a 1000 conexiones concurrentes para evitar el agotamiento de sockets (file descriptors) en el servidor.
* **`max_inflight_messages`:** Limitado a 20 para evitar la saturación del buffer de red de Mosquitto.

## 3. Encriptación de Tránsito (TLS/SSL)
Se preparó el listener en el puerto estandarizado `8883` con configuración de certificados (CA, Server Cert y Private Key).
* Se restringió la negociación de protocolos forzando el uso exclusivo de **TLS v1.2** o superior, mitigando vulnerabilidades conocidas en protocolos obsoletos como SSLv3 o TLS v1.0.

## 4. Auditoría y Logs
Se habilitó la bitácora de seguridad (`log_type warning`, `log_type error`). Mosquitto registra intentos de conexión con credenciales inválidas y violaciones de ACL, permitiendo la integración futura con herramientas como Fail2Ban para bloquear IPs atacantes a nivel de firewall.

## 5. Rotación de Credenciales en Producción
Se definió el procedimiento estándar para la rotación de contraseñas utilizando la utilidad `mosquitto_passwd`. Al pasar a producción, las contraseñas generadas en el entorno local de desarrollo deben ser reemplazadas inmediatamente y el contenedor reiniciado.