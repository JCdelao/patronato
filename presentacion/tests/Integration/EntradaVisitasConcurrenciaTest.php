<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Services\VisitaService;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $directorio = getenv('HU42_PG_FIXTURE_DIR') ?: '';
    if ($directorio === '') {
        $this->markTestSkipped('Requiere un clúster PostgreSQL desechable para HU-42.');
    }

    $config = config('database.connections.pgsql');
    if (config('database.default') !== 'pgsql' || $config['host'] !== '127.0.0.1'
        || (int) $config['port'] < 49152 || $config['database'] !== 'hu42_fixture'
        || $config['username'] !== 'hu42_fixture' || $config['url'] !== null
        || ! is_file($directorio.'/PG_VERSION')) {
        throw new RuntimeException('La configuración no corresponde al clúster de pruebas de HU-42.');
    }

    $this->principal = DB::connection();
    $verificar = function ($conexion) use ($directorio, $config): int {
        $estado = $conexion->selectOne("select current_database() as base, current_setting('data_directory') as directorio, inet_server_port() as puerto, pg_backend_pid() as pid");
        if ($estado->base !== 'hu42_fixture' || (int) $estado->puerto !== (int) $config['port']
            || realpath($estado->directorio) !== realpath($directorio)) {
            throw new RuntimeException('La conexión no pertenece al clúster desechable de HU-42.');
        }

        return (int) $estado->pid;
    };
    config(['database.connections.hu42_concurrente' => $config]);
    $this->administracion = DB::connection('hu42_concurrente');
    expect($verificar($this->principal))->not->toBe($verificar($this->administracion));

    if (! Schema::hasTable('visitas')) {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
    $this->seed(RolesSeeder::class);

    $r = Residencial::query()->create(['nombre' => 'Fixture HU-42 '.Str::uuid()]);
    $this->cuenta = User::factory()->create([
        'residencial_id' => $r->id,
        'rol_id' => Rol::query()->where('codigo', Rol::GUARDIA)->value('id'),
        'activo' => true,
    ]);
    $this->guardia = Guardia::query()->create([
        'residencial_id' => $r->id, 'user_id' => $this->cuenta->id,
        'identidad' => 'G-01', 'nombre' => 'Guardia ficticio', 'turno' => 'dia',
    ]);
    Turno::query()->create([
        'residencial_id' => $r->id, 'guardia_id' => $this->guardia->id, 'tipo' => 'dia',
        'inicio' => now()->subHour(), 'fin' => now()->addHour(), 'inicio_real' => now()->subHour(),
    ]);
    $tipo = TipoVivienda::query()->where('residencial_id', $r->id)->firstOrFail();
    $vivienda = Vivienda::query()->create([
        'residencial_id' => $r->id, 'tipo_vivienda_id' => $tipo->id, 'codigo' => 'A-01',
    ]);
    $medio = MedioEntrada::query()->where('residencial_id', $r->id)->where('nombre', 'A pie')->firstOrFail();
    $this->datos = [
        'vivienda_id' => $vivienda->id, 'medio_entrada_id' => $medio->id,
        'nombre_visitante' => 'Visitante ficticio', 'identidad' => 'V-01', 'motivo' => 'Prueba',
    ];
    $this->desactivar = fn () => $this->administracion->transaction(function () {
        $this->administracion->table('guardias')->where('id', $this->guardia->id)->lockForUpdate()->first();
        $this->administracion->table('guardias')->where('id', $this->guardia->id)->update(['activo' => false]);
        $this->administracion->table('users')->where('id', $this->cuenta->id)->update(['activo' => false]);
    });
    $this->registrar = fn () => app(VisitaService::class)->registrar($this->cuenta, $this->datos);
    $this->visitas = fn () => $this->principal->table('visitas')->where('guardia_entrada_id', $this->guardia->id)->count();
});

test('rejects the entry when guard deactivation commits first', function () {
    $desactivado = false;
    $this->principal->beforeExecuting(function (string $sql) use (&$desactivado) {
        if (! $desactivado && str_contains($sql, 'from "guardias"')) {
            expect($this->principal->transactionLevel())->toBe(1);
            ($this->desactivar)();
            $desactivado = true;
        }
    });

    expect($this->registrar)->toThrow(HttpException::class, 'Esta cuenta no está vinculada a un guardia activo.')
        ->and($desactivado)->toBeTrue()
        ->and(($this->visitas)())->toBe(0);
});

test('guard and account stay locked until the entry commits when registration wins', function () {
    $bloqueos = [];
    $this->principal->beforeExecuting(function (string $sql) use (&$bloqueos) {
        if (! str_starts_with($sql, 'insert into "visitas"')) {
            return;
        }

        foreach (['guardias' => $this->guardia->id, 'users' => $this->cuenta->id] as $tabla => $id) {
            $bloqueos[$tabla] = null;
            try {
                $this->administracion->table($tabla)->where('id', $id)->lock('for update nowait')->first();
            } catch (QueryException $error) {
                $bloqueos[$tabla] = $error->getCode();
            }
        }
    });

    ($this->registrar)();
    expect($bloqueos)->toBe(['guardias' => '55P03', 'users' => '55P03'])
        ->and(($this->visitas)())->toBe(1);

    ($this->desactivar)();
    expect($this->registrar)->toThrow(HttpException::class)
        ->and(($this->visitas)())->toBe(1);
});

test('does not wait on a locked account while holding the guard lock', function () {
    $this->administracion->beginTransaction();
    try {
        $this->administracion->table('users')->where('id', $this->cuenta->id)->update(['activo' => false]);
        expect($this->registrar)->toThrow(ValidationException::class);

        // A la edición de cuenta ya no le bloquea la fila del guardia.
        expect($this->administracion->table('guardias')->where('id', $this->guardia->id)
            ->lock('for update nowait')->first())->not->toBeNull();
        $this->administracion->commit();
    } finally {
        $this->administracion->rollBack();
    }

    expect($this->registrar)->toThrow(HttpException::class, 'Esta cuenta no está autorizada para registrar entradas.')
        ->and(($this->visitas)())->toBe(0);
});
