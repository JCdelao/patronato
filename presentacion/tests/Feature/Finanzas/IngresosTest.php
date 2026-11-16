<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Negocio\Services\IngresoService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Ingreso;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\SolicitudAnulacion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    // 9:00 en Honduras
    $this->travelTo('2026-11-15 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
        ...$datos,
    ]);

    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);

    $this->categoria = fn (string $nombre = 'Alquiler de áreas comunes', ?Residencial $residencial = null, string $tipo = CategoriaFinanciera::INGRESO): int => (int) CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('tipo', $tipo)
        ->where('nombre', $nombre)
        ->value('id');

    $this->datos = fn (array $cambios = []): array => [
        'fecha' => '2026-11-14',
        'concepto' => 'Alquiler del salón para un cumpleaños',
        'categoria_id' => ($this->categoria)(),
        'monto' => '1200.00',
        'numero_comprobante' => 'R-0077',
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->post(route('finanzas.ingresos.store'), ($this->datos)($cambios));

    $this->ingreso = fn (?Residencial $residencial = null, array $datos = []): Ingreso => Ingreso::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'categoria_id' => ($this->categoria)('Donaciones', $residencial),
        'fecha' => '2026-11-10',
        'concepto' => 'Donación para la fiesta navideña',
        'monto' => '3000.00',
        'estado' => Ingreso::VIGENTE,
        'registrado_por' => $this->tesorero->id,
        ...$datos,
    ]);

    $this->anular = fn (Ingreso $ingreso, ?User $usuario = null, string $motivo = 'Se registró en el mes equivocado.') => $this->actingAs($usuario ?? $this->tesorero)
        ->post(route('finanzas.ingresos.anular', $ingreso), ['motivo' => $motivo]);

    // Cobro en efectivo real, con su vivienda, su cargo y los meses pendientes simulados
    $this->cargosPendientes = new class implements CargosPendientes
    {
        /** @var array<int, array<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>> */
        public array $porVivienda = [];

        public function pendientesDeVivienda(int $viviendaId): Collection
        {
            return collect($this->porVivienda[$viviendaId] ?? []);
        }
    };
    $this->app->instance(CargosPendientes::class, $this->cargosPendientes);

    $this->cobrar = function (string $codigo = 'A-01', string $monto = '500.00'): Pago {
        $vivienda = Vivienda::query()->create([
            'residencial_id' => $this->residencial->id,
            'codigo' => $codigo,
            'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
            'estado_ocupacion' => 'ocupada',
            'activa' => true,
        ]);

        $concepto = DB::table('conceptos_cobro')->where('residencial_id', $this->residencial->id)->value('id')
            ?? DB::table('conceptos_cobro')->insertGetId([
                'residencial_id' => $this->residencial->id,
                'nombre' => 'Cuota mensual',
                'tipo' => 'recurrente',
                'es_cuota_unificada' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $cargo = DB::table('cargos')->insertGetId([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $vivienda->id,
            'concepto_id' => $concepto,
            'periodo' => '2026-11-01',
            'monto' => $monto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargosPendientes->porVivienda[$vivienda->id] = [[
            'cargo_id' => $cargo,
            'periodo' => '2026-11-01',
            'concepto' => 'Cuota mensual',
            'monto' => $monto,
            'pendiente' => $monto,
        ]];

        $this->cobrador ??= ($this->usuario)('cobrador', null, ['name' => 'Marta Cobradora']);

        $this->actingAs($this->cobrador)
            ->post(route('finanzas.cobros.store'), ['vivienda_id' => $vivienda->id, 'monto' => $monto, 'cargos' => [$cargo]])
            ->assertSessionHasNoErrors();

        return Pago::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    };

    $this->anularPago = function (Pago $pago, string $motivo = 'Se registró en la vivienda equivocada.'): void {
        $this->actingAs($this->tesorero)
            ->post(route('finanzas.pagos.anulacion', $pago), ['motivo' => $motivo])
            ->assertSessionHasNoErrors();

        $solicitud = SolicitudAnulacion::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAs(($this->usuario)('presidente', null, ['name' => 'Paula Presidenta']))
            ->post(route('finanzas.anulaciones.aprobar', $solicitud))
            ->assertSessionHasNoErrors();
    };
});

test('a new residential starts with the initial income categories', function () {
    $categorias = CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', $this->residencial->id)
        ->where('tipo', CategoriaFinanciera::INGRESO)
        ->orderBy('id')
        ->pluck('nombre')
        ->all();

    expect($categorias)->toBe(CategoriaFinancieraService::INGRESOS_PREDETERMINADOS);
});

test('the treasurer registers another income with all its fields', function () {
    $respuesta = ($this->registrar)()->assertSessionHasNoErrors();

    $ingreso = Ingreso::query()->sole();

    $respuesta->assertRedirect(route('finanzas.ingresos.show', $ingreso));

    expect($ingreso->residencial_id)->toBe($this->residencial->id)
        ->and($ingreso->fecha->toDateString())->toBe('2026-11-14')
        ->and($ingreso->concepto)->toBe('Alquiler del salón para un cumpleaños')
        ->and($ingreso->categoria_id)->toBe(($this->categoria)())
        ->and($ingreso->monto)->toBe('1200.00')
        ->and($ingreso->numero_comprobante)->toBe('R-0077')
        ->and($ingreso->pago_id)->toBeNull()
        ->and($ingreso->estado)->toBe(Ingreso::VIGENTE)
        ->and($ingreso->registrado_por)->toBe($this->tesorero->id);

    $auditoria = Auditoria::query()->where('accion', 'ingreso_registrado')->sole();

    expect($auditoria->modulo)->toBe('tesoreria')
        ->and($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->entidad_id)->toBe($ingreso->id)
        ->and($auditoria->valor_nuevo['monto'])->toBe('1200.00');
});

test('the receipt number of another income is optional', function () {
    ($this->registrar)(['numero_comprobante' => ''])->assertSessionHasNoErrors();

    expect(Ingreso::query()->sole()->numero_comprobante)->toBeNull();
});

test('date, concept, category and amount are required', function (string $campo) {
    ($this->registrar)([$campo => ''])->assertSessionHasErrors($campo);

    expect(Ingreso::query()->count())->toBe(0);
})->with(['fecha', 'concepto', 'categoria_id', 'monto']);

test('invalid values are rejected', function (string $campo, mixed $valor) {
    ($this->registrar)([$campo => $valor])->assertSessionHasErrors($campo);

    expect(Ingreso::query()->count())->toBe(0);
})->with([
    'zero amount' => ['monto', '0'],
    'negative amount' => ['monto', '-50'],
    'three decimals' => ['monto', '10.555'],
    'future date' => ['fecha', '2026-11-16'],
]);

test('only an active income category of the same residential can be used', function (string $caso) {
    $categoria = match ($caso) {
        'another residential' => ($this->categoria)('Alquiler de áreas comunes', $this->otro),
        'expense category' => ($this->categoria)('Vigilancia', null, CategoriaFinanciera::EGRESO),
        'inactive category' => CategoriaFinanciera::query()->create([
            'residencial_id' => $this->residencial->id,
            'tipo' => CategoriaFinanciera::INGRESO,
            'nombre' => 'Rifas',
            'activa' => false,
        ])->id,
    };

    ($this->registrar)(['categoria_id' => $categoria])->assertSessionHasErrors('categoria_id');

    expect(Ingreso::query()->count())->toBe(0);
})->with(['another residential', 'expense category', 'inactive category']);

test('the dues category cannot be used by hand so a fee is not counted twice', function () {
    ($this->registrar)(['categoria_id' => ($this->categoria)(CategoriaFinancieraService::CUOTAS)])
        ->assertSessionHasErrors('categoria_id');

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.ingresos.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/ingreso-nuevo')
            ->has('categorias', count(CategoriaFinancieraService::INGRESOS_PREDETERMINADOS) - 1)
            ->where('categorias', fn ($categorias) => ! collect($categorias)->contains('nombre', CategoriaFinancieraService::CUOTAS))
            ->where('hoy', '2026-11-15'));

    expect(Ingreso::query()->count())->toBe(0);
});

test('the treasurer adds a new income category and can use it right away', function () {
    $this->actingAs($this->tesorero)
        ->from(route('finanzas.ingresos.create'))
        ->post(route('finanzas.categorias-ingreso.store'), ['nombre' => ' Rifas '])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.ingresos.create'));

    $nueva = ($this->categoria)('Rifas');

    expect($nueva)->toBeGreaterThan(0);

    ($this->registrar)(['categoria_id' => $nueva])->assertSessionHasNoErrors();

    $auditoria = Auditoria::query()->where('accion', 'categoria_creada')->sole();

    expect($auditoria->valor_nuevo)->toBe(['tipo' => 'ingreso', 'nombre' => 'Rifas']);
});

test('an income category name cannot be repeated but may match an expense category', function () {
    $this->actingAs($this->tesorero)
        ->post(route('finanzas.categorias-ingreso.store'), ['nombre' => 'multas'])
        ->assertSessionHasErrors(['nombre' => 'Ya existe una categoría con ese nombre.']);

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.categorias-ingreso.store'), ['nombre' => 'Vigilancia'])
        ->assertSessionHasNoErrors();
});

test('a confirmed cash payment enters automatically as a dues income', function () {
    $pago = ($this->cobrar)('A-01', '500.00');

    $ingreso = Ingreso::query()->sole();

    expect($ingreso->pago_id)->toBe($pago->id)
        ->and($ingreso->categoria_id)->toBe(($this->categoria)(CategoriaFinancieraService::CUOTAS))
        ->and($ingreso->fecha->toDateString())->toBe($pago->fecha_pago->toDateString())
        ->and($ingreso->monto)->toBe('500.00')
        ->and($ingreso->numero_comprobante)->toBe($pago->numero_recibo)
        ->and($ingreso->concepto)->toBe("Cuota · recibo N.º {$pago->numero_recibo} · vivienda A-01")
        ->and($ingreso->estado)->toBe(Ingreso::VIGENTE)
        ->and($ingreso->registrado_por)->toBe($this->cobrador->id);

    $auditoria = Auditoria::query()->where('accion', 'ingreso_registrado')->sole();

    expect($auditoria->user_id)->toBe($this->cobrador->id)
        ->and($auditoria->valor_nuevo['pago_id'])->toBe($pago->id);
});

test('if the income cannot be saved the payment is not saved either', function () {
    $this->mock(IngresoService::class)
        ->shouldReceive('registrarDeCobro')
        ->andThrow(new RuntimeException('Falla al guardar el ingreso'));

    $vivienda = Vivienda::query()->create([
        'residencial_id' => $this->residencial->id,
        'codigo' => 'A-09',
        'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);
    $concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'es_cuota_unificada' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $cargo = DB::table('cargos')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $vivienda->id,
        'concepto_id' => $concepto,
        'periodo' => '2026-11-01',
        'monto' => '500.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->cargosPendientes->porVivienda[$vivienda->id] = [['cargo_id' => $cargo, 'periodo' => '2026-11-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00', 'pendiente' => '500.00']];

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(($this->usuario)('cobrador'))
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $vivienda->id, 'monto' => '500.00', 'cargos' => [$cargo]]))
        ->toThrow(RuntimeException::class, 'Falla al guardar el ingreso');

    expect(Pago::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Ingreso::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('cajas')->count())->toBe(0);
});

