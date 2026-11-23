<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Services\AutorizadoFrecuenteService;
use Patronato\Negocio\Services\MedioEntradaService;
use Patronato\Negocio\Services\PreautorizacionService;
use Patronato\Negocio\Services\VisitaService;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;
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
    $this->medio = $medio;
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

test('registration locks the entry medium until its visit commits and then deletion preserves it', function () {
    $bloqueo = null;
    $this->principal->beforeExecuting(function (string $sql) use (&$bloqueo) {
        if (str_starts_with($sql, 'insert into "visitas"')) {
            try {
                $this->administracion->table('medios_entrada')->where('id', $this->medio->id)
                    ->lock('for update nowait')->first();
            } catch (QueryException $error) {
                $bloqueo = $error->getCode();
            }
        }
    });

    ($this->registrar)();
    expect($bloqueo)->toBe('55P03');
    expect(fn () => app(MedioEntradaService::class)->eliminar($this->cuenta, $this->medio))
        ->toThrow(ValidationException::class);
    expect($this->medio->fresh())->not->toBeNull()
        ->and(($this->visitas)())->toBe(1);
});

test('registration rejects the stale choice when deletion commits first', function () {
    $eliminado = false;
    $this->principal->beforeExecuting(function (string $sql) use (&$eliminado) {
        if (! $eliminado && str_contains($sql, 'from "medios_entrada"') && str_contains($sql, 'for update')) {
            $this->administracion->transaction(function () {
                $this->administracion->table('medios_entrada')->where('id', $this->medio->id)->delete();
            });
            $eliminado = true;
        }
    });

    expect($this->registrar)->toThrow(ValidationException::class)
        ->and($eliminado)->toBeTrue()
        ->and(($this->visitas)())->toBe(0);
});

test('concurrent guards cannot record two exits for the same visit', function () {
    $visita = ($this->registrar)();
    $residencialId = $this->guardia->residencial_id;
    $otraCuenta = User::factory()->create([
        'residencial_id' => $residencialId, 'rol_id' => $this->cuenta->rol_id, 'activo' => true,
    ]);
    $otroGuardia = Guardia::query()->create([
        'residencial_id' => $residencialId, 'user_id' => $otraCuenta->id,
        'identidad' => 'G-02', 'nombre' => 'Otro guardia', 'turno' => 'dia',
    ]);
    Turno::query()->create([
        'residencial_id' => $residencialId, 'guardia_id' => $otroGuardia->id, 'tipo' => 'dia',
        'inicio' => now()->subHour(), 'fin' => now()->addHour(), 'inicio_real' => now()->subHour(),
    ]);
    $registrarSalidaConcurrente = function () use ($otraCuenta, $visita): void {
        $principal = DB::getDefaultConnection();
        DB::setDefaultConnection($this->administracion->getName());
        try {
            app(VisitaService::class)->registrarSalida($otraCuenta, $visita->id);
        } finally {
            DB::setDefaultConnection($principal);
        }
    };

    $bloqueo = null;
    $this->administracion->statement("set lock_timeout = '150ms'");
    $this->principal->beforeExecuting(function (string $sql) use ($registrarSalidaConcurrente, &$bloqueo) {
        if (str_starts_with($sql, 'update "visitas"')) {
            try {
                $registrarSalidaConcurrente();
            } catch (QueryException $error) {
                $bloqueo = $error->getCode();
            }
        }
    });

    app(VisitaService::class)->registrarSalida($this->cuenta, $visita->id);
    $salida = $this->principal->table('visitas')->where('id', $visita->id)->first();
    expect($bloqueo)->toBe('55P03')
        ->and($salida->salida_en)->not->toBeNull()
        ->and((int) $salida->guardia_salida_id)->toBe($this->guardia->id);

    expect($registrarSalidaConcurrente)->toThrow(ValidationException::class, 'La salida de esta visita ya fue registrada.');
    expect($this->principal->table('visitas')->where('id', $visita->id)->first())->toEqual($salida)
        ->and($this->principal->table('auditoria')->where('accion', 'visita_salida_registrada')
            ->where('entidad_id', $visita->id)->count())->toBe(1);
});

