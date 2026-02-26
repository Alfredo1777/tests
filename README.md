# 🚀 Laravel 12 API - Backend Base

Este repositorio contiene la configuración base para el backend de la aplicación, estructurado como una **API REST pura** utilizando Laravel 12 y PostgreSQL.

## 📋 Características del Setup

* **Framework:** Laravel 12 (Modo API estricto - Sin Vistas/Cookies de sesión web).
* **Lenguaje:** PHP 8.5+
* **Base de Datos:** PostgreSQL.
* **Arquitectura:** Modular Monolítica (DDD Lite).
    * Lógica de negocio desacoplada en directorio `Modules/`.
    * Core del framework en directorio `app/`.
* **Calidad de Código:** Laravel Pint (Estilo) y Larastan (Análisis estático).

---

## 🛠 Requisitos Previos

Asegúrate de tener instalado en tu entorno:

1.  **PHP 8.5+** con extensiones habilitadas:
    * `pdo_pgsql`
    * `pgsql`
    * `fileinfo`
2.  **Composer** 2.x
3.  **PostgreSQL** (Servicio activo)

---

## ⚡️ Instalación y Configuración

Sigue estos pasos para levantar el proyecto desde cero:

### 1. Clonar el repositorio
```bash
## git clone <https://github.com/bayalad7/sigma-back-api>
## cd backend--api

 #Configurar Base de datos
 #CREA UNA BASE DE DATOS VACIA EN TU SERVIDOR POSTGRESQL LOCAL:
 CREATE DATABASE laravel_api_db;

#Instalacion automatica
#Ejecuta el script de setup para instalar dependencias, generar el .env, etc
#En la terminal:
composer run setup


#Para iniciar el lservidor local en el puerto 8000:
#En terminal:
composer run start

#Healtcheck
#Comprobar si la api y la base de datos estan conectadas correctamente
#En terminal:
GET [http://127.0.0.1:8000/api/system/healthcheck](http://127.0.0.1:8000/api/system/healthcheck)
#Respuesta esperada: OK

#ESTRUCTURA DEL PROYECTO:
app/ Core del Framework
Modules/ Logica de negocio (features)
boostrap/ Configuracion de arranque (Api mode)

#DOCKER Y LA CREACION DE USUARIOS

Generar Credenciales:

-User: Obtener UUID del dispositivo (ej. impreso en la etiqueta o generado por software).
-Pass: Generar cadena aleatoria de 12 caracteres (A-Z, a-z, 0-9, símbolos).

Registrar en el Broker:
Ejecutar el siguiente comando (sin reiniciar el servicio):
"docker compose exec mqtt mosquitto_passwd -b /mosquitto/config/passwd <UUID_DEL_DEVICE> <PASSWORD_GENERADO>"
Reiniciar docker.
