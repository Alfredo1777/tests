<?php

namespace App\Http\Controllers;
/**
 * @OA\Info(
 * version="1.0.0",
 * title="SIGMA GPS API",
 * description="API REST SQL-First para gestión de dispositivos GPS, telemetría de alta frecuencia y análisis geoespacial con TimescaleDB.",
 * @OA\Contact(
 * email="soporte@sigma-gps.com"
 * )
 * )
 *
 * @OA\Server(
 * url=L5_SWAGGER_CONST_HOST,
 * description="API Server"
 * )
 *
 * @OA\Tag(
 * name="Devices",
 * description="Gestión del ciclo de vida de los dispositivos (CRUD)"
 * )
 * @OA\Tag(
 * name="Telemetry",
 * description="Ingesta masiva de datos de sensores"
 * )
 * @OA\Tag(
 * name="Positions",
 * description="Consultas históricas y geoespaciales (TimescaleDB + PostGIS)"
 * )
 */
abstract class Controller
{
    //
}
