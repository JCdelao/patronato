<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Persistencia\Excepciones\CobroDeCajaCerrada;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\PagoRepositorio;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
        ...$datos,
    ]);

    $this->cobrador = ($this->usuario)('cobrador', null, ['name' => 'Marta Cobradora']);
    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);

    $this->casa = Vivienda::query()->create([
        'residencial_id' => $this->residencial->id,
        'codigo' => 'A-01',
        'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->abrirCaja = fn (User $cobrador): Caja => Caja::query()->create([
        'residencial_id' => $cobrador->residencial_id,
        'cobrador_id' => $cobrador->id,
        'estado' => Caja::ABIERTA,
        'abierta_en' => now(),
    ]);

    $recibo = 0;

    // Cobro en efectivo ya registrado en una caja
    $this->cobro = function (Caja $caja, string $monto, string $estado = Pago::CONFIRMADO) use (&$recibo): Pago {
        $recibo++;

        return Pago::query()->create([
            'residencial_id' => $caja->residencial_id,
            'vivienda_id' => $this->casa->id,
            'forma_pago' => Pago::EFECTIVO,
            'estado' => $estado,
            'origen' => 'sistema',
            'monto' => $monto,
            'fecha_pago' => '2026-11-14',
            'numero_recibo' => (string) $recibo,
            'caja_id' => $caja->id,
            'registrado_por' => $caja->cobrador_id,
        ]);
    };

    $this->caja = ($this->abrirCaja)($this->cobrador);
    $this->primero = ($this->cobro)($this->caja, '500.00');
    $this->segundo = ($this->cobro)($this->caja, '250.50');

    $this->cerrar = fn (Caja $caja, array $datos) => $this->post(route('finanzas.cajas.cerrar', $caja), $datos);
});

test('the first payment of a collector opens a box and it is audited', function () {
    $nuevo = ($this->usuario)('cobrador');

    $cargo = DB::table('cargos')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'concepto_id' => DB::table('conceptos_cobro')->insertGetId([
            'residencial_id' => $this->residencial->id,
            'nombre' => 'Cuota mensual',
            'tipo' => 'recurrente',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'periodo' => '2026-01-01',
        'monto' => '500.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->app->instance(CargosPendientes::class, new class($cargo) implements CargosPendientes
    {
        public function __construct(private int $cargo) {}

        public function pendientesDeVivienda(int $viviendaId): Collection
        {
            return collect([['cargo_id' => $this->cargo, 'periodo' => '2026-01-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00', 'pendiente' => '500.00']]);
        }
    });

    $this->actingAs($nuevo)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$cargo]])
        ->assertSessionHasNoErrors();

    $caja = Caja::query()->where('cobrador_id', $nuevo->id)->sole();
    $registro = Auditoria::query()->where('accion', 'caja_abierta')->sole();

    expect($caja->estado)->toBe(Caja::ABIERTA)
        ->and(Pago::query()->where('registrado_por', $nuevo->id)->sole()->caja_id)->toBe($caja->id)
        ->and($registro->modulo)->toBe('cajas')
        ->and($registro->entidad_id)->toBe($caja->id);
});

test('the list shows the open boxes of each collector with their total', function () {
    $otroCobrador = ($this->usuario)('cobrador', null, ['name' => 'Luis Cobrador']);
    ($this->cobro)(($this->abrirCaja)($otroCobrador), '100.00');

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.cajas.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/cajas')
            ->has('abiertas', 2)
            ->where('abiertas.0.cobrador', 'Marta Cobradora')
            ->where('abiertas.0.cobros', 2)
            ->where('abiertas.0.total_registrado', '750.50')
            ->where('abiertas.1.cobrador', 'Luis Cobrador')
            ->where('abiertas.1.total_registrado', '100.00')
            ->has('cerradas', 0));
});

test('the closing shows the included payments, the registered total, the delivered amount and the difference', function () {
    $this->actingAs($this->tesorero)
        ->get(route('finanzas.cajas.show', $this->caja))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/caja')
            ->where('caja.estado', 'abierta')
            ->where('caja.cobrador', 'Marta Cobradora')
            ->has('caja.cobros', 2)
            ->where('caja.cobros.0.numero_recibo', '1')
            ->where('caja.cobros.0.vivienda', 'A-01')
            ->where('caja.cobros.0.monto', '500.00')
            ->where('caja.cobros.1.monto', '250.50')
            ->where('caja.total_registrado', '750.50')
            ->where('caja.monto_entregado', null)
            ->where('caja.puede_cerrar', true));

    ($this->cerrar)($this->caja, ['monto_entregado' => '700', 'justificacion' => 'Faltan 50.50 de un vuelto'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.cajas.show', $this->caja));

    $this->get(route('finanzas.cajas.show', $this->caja))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('caja.estado', 'cerrada')
            ->where('caja.total_registrado', '750.50')
            ->where('caja.monto_entregado', '700.00')
            ->where('caja.diferencia', '-50.50')
            ->where('caja.justificacion', 'Faltan 50.50 de un vuelto')
            ->where('caja.cerrada_por', 'Tomás Tesorero')
            ->where('caja.puede_cerrar', false));
});

