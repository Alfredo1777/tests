(Guía de Producción)

```markdown
# Guía de Deployment para Producción

Para llevar el sistema desde un entorno de desarrollo local a un servidor de producción (VPS/Cloud), se deben seguir estos pasos críticos:

1.  **Aislamiento de Red:** Asegurar que el puerto 1883 esté abierto en el firewall del proveedor de nube (AWS, DigitalOcean, etc.), pero bloquear el puerto de bases de datos desde el exterior.

2.  **Seguridad de Credenciales:**
    * Cambiar inmediatamente las contraseñas de `admin` y `laravel_backend` usadas en desarrollo.

3.  **Persistencia del Worker (Docker Nativo):**
    Asegurarse de que el archivo `docker-compose.yml` en producción tenga la política `restart: unless-stopped` para el servicio del worker de Laravel. Esto garantiza que el consumidor de MQTT sobreviva a reinicios del servidor físico o caídas de red.

4.  **Encriptación TLS/SSL (Fase 2):**
    Para producción real, se recomienda montar un proxy reverso (como Nginx o Traefik) delante de Mosquitto para proveer certificados SSL, o configurar los certificados directamente en Mosquitto escuchando en el puerto `8883`. Esto evita que las coordenadas viajen en texto plano.