<?php

return [
    // Carpeta de pg_dump y pg_restore
    'bin' => env('PG_BIN_PATH', ''),

    // Carpeta de los respaldos
    'directorio' => storage_path('app/private/respaldos'),

    // Cantidad de respaldos que se conservan
    'conservar' => (int) env('RESPALDOS_CONSERVAR', 30),
];