test('the dues income is created even when the dues category was removed', function () {
    DB::table('categorias_financieras')->where('residencial_id', $this->residencial->id)->where('nombre', CategoriaFinancieraService::CUOTAS)->delete();

    ($this->cobrar)();

    expect(Ingreso::query()->sole()->categoria->nombre)->toBe(CategoriaFinancieraService::CUOTAS);
});

test('approving the voiding of a payment voids its income with the same reason', function () {
    $pago = ($this->cobrar)();

    ($this->anularPago)($pago, 'Se registró en la vivienda equivocada.');

    $ingreso = Ingreso::query()->sole();

    expect($pago->fresh()->estado)->toBe(Pago::ANULADO)
        ->and($ingreso->estado)->toBe(Ingreso::ANULADO)
        ->and($ingreso->motivo_anulacion)->toBe('Se registró en la vivienda equivocada.');

    $auditoria = Auditoria::query()->where('accion', 'ingreso_anulado')->sole();

    expect($auditoria->entidad_id)->toBe($ingreso->id)
        ->and($auditoria->motivo)->toBe('Se registró en la vivienda equivocada.');
});

test('a voiding request that is not approved keeps the income', function () {
    $pago = ($this->cobrar)();

    $this->actingAs($this->tesorero)->post(route('finanzas.pagos.anulacion', $pago), ['motivo' => 'Revisar este cobro, parece duplicado.']);

    expect(Ingreso::query()->sole()->estado)->toBe(Ingreso::VIGENTE);
});

