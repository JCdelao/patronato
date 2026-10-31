<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:probar', function () {
    try {
        $db = DB::connection();

        $datos = [
            ['Base de datos', $db->scalar('select current_database()')],
            ['Usuario', $db->scalar('select current_user')],
            ['Servidor', $db->getConfig('host').':'.$db->getConfig('port')],
            ['Tablas', $db->scalar("select count(*) from pg_tables where schemaname = 'public'")],
            ['Roles', $db->scalar('select count(*) from roles')],
        ];

        $this->info('Conexion exitosa');
        $this->table(['Dato', 'Valor'], $datos);

        return 0;
    } catch (Throwable $e) {
        // En Windows, PostgreSQL puede enviar el error en Windows-1252.
        $mensaje = mb_check_encoding($e->getMessage(), 'UTF-8')
            ? $e->getMessage()
            : mb_convert_encoding($e->getMessage(), 'UTF-8', 'Windows-1252');

        $this->error('No se pudo conectar: '.$mensaje);

        return 1;
    }
})->purpose('Prueba la conexion a PostgreSQL sin modificar datos');
