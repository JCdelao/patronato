<?php

return [

    // Carpeta de pg_dump y pg_restore; vacía si ya están en el PATH.
    'bin' => env('PG_BIN_PATH', ''),

    // Fuera de public/ y de git: los respaldos contienen todos los datos.
    'directorio' => storage_path('app/private/respaldos'),

    // Cantidad de respaldos que se conservan (HU-09).
    'conservar' => (int) env('RESPALDOS_CONSERVAR', 30),

];