test('a dues income cannot be voided by hand', function () {
    ($this->cobrar)();
    $ingreso = Ingreso::query()->sole();

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.ingresos.show', $ingreso))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ingreso.puede_anular', false)
            ->where('ingreso.pago.numero_recibo', $ingreso->numero_comprobante)
            ->where('ingreso.pago.visible', true));

    ($this->anular)($ingreso)->assertSessionHasErrors('motivo');

    expect($ingreso->fresh()->estado)->toBe(Ingreso::VIGENTE);
});

test('the treasurer voids another income with a reason that stays in the audit log', function () {
    $ingreso = ($this->ingreso)();

    ($this->anular)($ingreso)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.ingresos.show', $ingreso));

    $ingreso->refresh();

    expect($ingreso->estado)->toBe(Ingreso::ANULADO)
        ->and($ingreso->motivo_anulacion)->toBe('Se registró en el mes equivocado.')
        ->and(Ingreso::query()->count())->toBe(1);

    $auditoria = Auditoria::query()->where('accion', 'ingreso_anulado')->sole();

    expect($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->motivo)->toBe('Se registró en el mes equivocado.')
        ->and($auditoria->valor_anterior)->toBe(['estado' => 'vigente'])
        ->and($auditoria->valor_nuevo)->toBe(['estado' => 'anulado']);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.ingresos.show', $ingreso))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/ingreso')
            ->where('ingreso.anulacion.anulado_por', 'Tomás Tesorero')
            ->where('ingreso.puede_anular', false));
});

