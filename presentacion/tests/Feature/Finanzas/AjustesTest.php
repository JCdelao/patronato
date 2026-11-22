<?php

/*
 * Correcciones autorizadas al saldo de una vivienda
 *
 * Solo el presidente y el tesorero las registran. Cambian el saldo general y
 * aparecen en el estado de cuenta, sin tocar cuotas, pagos, aplicaciones ni mora.
 */

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Services\AjusteService;
use Patronato\Negocio\Services\CobroService;
use Patronato\Negocio\Services\GeneracionCargosService;
use Patronato\Negocio\Services\MoraService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Persistencia\Models\Ajuste;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\PagoRepositorio;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    // 03:00 en UTC del 22 de noviembre todavía es 21 de noviembre en Honduras
    Carbon::setTestNow('2026-11-22 03:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $codigo, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($codigo),
        ...$datos,
    ]);

    $this->presidente = ($this->usuario)(Rol::PRESIDENTE, null, ['name' => 'Paula Presidenta']);
    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);
    $this->cobrador = ($this->usuario)('cobrador');

    $this->vivienda = fn (string $codigo, bool $activa = true, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)('A-01');

    // Dos meses de L 500 con el primero pagado, para comprobar que nada de eso cambia
    $concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->cargos = array_map(fn (string $periodo): int => (int) DB::table('cargos')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'concepto_id' => $concepto,
        'periodo' => $periodo,
        'monto' => '500.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]), ['2026-10-01', '2026-11-01']);

    app(CobroService::class)->registrarEfectivo($this->cobrador, $this->casa->id, '500', [$this->cargos[0]]);

    $this->datos = fn (array $cambios = []): array => [
        'vivienda_id' => $this->casa->id,
        'sentido' => AjusteService::AUMENTA,
        'monto' => '150',
        'motivo' => 'Cuota de octubre registrada con un monto menor',
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route('finanzas.ajustes.store'), ($this->datos)($cambios));

    $this->saldo = fn (?Vivienda $vivienda = null): string => app(PagoRepositorio::class)->saldoDeVivienda(($vivienda ?? $this->casa)->id);
});

afterEach(fn () => Carbon::setTestNow());

// Registro

test('the president registers a correction that increases the balance', function () {
    expect(($this->saldo)())->toBe('500.00');

    ($this->registrar)()->assertSessionHasNoErrors()->assertRedirect(route('finanzas.ajustes.index', ['vivienda' => $this->casa->id]));

    $ajuste = Ajuste::query()->sole();

    expect($ajuste->tipo)->toBe(Ajuste::CORRECCION)
        ->and($ajuste->monto)->toBe('150.00')
        ->and($ajuste->motivo)->toBe('Cuota de octubre registrada con un monto menor')
        ->and($ajuste->autorizado_por)->toBe($this->presidente->id)
        ->and($ajuste->vivienda_id)->toBe($this->casa->id)
        ->and(($this->saldo)())->toBe('650.00');
});

test('the treasurer registers a correction that decreases the balance', function () {
    ($this->registrar)(['sentido' => AjusteService::DISMINUYE, 'monto' => '200.50'], $this->tesorero)->assertSessionHasNoErrors();

    $ajuste = Ajuste::query()->sole();

    expect($ajuste->monto)->toBe('-200.50')
        ->and($ajuste->autorizado_por)->toBe($this->tesorero->id)
        ->and(($this->saldo)())->toBe('299.50');
});

test('a correction can leave the balance below zero', function () {
    ($this->registrar)(['sentido' => AjusteService::DISMINUYE, 'monto' => '800'])->assertSessionHasNoErrors();

    expect(($this->saldo)())->toBe('-300.00');
});

test('the date is today in Honduras, not in UTC', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    expect(Ajuste::query()->sole()->fecha->toDateString())->toBe('2026-11-21');
});