test('a box without difference closes without justification and is audited', function () {
    $this->travelTo('2026-11-14 23:00:00');

    $this->actingAs($this->tesorero);
    ($this->cerrar)($this->caja, ['monto_entregado' => '750.50'])->assertSessionHasNoErrors();

    $caja = $this->caja->fresh();
    $registro = Auditoria::query()->where('accion', 'caja_cerrada')->sole();

    expect($caja->estado)->toBe(Caja::CERRADA)
        ->and($caja->cerrada_por)->toBe($this->tesorero->id)
        ->and($caja->cerrada_en->format('Y-m-d H:i'))->toBe('2026-11-14 23:00')
        ->and($caja->total_registrado)->toBe('750.50')
        ->and($caja->monto_entregado)->toBe('750.50')
        ->and($caja->diferencia)->toBe('0.00')
        ->and($caja->justificacion)->toBeNull()
        ->and($registro->modulo)->toBe('cajas')
        ->and($registro->user_id)->toBe($this->tesorero->id)
        ->and($registro->entidad_id)->toBe($this->caja->id)
        ->and($registro->valor_anterior['estado'])->toBe('abierta')
        ->and($registro->valor_nuevo['cobros'])->toBe(2)
        ->and($registro->valor_nuevo['diferencia'])->toBe('0.00');
});

test('a difference requires a justification', function (string $entregado, string $mensaje, string $diferencia) {
    $this->actingAs($this->tesorero);

    ($this->cerrar)($this->caja, ['monto_entregado' => $entregado, 'justificacion' => '   '])
        ->assertSessionHasErrors(['justificacion' => $mensaje]);

    expect($this->caja->fresh()->estado)->toBe(Caja::ABIERTA);

    ($this->cerrar)($this->caja, ['monto_entregado' => $entregado, 'justificacion' => 'Revisado con el cobrador'])
        ->assertSessionHasNoErrors();

    expect($this->caja->fresh()->diferencia)->toBe($diferencia);
})->with([
    'shortage' => ['700', 'Hay un faltante de L 50.50. Escribe la justificación.', '-50.50'],
    'surplus' => ['800', 'Hay un sobrante de L 49.50. Escribe la justificación.', '49.50'],
]);

test('the delivered amount must be valid and cannot be negative', function (mixed $entregado) {
    $this->actingAs($this->tesorero);

    ($this->cerrar)($this->caja, ['monto_entregado' => $entregado, 'justificacion' => 'x'])
        ->assertSessionHasErrors('monto_entregado');

    expect($this->caja->fresh()->estado)->toBe(Caja::ABIERTA);
})->with([
    'negative' => ['-1'],
    'missing' => [''],
    'not a number' => ['abc'],
    'three decimals' => ['10.555'],
]);

test('a box with no payments closes with a total of zero', function () {
    $vacia = ($this->abrirCaja)(($this->usuario)('cobrador'));

    $this->actingAs($this->tesorero);
    ($this->cerrar)($vacia, ['monto_entregado' => '0'])->assertSessionHasNoErrors();

    expect($vacia->fresh())
        ->estado->toBe(Caja::CERRADA)
        ->total_registrado->toBe('0.00')
        ->diferencia->toBe('0.00');
});

test('voided payments do not count towards the registered total', function () {
    ($this->cobro)($this->caja, '99.00', Pago::ANULADO);

    $this->actingAs($this->tesorero);
    ($this->cerrar)($this->caja, ['monto_entregado' => '750.50'])->assertSessionHasNoErrors();

    expect($this->caja->fresh()->total_registrado)->toBe('750.50');
});

test('the treasurer cannot close their own box', function () {
    $propia = ($this->abrirCaja)($this->tesorero);
    ($this->cobro)($propia, '100.00');

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.cajas.show', $propia))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('caja.puede_cerrar', false));

    ($this->cerrar)($propia, ['monto_entregado' => '100'])
        ->assertSessionHasErrors(['caja' => 'No puedes cerrar tu propia caja. Otro usuario con permiso de cierre debe hacerlo.']);

    expect($propia->fresh()->estado)->toBe(Caja::ABIERTA);

    // Otro usuario con el permiso sí la cierra
    $this->actingAs(($this->usuario)('tesorero'));
    ($this->cerrar)($propia, ['monto_entregado' => '100'])->assertSessionHasNoErrors();

    expect($propia->fresh()->estado)->toBe(Caja::CERRADA);
});

test('a closed box cannot be closed again', function () {
    $this->actingAs($this->tesorero);
    ($this->cerrar)($this->caja, ['monto_entregado' => '750.50'])->assertSessionHasNoErrors();

    ($this->cerrar)($this->caja, ['monto_entregado' => '1', 'justificacion' => 'Otra vez'])
        ->assertSessionHasErrors(['caja' => 'Esta caja ya está cerrada.']);

    expect($this->caja->fresh()->monto_entregado)->toBe('750.50')
        ->and(Auditoria::query()->where('accion', 'caja_cerrada')->count())->toBe(1);
});