test('an income cannot be voided twice or without a reason, and cannot be deleted', function () {
    $ingreso = ($this->ingreso)();

    ($this->anular)($ingreso, null, '')->assertSessionHasErrors('motivo');
    ($this->anular)($ingreso, null, 'corto')->assertSessionHasErrors('motivo');
    ($this->anular)($ingreso)->assertSessionHasNoErrors();
    ($this->anular)($ingreso, null, 'Otro motivo para anular.')->assertSessionHasErrors(['motivo' => 'El ingreso ya está anulado.']);

    $this->actingAs($this->tesorero)->delete(route('finanzas.ingresos.show', $ingreso))->assertMethodNotAllowed();

    expect($ingreso->fresh()->motivo_anulacion)->toBe('Se registró en el mes equivocado.')
        ->and(Auditoria::query()->where('accion', 'ingreso_anulado')->count())->toBe(1);
});

test('the list shows dues and other incomes of the residential, including the voided ones, and searches them', function () {
    ($this->cobrar)('B-07');
    ($this->ingreso)(null, ['concepto' => 'Alquiler de la cancha', 'fecha' => '2026-11-12']);
    ($this->ingreso)(null, ['concepto' => 'Multa por ruido', 'estado' => Ingreso::ANULADO, 'motivo_anulacion' => 'Se perdonó la multa.']);
    ($this->ingreso)($this->otro, ['concepto' => 'Ingreso ajeno']);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.ingresos.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/ingresos')
            ->has('ingresos.data', 3)
            ->where('ingresos.data.0.de_cobro', true)
            ->where('ingresos.data.0.categoria', CategoriaFinancieraService::CUOTAS)
            ->where('ingresos.data.1.concepto', 'Alquiler de la cancha')
            ->where('ingresos.data.2.estado', 'anulado'));

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.ingresos.index', ['buscar' => 'b-07']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('ingresos.data', 1)
            ->where('ingresos.data.0.de_cobro', true));
});