test('the type, the date and who authorizes are set by the system', function () {
    ($this->registrar)([
        'tipo' => 'descuento',
        'fecha' => '2020-01-01',
        'autorizado_por' => $this->cobrador->id,
    ])->assertSessionHasNoErrors();

    $ajuste = Ajuste::query()->sole();

    expect($ajuste->tipo)->toBe(Ajuste::CORRECCION)
        ->and($ajuste->fecha->toDateString())->toBe('2026-11-21')
        ->and($ajuste->autorizado_por)->toBe($this->presidente->id);
});

test('amount, reason and direction are required and validated', function (array $cambios, string $campo) {
    ($this->registrar)($cambios)->assertSessionHasErrors($campo);

    expect(Ajuste::query()->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'ajuste_registrado')->exists())->toBeFalse();
})->with([
    'sin monto' => [['monto' => ''], 'monto'],
    'monto cero' => [['monto' => '0'], 'monto'],
    'monto negativo' => [['monto' => '-50'], 'monto'],
    'tres decimales' => [['monto' => '10.555'], 'monto'],
    'sin motivo' => [['motivo' => ''], 'motivo'],
    'motivo corto' => [['motivo' => 'Corrige 1'], 'motivo'],
    'motivo corto con espacios' => [['motivo' => '   Corrige    '], 'motivo'],
    'sin sentido' => [['sentido' => ''], 'sentido'],
    'sentido inválido' => [['sentido' => 'condonar'], 'sentido'],
    'sin vivienda' => [['vivienda_id' => ''], 'vivienda_id'],
]);

test('the service also requires a reason of at least ten characters', function () {
    expect(fn () => app(AjusteService::class)->registrar($this->presidente, $this->casa->id, AjusteService::AUMENTA, '100', '  corto   '))
        ->toThrow(ValidationException::class);

    expect(Ajuste::query()->count())->toBe(0);
});

