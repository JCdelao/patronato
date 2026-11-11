<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Patronato\Negocio\Notificaciones\RespaldoFallido;
use Patronato\Negocio\Services\RespaldoService;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'respaldos_'.uniqid();
    File::ensureDirectoryExists($this->directorio);

    config(['respaldos.directorio' => $this->directorio, 'respaldos.bin' => 'C:\\pg\\bin']);

    Notification::fake();

    // Simula pg_dump escribiendo el archivo que recibe en --file
    $this->dumpExitoso = fn () => Process::fake(function (PendingProcess $proceso) {
        foreach ((array) $proceso->command as $argumento) {
            if (str_starts_with($argumento, '--file=')) {
                file_put_contents(substr($argumento, 7), 'contenido');
            }
        }

        return Process::result();
    });
});

afterEach(function () {
    File::deleteDirectory($this->directorio);
});

test('a backup file is created without exposing the password in the command', function () {
    ($this->dumpExitoso)();

    $this->artisan('respaldos:crear')->assertSuccessful();

    expect(app(RespaldoService::class)->listar())->toHaveCount(1);

    Process::assertRan(function (PendingProcess $proceso) {
        $comando = (array) $proceso->command;
        $clave = DB::connection('pgsql')->getConfig('password');

        return str_contains($comando[0], 'pg_dump')
            && in_array('--format=custom', $comando, true)
            && collect($comando)->every(fn (string $argumento) => ! str_ends_with($argumento, '='.$clave) && ! str_starts_with($argumento, '--password'))
            && array_key_exists('PGPASSWORD', $proceso->environment);
    });

    Notification::assertNothingSent();
});

test('at least the last 30 backups are kept', function () {
    ($this->dumpExitoso)();

    foreach (range(1, 28) as $dia) {
        file_put_contents($this->directorio.DIRECTORY_SEPARATOR.sprintf('patronato_202601%02d_020000.dump', $dia), 'x');
        file_put_contents($this->directorio.DIRECTORY_SEPARATOR.sprintf('patronato_202602%02d_020000.dump', $dia), 'x');
    }

    $this->artisan('respaldos:crear')->assertSuccessful();

    $archivos = app(RespaldoService::class)->listar();

    expect($archivos)->toHaveCount(30)
        ->and($archivos[0])->not->toContain('202601')
        ->and(collect($archivos)->contains(fn (string $nombre) => str_contains($nombre, '20260101')))->toBeFalse();
});

test('a failed backup notifies the administrators and leaves no file', function () {
    $residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $rol = fn (string $codigo) => Rol::query()->where('codigo', $codigo)->value('id');

    $tecnico = User::factory()->create(['residencial_id' => $residencial->id, 'rol_id' => $rol(Rol::ADMIN_TECNICO)]);
    $plataforma = User::factory()->create(['rol_id' => $rol(Rol::ADMIN_PLATAFORMA)]);
    $inactivo = User::factory()->create(['residencial_id' => $residencial->id, 'rol_id' => $rol(Rol::ADMIN_TECNICO), 'activo' => false]);
    $vocal = User::factory()->create(['residencial_id' => $residencial->id, 'rol_id' => $rol('vocal')]);

    Process::fake(fn () => Process::result(errorOutput: 'conexion rechazada', exitCode: 1));

    $this->artisan('respaldos:crear')->assertFailed();

    Notification::assertSentTo([$tecnico, $plataforma], RespaldoFallido::class, fn (RespaldoFallido $aviso) => $aviso->error === 'conexion rechazada');
    Notification::assertNotSentTo([$inactivo, $vocal], RespaldoFallido::class);

    expect(app(RespaldoService::class)->listar())->toBe([]);
});

test('the backup runs every day', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($evento) => str_contains((string) $evento->command, 'respaldos:crear'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('0 2 * * *')
        ->and((string) $evento->timezone)->toBe('America/Tegucigalpa');
});

test('restoring over the database in use requires explicit confirmation', function () {
    Process::fake();

    file_put_contents($this->directorio.DIRECTORY_SEPARATOR.'patronato_20260101_020000.dump', 'x');

    $enUso = app(RespaldoService::class)->base();

    $this->artisan('respaldos:restaurar', ['archivo' => 'patronato_20260101_020000.dump'])->assertFailed();
    $this->artisan('respaldos:restaurar', ['archivo' => 'patronato_20260101_020000.dump', '--base' => $enUso])->assertFailed();

    Process::assertNothingRan();

    $this->artisan('respaldos:restaurar', ['archivo' => 'patronato_20260101_020000.dump', '--base' => $enUso, '--produccion' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $proceso) => str_contains(((array) $proceso->command)[0], 'pg_restore'));
});

test('a backup can be restored into another database', function () {
    Process::fake();

    file_put_contents($this->directorio.DIRECTORY_SEPARATOR.'patronato_20260101_020000.dump', 'x');

    $this->artisan('respaldos:restaurar', ['archivo' => 'patronato_20260101_020000.dump', '--base' => 'patronato_pruebas'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $proceso) => in_array('--dbname=patronato_pruebas', (array) $proceso->command, true));
});

test('a missing or outside file is rejected', function () {
    Process::fake();

    $this->artisan('respaldos:restaurar', ['archivo' => 'no-existe.dump', '--base' => 'patronato_pruebas'])->assertFailed();
    $this->artisan('respaldos:restaurar', ['archivo' => '..\\..\\.env', '--base' => 'patronato_pruebas'])->assertFailed();

    Process::assertNothingRan();
});