test('serializes identical preauthorizations and rejects a link closed during the request', function (bool $cerrarVinculo) {
    $residencial = $this->guardia->residencial_id;
    $usuario = User::factory()->create([
        'residencial_id' => $residencial, 'rol_id' => Rol::query()->where('codigo', Rol::RESIDENTE)->value('id'),
    ]);
    $residente = Residente::query()->create([
        'residencial_id' => $residencial, 'user_id' => $usuario->id,
        'nombres' => 'Residente', 'apellidos' => 'Ficticio',
    ]);
    $vivienda = $this->datos['vivienda_id'];
    $vinculo = ViviendaResidente::query()->create([
        'residencial_id' => $residencial, 'residente_id' => $residente->id,
        'vivienda_id' => $vivienda, 'tipo' => 'propietario',
    ]);
    $crear = fn () => app(PreautorizacionService::class)->crear(
        $usuario, $vivienda, $this->medio->id, 'Visitante ficticio', '2026-11-23T14:00', '2026-11-23T15:00',
    );
    $competir = function () use ($crear): bool {
        $principal = DB::getDefaultConnection();
        DB::setDefaultConnection($this->administracion->getName());
        try {
            return $crear();
        } finally {
            DB::setDefaultConnection($principal);
        }
    };
    $intervino = false;
    $bloqueo = null;
    $this->administracion->statement("set lock_timeout = '150ms'");
    $this->principal->beforeExecuting(function (string $sql) use ($cerrarVinculo, $competir, $vinculo, &$intervino, &$bloqueo) {
        if ($intervino) {
            return;
        }
        if ($cerrarVinculo && str_contains($sql, 'from "vivienda_residentes"') && str_contains($sql, 'for update')) {
            $intervino = true;
            $this->administracion->table('vivienda_residentes')->where('id', $vinculo->id)->update(['fecha_fin' => now()->toDateString()]);
        } elseif (! $cerrarVinculo && str_starts_with($sql, 'insert into "preautorizaciones"')) {
            $intervino = true;
            try {
                $competir();
            } catch (QueryException $error) {
                $bloqueo = $error->getCode();
            }
        }
    });

    if ($cerrarVinculo) {
        expect($crear)->toThrow(ValidationException::class, 'Elige una vivienda activa donde tengas un vínculo vigente.');
    } else {
        expect($crear())->toBeTrue()->and($bloqueo)->toBe('55P03');
        expect($competir())->toBeFalse();
    }
    $cantidad = $cerrarVinculo ? 0 : 1;
    expect($intervino)->toBeTrue()
        ->and($this->principal->table('preautorizaciones')->where('residencial_id', $residencial)->count())->toBe($cantidad)
        ->and($this->principal->table('auditoria')->where('residencial_id', $residencial)
            ->where('accion', 'preautorizacion_creada')->count())->toBe($cantidad);
})->with(['concurrent duplicate' => false, 'concurrent link closure' => true]);

test('concurrent frequent authorizations share one record, one housing link and one audit', function () {
    $residencial = $this->guardia->residencial_id;
    $actor = User::factory()->create([
        'residencial_id' => $residencial, 'rol_id' => Rol::query()->where('codigo', Rol::PRESIDENTE)->value('id'),
    ]);
    $vivienda = $this->datos['vivienda_id'];
    $crear = fn () => app(AutorizadoFrecuenteService::class)->crear(
        $actor, 'Proveedor', 'Jardinería', [1, 7], '22:00', '02:00', [$vivienda],
    );
    $competir = function () use ($crear): bool {
        $principal = DB::getDefaultConnection();
        DB::setDefaultConnection($this->administracion->getName());
        try {
            return $crear();
        } finally {
            DB::setDefaultConnection($principal);
        }
    };
    $bloqueo = null;
    $this->administracion->statement("set lock_timeout = '150ms'");
    $this->principal->beforeExecuting(function (string $sql) use ($competir, &$bloqueo) {
        if (str_starts_with($sql, 'insert into "autorizados_frecuentes"')) {
            try {
                $competir();
            } catch (QueryException $error) {
                $bloqueo = $error->getCode();
            }
        }
    });

    expect($crear())->toBeTrue()->and($bloqueo)->toBe('55P03');
    expect($competir())->toBeFalse();
    $registro = $this->principal->table('autorizados_frecuentes')->where('residencial_id', $residencial)->sole();
    $vinculos = $this->principal->table('autorizado_frecuente_viviendas');
    $datos = ['residencial_id' => $residencial, 'autorizado_id' => $registro->id, 'vivienda_id' => $vivienda];
    expect($vinculos->where('autorizado_id', $registro->id)->count())->toBe(1)
        ->and($this->principal->table('auditoria')->where('residencial_id', $residencial)
            ->where('accion', 'autorizado_frecuente_creado')->count())->toBe(1);
    $otro = Residencial::query()->create(['nombre' => 'Fixture externo '.Str::uuid()]);
    $casaAjena = Vivienda::query()->create([
        'residencial_id' => $otro->id, 'codigo' => 'A-01',
        'tipo_vivienda_id' => TipoVivienda::query()->where('residencial_id', $otro->id)->value('id'),
    ]);
    expect(fn () => $vinculos->insert($datos))->toThrow(QueryException::class)
        ->and(fn () => $vinculos->insert([...$datos, 'vivienda_id' => $casaAjena->id]))->toThrow(QueryException::class)
        ->and(fn () => $this->principal->table('autorizados_frecuentes')->where('id', $registro->id)
            ->update(['dias_semana' => '[8]']))->toThrow(QueryException::class);
});
