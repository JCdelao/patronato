<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Patronato\Negocio\Services\CarnetService;
use Patronato\Negocio\Services\CuentaService;
use Patronato\Negocio\Services\GeneracionCargosService;
use Patronato\Negocio\Services\RespaldoService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('plataforma:admin {email}', function (CuentaService $cuentas) {
    $correo = $this->argument('email');
    $usuario = is_string($correo) ? $cuentas->porCorreo($correo) : null;

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

Artisan::command('cargos:generar', function (GeneracionCargosService $generacion) {
    $resultado = $generacion->generarPendientes();

    if ($resultado === []) {
        $this->info('Ningún residencial tenía cargos pendientes de generar.');

        return 0;
    }

    foreach ($resultado as $residencialId => $creados) {
        $this->info("Residencial {$residencialId}: {$creados} cargos generados.");
    }

    return 0;
})->purpose('Genera los cargos del mes de los residenciales cuyo día ya llegó');

// Cada hora, para ponerse al día si el servidor estuvo apagado el día de generación
Schedule::command('cargos:generar')->hourly()->withoutOverlapping();

Artisan::command('carnets:vencer', function (CarnetService $carnets) {
    $vencidos = $carnets->vencerVencidos();

    $this->info($vencidos === 0 ? 'Ningún carnet venció hoy.' : "{$vencidos} carnets pasaron a vencidos.");

    return 0;
})->purpose('Pasa a vencidos los carnets activos cuya vigencia terminó');

// Cada día al empezar, en la hora de Honduras; si el servidor estuvo apagado se pone al día al siguiente
Schedule::command('carnets:vencer')->dailyAt('00:05')->timezone('America/Tegucigalpa')->withoutOverlapping();

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
        // En Windows, PostgreSQL puede enviar el error en Windows-1252
        $mensaje = mb_check_encoding($e->getMessage(), 'UTF-8')
            ? $e->getMessage()
            : mb_convert_encoding($e->getMessage(), 'UTF-8', 'Windows-1252');

        $this->error('No se pudo conectar: '.$mensaje);

        return 1;
    }
})->purpose('Prueba la conexion a PostgreSQL sin modificar datos');