test('payments of a closed box cannot be edited and are corrected with a voiding', function () {
    $this->actingAs($this->tesorero);
    ($this->cerrar)($this->caja, ['monto_entregado' => '750.50'])->assertSessionHasNoErrors();

    $pagos = app(PagoRepositorio::class);

    expect(fn () => $pagos->actualizar($this->primero->fresh(), ['monto' => '1.00']))->toThrow(CobroDeCajaCerrada::class)
        ->and(fn () => $pagos->actualizar($this->primero->fresh(), ['vivienda_id' => $this->casa->id + 1]))->toThrow(CobroDeCajaCerrada::class)
        ->and(fn () => $pagos->actualizar($this->primero->fresh(), ['estado' => 'rechazado']))->toThrow(CobroDeCajaCerrada::class)
        ->and(fn () => $this->primero->fresh()->delete())->toThrow(CobroDeCajaCerrada::class);

    expect($this->primero->fresh()->monto)->toBe('500.00');

    // La anulación (HU-23) sí se puede guardar
    $pagos->actualizar($this->primero->fresh(), [
        'estado' => Pago::ANULADO,
        'anulado_por' => $this->tesorero->id,
        'anulado_en' => now(),
        'motivo_anulacion' => 'Recibo duplicado',
    ]);

    expect($this->primero->fresh()->estado)->toBe(Pago::ANULADO);
});

test('payments of an open box can still change', function () {
    app(PagoRepositorio::class)->actualizar($this->primero, ['monto' => '450.00']);

    expect($this->primero->fresh()->monto)->toBe('450.00');
});

test('only users with the close box permission can use it', function () {
    $rutas = [
        fn () => $this->get(route('finanzas.cajas.index')),
        fn () => $this->get(route('finanzas.cajas.show', $this->caja)),
        fn () => ($this->cerrar)($this->caja, ['monto_entregado' => '750.50']),
    ];

    foreach ($rutas as $ruta) {
        $ruta()->assertRedirect(route('login'));
    }

    foreach (['cobrador', 'vocal', 'fiscal', Rol::PRESIDENTE] as $rol) {
        $this->actingAs(($this->usuario)($rol));

        foreach ($rutas as $ruta) {
            $ruta()->assertForbidden();
        }
    }

    expect($this->caja->fresh()->estado)->toBe(Caja::ABIERTA);

    $this->actingAs($this->tesorero)->get(route('finanzas.cajas.index'))->assertOk();
});

test('existing residentials give the treasurer the close box permission', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'cajas')->delete();

    $tesorero = ($this->usuario)('tesorero', $antiguo);

    $this->actingAs($tesorero)->get(route('finanzas.cajas.index'))->assertForbidden();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_14_100001_asignar_cierre_de_caja_al_tesorero.php');
    $migracion->up();
    $migracion->up();

    expect(DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'cajas')->count())->toBe(1);

    $this->actingAs($tesorero)->get(route('finanzas.cajas.index'))->assertOk();
});

test('boxes of another residential are not listed and are denied', function () {
    $ajeno = ($this->usuario)('tesorero', $this->otro);

    $this->actingAs($ajeno)
        ->get(route('finanzas.cajas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('abiertas', 0)->has('cerradas', 0));

    $this->get(route('finanzas.cajas.show', $this->caja))->assertForbidden();
    ($this->cerrar)($this->caja, ['monto_entregado' => '750.50'])->assertForbidden();

    expect($this->caja->fresh()->estado)->toBe(Caja::ABIERTA)
        ->and(Auditoria::query()->withoutGlobalScopes()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(2);
});

test('a collector with an open box in another residential gets a clear message', function () {
    $cargo = DB::table('cargos')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'concepto_id' => DB::table('conceptos_cobro')->insertGetId([
            'residencial_id' => $this->residencial->id,
            'nombre' => 'Cuota mensual',
            'tipo' => 'recurrente',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'periodo' => '2026-01-01',
        'monto' => '500.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->app->instance(CargosPendientes::class, new class($cargo) implements CargosPendientes
    {
        public function __construct(private int $cargo) {}

        public function pendientesDeVivienda(int $viviendaId): Collection
        {
            return collect([['cargo_id' => $this->cargo, 'periodo' => '2026-01-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00', 'pendiente' => '500.00']]);
        }
    });

    $movido = ($this->usuario)('cobrador');
    Caja::query()->create(['residencial_id' => $this->otro->id, 'cobrador_id' => $movido->id, 'estado' => Caja::ABIERTA, 'abierta_en' => now()]);

    $this->actingAs($movido)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$cargo]])
        ->assertSessionHasErrors(['caja' => 'Tienes una caja abierta en otro residencial. Pide que la cierren antes de cobrar aquí.']);

    expect(Pago::query()->where('registrado_por', $movido->id)->count())->toBe(0);
});