test('corrections are allowed for inactive housing units', function () {
    $inactiva = ($this->vivienda)('B-09', activa: false);

    $this->actingAs($this->presidente)
        ->get(route('finanzas.ajustes.index', ['buscar' => 'B-09']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas.0.id', $inactiva->id)->where('viviendas.0.activa', false));

    ($this->registrar)(['vivienda_id' => $inactiva->id])->assertSessionHasNoErrors();

    expect(Ajuste::query()->sole()->vivienda_id)->toBe($inactiva->id)
        ->and(($this->saldo)($inactiva))->toBe('150.00');
});

test('a housing unit of another residencial cannot be corrected', function () {
    $ajena = ($this->vivienda)('Z-01', residencial: $this->otro);

    ($this->registrar)(['vivienda_id' => $ajena->id])->assertSessionHasErrors('vivienda_id');

    expect(fn () => app(AjusteService::class)->registrar($this->presidente, $ajena->id, AjusteService::AUMENTA, '100', 'Corrección de otro residencial'))
        ->toThrow(ValidationException::class);

    expect(Ajuste::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('each correction is audited with the balance before and after', function () {
    ($this->registrar)(['sentido' => AjusteService::DISMINUYE, 'monto' => '120'], $this->tesorero)->assertSessionHasNoErrors();

    $ajuste = Ajuste::query()->sole();
    $auditoria = Auditoria::query()->where('accion', 'ajuste_registrado')->sole();

    expect($auditoria->modulo)->toBe('ajustes')
        ->and($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->entidad)->toBe('ajustes')
        ->and($auditoria->entidad_id)->toBe($ajuste->id)
        ->and($auditoria->motivo)->toBe('Cuota de octubre registrada con un monto menor')
        ->and($auditoria->valor_anterior)->toBe(['saldo' => '500.00'])
        ->and($auditoria->valor_nuevo)->toBe([
            'vivienda_id' => $this->casa->id,
            'tipo' => Ajuste::CORRECCION,
            'monto' => '-120.00',
            'fecha' => '2026-11-21',
            'saldo' => '380.00',
        ]);
});

// Estado de cuenta

test('the correction appears as its own line in the account statement', function () {
    ($this->registrar)(['sentido' => AjusteService::DISMINUYE, 'monto' => '75'])->assertSessionHasNoErrors();
    ($this->registrar)(['monto' => '40', 'motivo' => 'Recargo omitido en el cobro de octubre'], $this->tesorero)->assertSessionHasNoErrors();

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.estado-cuenta.show', $this->casa))
        ->assertInertia(function (AssertableInertia $page) {
            $page->where('estado.cuadra', true)
                ->where('estado.totales.ajustes', '-35.00')
                ->where('estado.totales.saldo', '465.00');

            $ajustes = collect($page->toArray()['props']['estado']['movimientos'])->where('clase', 'ajuste')->values();

            expect($ajustes)->toHaveCount(2)
                ->and($ajustes[0])->toMatchArray([
                    'fecha' => '2026-11-21',
                    'concepto' => 'Corrección',
                    'motivo' => 'Cuota de octubre registrada con un monto menor',
                    'autorizado_por' => 'Paula Presidenta',
                    'debe' => null,
                    'haber' => '75.00',
                ])
                ->and($ajustes[1])->toMatchArray([
                    'concepto' => 'Corrección',
                    'motivo' => 'Recargo omitido en el cobro de octubre',
                    'autorizado_por' => 'Tomás Tesorero',
                    'debe' => '40.00',
                    'haber' => null,
                ]);
        });
});

// Permisos

test('only the president and the treasurer can open and register corrections', function (string $codigo) {
    $usuario = ($this->usuario)($codigo);

    $this->actingAs($usuario)->get(route('finanzas.ajustes.index'))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();

    expect(Ajuste::query()->count())->toBe(0);
})->with(['fiscal', 'vocal', 'secretario', 'cobrador', 'guardia', 'residente']);

test('the president and the treasurer can open the corrections page', function () {
    foreach ([$this->presidente, $this->tesorero] as $usuario) {
        $this->actingAs($usuario)
            ->get(route('finanzas.ajustes.index', ['vivienda' => $this->casa->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finanzas/ajustes')
                ->where('detalle.vivienda.id', $this->casa->id)
                ->where('detalle.saldo', '500.00')
                ->where('detalle.ajustes', []));
    }
});

test('the technical administrator cannot register corrections', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    $this->actingAs($admin)->get(route('finanzas.ajustes.index'))->assertForbidden();
    ($this->registrar)([], $admin)->assertForbidden();

    expect(app(PermisoService::class)->deUsuario($admin))->not->toContain('ajustes.aprobar')
        ->and(Ajuste::query()->count())->toBe(0);
});

test('guests are sent to the login', function () {
    $this->get(route('finanzas.ajustes.index'))->assertRedirect(route('login'));
    $this->post(route('finanzas.ajustes.store'), ($this->datos)())->assertRedirect(route('login'));

    expect(Ajuste::query()->count())->toBe(0);
});

test('approving voidings does not allow registering corrections', function () {
    $fiscal = ($this->usuario)('fiscal');

    expect(app(PermisoService::class)->puede($fiscal, 'cobros', 'aprobar'))->toBeTrue();

    ($this->registrar)([], $fiscal)->assertForbidden();
});

test('the adjustments permission cannot be given to any other role', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    foreach (['fiscal', 'vocal', 'secretario', 'cobrador', 'guardia', 'residente'] as $codigo) {
        $this->actingAs($admin)
            ->put(route('usuarios.permisos.update', ($this->rol)($codigo)), ['permisos' => ['padron.ver', 'ajustes.aprobar']])
            ->assertSessionHasErrors('permisos');

        expect(DB::table('rol_permisos')->where('rol_id', ($this->rol)($codigo))->where('modulo', 'ajustes')->exists())->toBeFalse();
    }

    // El servicio lo rechaza aunque no se use la pantalla
    expect(fn () => app(PermisoService::class)->actualizar($admin, Rol::query()->where('codigo', 'fiscal')->sole(), ['ajustes.aprobar']))
        ->toThrow(ValidationException::class);

    // Al presidente y al tesorero sí se les puede guardar
    $this->actingAs($admin)
        ->put(route('usuarios.permisos.update', ($this->rol)('tesorero')), ['permisos' => [...app(PermisoService::class)->deRol($this->residencial->id, ($this->rol)('tesorero'))]])
        ->assertSessionHasNoErrors();

    expect(app(PermisoService::class)->deRol($this->residencial->id, ($this->rol)('tesorero')))->toContain('ajustes.aprobar');
});

test('the permissions screen blocks the adjustments permission for other roles', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    $this->actingAs($admin)
        ->get(route('usuarios.permisos.index'))
        ->assertInertia(function (AssertableInertia $page) {
            foreach ($page->toArray()['props']['roles'] as $rol) {
                $autorizado = in_array($rol['nombre'], ['Presidente', 'Tesorero'], true);

                // Bloqueado para los demás roles; marcado y obligatorio para el presidente y el tesorero
                expect($rol['no_asignables'])->toBe($autorizado ? [] : ['ajustes.aprobar'])
                    ->and($rol['obligatorios'])->toBe($autorizado ? ['ajustes.aprobar'] : []);

                if ($autorizado) {
                    expect($rol['permisos'])->toContain('ajustes.aprobar');
                }
            }
        });
});

test('the adjustments permission cannot be removed from the president nor the treasurer through the service', function (string $codigo) {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);
    $rol = Rol::query()->where('codigo', $codigo)->sole();
    $permisos = app(PermisoService::class);

    // Se intenta guardar el rol con todo lo demás pero sin ajustes.aprobar, y también sin nada
    $sinAjustes = array_values(array_diff($permisos->deRol($this->residencial->id, $rol->id), ['ajustes.aprobar']));

    $permisos->actualizar($admin, $rol, $sinAjustes);

    expect($permisos->deRol($this->residencial->id, $rol->id))->toContain('ajustes.aprobar');

    $permisos->actualizar($admin, $rol, []);

    expect($permisos->deRol($this->residencial->id, $rol->id))->toBe(['ajustes.aprobar']);

    // Quien tiene el rol sigue pudiendo registrar correcciones
    ($this->registrar)([], ($this->usuario)($codigo))->assertSessionHasNoErrors();
})->with([Rol::PRESIDENTE, 'tesorero']);

test('the adjustments permission cannot be removed from the president nor the treasurer through the permissions endpoint', function (string $codigo) {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);
    $rolId = ($this->rol)($codigo);

    // Petición manipulada: la casilla deshabilitada no llega y se envía solo otro permiso
    $this->actingAs($admin)
        ->put(route('usuarios.permisos.update', $rolId), ['permisos' => ['padron.ver']])
        ->assertSessionHasNoErrors();

    expect(app(PermisoService::class)->deRol($this->residencial->id, $rolId))->toBe(['ajustes.aprobar', 'padron.ver']);

    // Sin ningún permiso en la petición
    $this->actingAs($admin)
        ->put(route('usuarios.permisos.update', $rolId), [])
        ->assertSessionHasNoErrors();

    expect(DB::table('rol_permisos')->where('residencial_id', $this->residencial->id)->where('rol_id', $rolId)->where('modulo', 'ajustes')->where('accion', 'aprobar')->exists())->toBeTrue();
})->with([Rol::PRESIDENTE, 'tesorero']);

test('a permission row inserted by hand for another role still does not allow corrections', function () {
    $fiscal = ($this->usuario)('fiscal');

    DB::table('rol_permisos')->insert([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('fiscal'),
        'modulo' => 'ajustes',
        'accion' => 'aprobar',
    ]);

    $this->actingAs($fiscal)->get(route('finanzas.ajustes.index'))->assertForbidden();
    ($this->registrar)([], $fiscal)->assertForbidden();

    expect(Ajuste::query()->count())->toBe(0);
});

test('an unauthorized role with a hand inserted permission gets 403 before field validation', function () {
    $fiscal = ($this->usuario)('fiscal');

    DB::table('rol_permisos')->insert([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('fiscal'),
        'modulo' => 'ajustes',
        'accion' => 'aprobar',
    ]);

    // Datos inválidos: si la validación corriera primero devolvería errores de campo
    ($this->registrar)(['monto' => '', 'motivo' => 'corto', 'sentido' => 'otro', 'vivienda_id' => ''], $fiscal)
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors();

    expect(Ajuste::query()->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'ajuste_registrado')->exists())->toBeFalse();
});

test('the technical administrator also gets 403 before field validation', function () {
    ($this->registrar)(['monto' => ''], ($this->usuario)(Rol::ADMIN_TECNICO))
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors();
});

test('only the president and the treasurer get the permission by default', function () {
    foreach (PermisoService::PREDETERMINADOS as $codigo => $permisos) {
        expect(in_array('ajustes.aprobar', $permisos, true))->toBe(in_array($codigo, [Rol::PRESIDENTE, 'tesorero'], true));
    }

    $conPermiso = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('rol_permisos.residencial_id', $this->residencial->id)
        ->where('modulo', 'ajustes')
        ->pluck('roles.codigo')
        ->sort()
        ->values()
        ->all();

    expect($conPermiso)->toBe([Rol::PRESIDENTE, 'tesorero']);
});

test('the migration gives the permission to the president and the treasurer of existing residenciales', function () {
    $migracion = require database_path('migrations/2026_11_22_100001_asignar_aprobacion_de_ajustes.php');

    // Simula residenciales creados antes de este permiso
    DB::table('rol_permisos')->where('modulo', 'ajustes')->delete();

    $migracion->up();

    foreach ([$this->residencial, $this->otro] as $residencial) {
        $codigos = DB::table('rol_permisos')
            ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
            ->where('rol_permisos.residencial_id', $residencial->id)
            ->where('modulo', 'ajustes')
            ->where('accion', 'aprobar')
            ->pluck('roles.codigo')
            ->sort()
            ->values()
            ->all();

        expect($codigos)->toBe([Rol::PRESIDENTE, 'tesorero']);
    }

    // Ejecutarla otra vez no duplica
    $migracion->up();

    expect(DB::table('rol_permisos')->where('modulo', 'ajustes')->count())->toBe(4);

    $migracion->down();

    expect(DB::table('rol_permisos')->where('modulo', 'ajustes')->exists())->toBeFalse();
});

// Sin efectos en cuotas, pagos ni mora

test('a correction does not change charges, payments, applications, mora or credit', function (string $sentido) {
    $foto = fn (): array => [
        'cargos' => DB::table('cargos')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        'pagos' => DB::table('pagos')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        'aplicaciones' => DB::table('pago_aplicaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        'ingresos' => DB::table('ingresos')->count(),
        'cajas' => DB::table('cajas')->count(),
        'pendientes' => app(CargosPendientes::class)->pendientesDeVivienda($this->casa->id)->all(),
        'mora' => app(MoraService::class)->estadoDe($this->residencial->id, $this->casa->id),
        'saldo_disponible' => app(PagoRepositorio::class)->conSaldoDisponible($this->residencial->id)->modelKeys(),
    ];

    $antes = $foto();

    ($this->registrar)(['sentido' => $sentido, 'monto' => '900'])->assertSessionHasNoErrors();

    expect($foto())->toBe($antes);

    // Una generación posterior no usa la corrección como saldo a favor
    app(GeneracionCargosService::class)->generarAhora($this->tesorero);

    expect(DB::table('pago_aplicaciones')->where('origen', 'saldo_a_favor')->exists())->toBeFalse()
        ->and(Auditoria::query()->where('accion', 'saldo_favor_aplicado')->exists())->toBeFalse();
})->with([AjusteService::AUMENTA, AjusteService::DISMINUYE]);
