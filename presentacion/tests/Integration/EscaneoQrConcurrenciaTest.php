<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Patronato\Negocio\Services\EscaneoQrService;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent decisions serialize on the guard and a retry preserves the result', function () {
    $fixture = getenv('HU42_PG_FIXTURE_DIR') ?: '';
    if ($fixture === '') {
        $this->markTestSkipped('Requiere el clúster PostgreSQL desechable de CI.');
    }

    $config = config('database.connections.pgsql');
    $principal = DB::connection();
    if ($principal->getDriverName() !== 'pgsql' || $config['host'] !== '127.0.0.1'
        || (int) $config['port'] < 49152 || $config['database'] !== 'hu42_fixture'
        || $config['username'] !== 'hu42_fixture' || $config['url'] !== null || ! is_file($fixture.'/PG_VERSION')) {
        throw new RuntimeException('La conexión no corresponde al clúster de pruebas.');
    }
    $estado = $principal->selectOne("select current_setting('data_directory') as ruta");
    expect(realpath($estado->ruta))->toBe(realpath($fixture));
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->seed(RolesSeeder::class);

    $residencial = Residencial::query()->create(['nombre' => 'Fixture HU-48 '.Str::uuid()]);
    $usuario = User::factory()->create(['activo' => true, 'residencial_id' => $residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::GUARDIA)->value('id')]);
    $guardia = Guardia::query()->create(['residencial_id' => $residencial->id, 'user_id' => $usuario->id,
        'identidad' => 'G-48', 'nombre' => 'Guardia ficticio', 'turno' => 'dia']);
    Turno::query()->create(['residencial_id' => $residencial->id, 'guardia_id' => $guardia->id, 'tipo' => 'dia',
        'inicio' => now()->subHour(), 'fin' => now()->addHour(), 'inicio_real' => now()->subHour()]);

    $servicio = app(EscaneoQrService::class);
    $id = $servicio->escanear($usuario, 'desconocido')['id'];
    config(['database.connections.hu48_concurrente' => $config]);
    $competidora = DB::connection('hu48_concurrente');
    $competidora->statement("set lock_timeout = '150ms'");

    $rechazos = 0;
    $principal->beforeExecuting(function (string $sql) use ($competidora, $guardia, &$rechazos): void {
        if (! str_starts_with($sql, 'update "escaneos_qr"')) {
            return;
        }

        try {
            $competidora->table('guardias')->where('id', $guardia->id)->lockForUpdate()->first();
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03');
            $rechazos++;
        }
    });

    $servicio->decidir($usuario, $id, 'no_ingreso');
    $servicio->decidir($usuario, $id, 'no_ingreso');
    expect($rechazos)->toBe(1);
    $this->assertDatabaseHas('escaneos_qr', ['id' => $id, 'decision_final' => 'no_ingreso']);
});
