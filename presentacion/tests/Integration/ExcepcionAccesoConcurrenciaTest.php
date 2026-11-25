<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Services\ExcepcionAccesoService;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent grants serialize on the housing record', function () {
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

    $r = Residencial::query()->create(['nombre' => 'Fixture HU-50 '.Str::uuid()]);
    $usuario = User::factory()->create(['activo' => true, 'residencial_id' => $r->id,
        'rol_id' => Rol::query()->where('codigo', Rol::PRESIDENTE)->value('id')]);
    $vivienda = Vivienda::query()->create(['residencial_id' => $r->id, 'codigo' => 'A-50',
        'tipo_vivienda_id' => TipoVivienda::query()->where('residencial_id', $r->id)->firstOrFail()->id]);
    DB::table('configuracion_residencial')->updateOrInsert(['residencial_id' => $r->id], ['acceso_depende_pago' => true]);
    $concepto = DB::table('conceptos_cobro')->insertGetId(['residencial_id' => $r->id, 'nombre' => 'Cuota ficticia', 'tipo' => 'recurrente']);
    DB::table('cargos')->insert(['residencial_id' => $r->id, 'vivienda_id' => $vivienda->id,
        'concepto_id' => $concepto, 'periodo' => now()->startOfMonth()->subMonth()->toDateString(), 'monto' => '100.00', 'origen' => 'sistema']);

    config(['database.connections.hu50_concurrente' => $config]);
    $competidora = DB::connection('hu50_concurrente');
    expect($principal->selectOne('select pg_backend_pid() as pid')->pid)
        ->not->toBe($competidora->selectOne('select pg_backend_pid() as pid')->pid);
    $competidora->statement("set lock_timeout = '150ms'");
    $rechazos = 0;
    $principal->beforeExecuting(function (string $sql) use ($competidora, $vivienda, &$rechazos): void {
        if (! str_starts_with($sql, 'insert into "excepciones_acceso"')) {
            return;
        }

        try {
            $competidora->table('viviendas')->where('id', $vivienda->id)->lockForUpdate()->first();
        } catch (QueryException $error) {
            expect($error->getCode())->toBe('55P03');
            $rechazos++;
        }
    });

    $vence = now('America/Tegucigalpa')->addHour()->format('Y-m-d\TH:i');
    $servicio = app(ExcepcionAccesoService::class);
    $servicio->conceder($usuario, $vivienda->id, 'Excepción ficticia', $vence);
    expect(fn () => $servicio->conceder($usuario, $vivienda->id, 'Reintento', $vence))->toThrow(ValidationException::class)
        ->and($rechazos)->toBe(1);
    expect(DB::table('excepciones_acceso')->where('vivienda_id', $vivienda->id)->count())->toBe(1);
});
