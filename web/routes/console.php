<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Patronato\Modules\Identidad\Services\CuentaService;
use Patronato\Modules\Plataforma\Services\RespaldoService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('plataforma:admin {email}', function (CuentaService $cuentas) {
    $usuario = User::query()->where('email', $this->argument('email'))->first();

    if ($usuario === null) {
        $this->error('No existe una cuenta con ese correo.');

        return 1;
    }

    $cuentas->asignarAdministradorPlataforma($usuario);

    $this->info("{$usuario->email} ahora es administrador de la plataforma.");

    return 0;
})->purpose('Convierte una cuenta existente en administrador de la plataforma');

Artisan::command('respaldos:crear', function (RespaldoService $respaldos) {
    try {
        $this->info('Respaldo creado: '.$respaldos->crear());

        return 0;
    } catch (RuntimeException $e) {
        $respaldos->notificarFallo($e->getMessage());
        $this->error('El respaldo falló: '.$e->getMessage());

        return 1;
    }
})->purpose('Genera un respaldo de la base de datos y conserva los mas recientes');

Artisan::command('respaldos:listar', function (RespaldoService $respaldos) {
    $archivos = $respaldos->listar();

    $this->table(['Respaldo'], array_map(fn (string $archivo) => [$archivo], $archivos));

    return 0;
})->purpose('Muestra los respaldos disponibles');

Artisan::command('respaldos:restaurar {archivo} {--base= : Base de datos donde restaurar} {--produccion : Confirma que se reemplaza la base en uso}', function (RespaldoService $respaldos) {
    $base = $this->option('base');
    $archivo = $this->argument('archivo');

    if (! is_string($base) || $base === '' || ! is_string($archivo)) {
        $this->error('Indica la base de destino con --base=nombre.');

        return 1;
    }

    if ($base === $respaldos->base() && ! $this->option('produccion')) {
        $this->error("{$base} es la base en uso y su contenido se reemplazaria. Prueba primero en otra base, o confirma con --produccion.");

        return 1;
    }

    try {
        $respaldos->restaurar($archivo, $base);
    } catch (RuntimeException $e) {
        $this->error('La restauracion falló: '.$e->getMessage());

        return 1;
    }

    $this->info("Respaldo restaurado en {$base}.");

    return 0;
})->purpose('Restaura un respaldo en la base indicada');

Schedule::command('respaldos:crear')->dailyAt('02:00')->timezone('America/Tegucigalpa');

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