test('the president and the auditor see the incomes but cannot register or void them', function (string $rol) {
    $ingreso = ($this->ingreso)();
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('finanzas.ingresos.index'))->assertOk();
    $this->actingAs($usuario)
        ->get(route('finanzas.ingresos.show', $ingreso))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ingreso.puede_anular', false));

    $this->actingAs($usuario)->get(route('finanzas.ingresos.create'))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();
    $this->actingAs($usuario)->post(route('finanzas.categorias-ingreso.store'), ['nombre' => 'Nueva'])->assertForbidden();
    ($this->anular)($ingreso, $usuario)->assertForbidden();

    expect(Ingreso::query()->count())->toBe(1)
        ->and($ingreso->fresh()->estado)->toBe(Ingreso::VIGENTE);
})->with(['presidente', 'fiscal']);

test('other roles cannot see the incomes', function (string $rol) {
    $ingreso = ($this->ingreso)();
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('finanzas.ingresos.index'))->assertForbidden();
    $this->actingAs($usuario)->get(route('finanzas.ingresos.show', $ingreso))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();
    ($this->anular)($ingreso, $usuario)->assertForbidden();
})->with(['secretario', 'vocal', 'cobrador']);

test('the incomes of another residential are denied and audited', function () {
    $ingreso = ($this->ingreso)();
    $ajeno = ($this->usuario)('tesorero', $this->otro);

    $this->actingAs($ajeno)
        ->get(route('finanzas.ingresos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('ingresos.data', 0));

    $this->actingAs($ajeno)->get(route('finanzas.ingresos.show', $ingreso))->assertForbidden();
    ($this->anular)($ingreso, $ajeno)->assertForbidden();

    expect($ingreso->fresh()->estado)->toBe(Ingreso::VIGENTE)
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(2);
});

test('existing residentials get the income categories and their earlier payments as incomes', function () {
    $confirmado = ($this->cobrar)('C-01');
    $anulado = ($this->cobrar)('C-02', '750.00');
    ($this->anularPago)($anulado, 'Cobro duplicado del mismo mes.');

    // Estado anterior a la HU-32: sin categorías de ingreso ni ingresos de cobros
    DB::table('ingresos')->delete();
    DB::table('categorias_financieras')->where('tipo', 'ingreso')->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_15_300001_crear_categorias_de_ingreso_y_registrar_cobros.php');
    $migracion->up();
    $migracion->up();

    $categorias = DB::table('categorias_financieras')
        ->where('residencial_id', $this->residencial->id)
        ->where('tipo', 'ingreso')
        ->orderBy('id')
        ->pluck('nombre')
        ->all();

    $ingresos = Ingreso::query()->withoutGlobalScopes()->orderBy('pago_id')->get();

    expect($categorias)->toBe(CategoriaFinancieraService::INGRESOS_PREDETERMINADOS)
        ->and($ingresos)->toHaveCount(2)
        ->and($ingresos[0]->pago_id)->toBe($confirmado->id)
        ->and($ingresos[0]->estado)->toBe(Ingreso::VIGENTE)
        ->and($ingresos[0]->monto)->toBe('500.00')
        ->and($ingresos[0]->concepto)->toBe("Cuota · recibo N.º {$confirmado->numero_recibo} · vivienda C-01")
        ->and($ingresos[0]->categoria_id)->toBe(($this->categoria)(CategoriaFinancieraService::CUOTAS))
        ->and($ingresos[1]->pago_id)->toBe($anulado->id)
        ->and($ingresos[1]->estado)->toBe(Ingreso::ANULADO)
        ->and($ingresos[1]->motivo_anulacion)->toBe('Cobro duplicado del mismo mes.');
});
