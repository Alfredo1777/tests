<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Spatial Reference System Identifier (SRID)
    |--------------------------------------------------------------------------
    | El estándar GPS mundial es WGS 84 (SRID 4326).
    | Asegurarse de que coincida con la definición de la BD:
    | location GEOGRAPHY(POINT, 4326)
    */
    'default_srid' => env('POSTGIS_SRID', 4326),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    | Esquemas habilitados para búsqueda en PostgreSQL.
    | Esto alinea Laravel con el script '04_security.sql'.
    */
    'search_paths' => ['core', 'gps', 'security', 'public'],
];