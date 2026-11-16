<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Services\GeneracionCargosService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\GeneracionCargos;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    Carbon::setTestNow('2026-11-15 12:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->crear = fn (string $codigo, ?Residencial $residencial = null) => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => Rol::query()->where('codigo', $codigo)->value('id'),
    ]);

    $this->tesorero = ($this->crear)('tesorero');
    $this->actingAs($this->tesorero);

    $casa = TipoVivienda::query()->where('nombre', 'Casa')->sole();
    $apartamento = TipoVivienda::query()->where('nombre', 'Apartamento')->sole();

    // Cuotas: casa L. 500 y apartamento L. 300 desde noviembre
    $this->put(route('finanzas.tipos-vivienda.update', $casa), ['nombre' => 'Casa', 'monto' => '500', 'vigente_desde' => '2026-11']);
    $this->put(route('finanzas.tipos-vivienda.update', $apartamento), ['nombre' => 'Apartamento', 'monto' => '300', 'vigente_desde' => '2026-11']);

    $this->vivienda = fn (string $codigo, TipoVivienda $tipo, bool $activa = true) => Vivienda::query()->create([
        'residencial_id' => $this->residencial->id, 'codigo' => $codigo, 'tipo_vivienda_id' => $tipo->id, 'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)('A-01', $casa);
    ($this->vivienda)('A-02', $casa, false);
    $this->exonerado = ($this->vivienda)('T-01', $apartamento);
    $this->especial = ($this->vivienda)('T-02', $apartamento);

    $especial = fn (Vivienda $vivienda, string $monto) => $this->post(route('finanzas.tarifas-especiales.store'), [
        'vivienda_id' => $vivienda->id, 'monto' => $monto, 'motivo' => 'Acuerdo de la Junta', 'vigente_desde' => '2026-11',
    ]);

    $especial($this->exonerado, '0');
    $especial($this->especial, '250');

    $concepto = fn (array $datos) => $this->post(route('finanzas.conceptos.store'), ['tipo' => 'recurrente', 'vigente_desde' => '2026-11', ...$datos]);

    $concepto(['nombre' => 'Vigilancia', 'monto' => '100']);
    $concepto(['nombre' => 'Reparación del portón', 'tipo' => 'unico', 'monto' => '1000', 'vigente_desde' => '2026-12']);
    $concepto(['nombre' => 'Agua', 'monto' => '80']);
    $this->post(route('finanzas.conceptos.desactivar', ConceptoCobro::query()->where('nombre', 'Agua')->sole()));

    $this->generar = fn () => app(GeneracionCargosService::class)->generarAhora($this->tesorero);

    // Cargos como "vivienda · concepto · monto"
    $this->cargos = fn (?string $periodo = null) => Cargo::query()
        ->with('concepto')
        ->when($periodo, fn ($query, string $fecha) => $query->whereDate('periodo', $fecha))
        ->get()
        ->map(fn (Cargo $cargo) => Vivienda::query()->find($cargo->vivienda_id)->codigo.' · '.$cargo->concepto->nombre.' · '.$cargo->monto)
        ->sort()
        ->values()
        ->all();
});

afterEach(fn () => Carbon::setTestNow());

test('the charges of the month follow the fee of each type, the special fees and the active concepts', function () {
    expect(($this->generar)())->toBe(5)
        ->and(($this->cargos)('2026-11-01'))->toBe([
            'A-01 · Cuota mensual · 500.00',
            'A-01 · Vigilancia · 100.00',
            'T-01 · Vigilancia · 100.00',
            'T-02 · Cuota mensual · 250.00',
            'T-02 · Vigilancia · 100.00',
        ]);
});

test('running the generation again does not duplicate charges', function () {
    ($this->generar)();

    expect(($this->generar)())->toBe(0)
        ->and(Cargo::query()->count())->toBe(5);

    // Una vivienda registrada después recibe solo sus cargos
    ($this->vivienda)('A-03', TipoVivienda::query()->where('nombre', 'Casa')->sole());

    expect(($this->generar)())->toBe(2)
        ->and(Cargo::query()->count())->toBe(7);
});

test('a one-time concept is charged only in the month it applies', function () {
    Carbon::setTestNow('2026-12-02 12:00:00');
    ($this->generar)();

    Carbon::setTestNow('2027-01-02 12:00:00');
    ($this->generar)();

    expect(($this->cargos)('2026-12-01'))->toContain('A-01 · Reparación del portón · 1000.00', 'T-01 · Reparación del portón · 1000.00')
        ->and(collect(($this->cargos)('2027-01-01'))->filter(fn (string $cargo) => str_contains($cargo, 'Reparación'))->all())->toBe([]);
});

test('the charges keep their amount when the fee changes later', function () {
    ($this->generar)();

    $casa = TipoVivienda::query()->where('nombre', 'Casa')->sole();
    $this->put(route('finanzas.tipos-vivienda.update', $casa), ['nombre' => 'Casa', 'monto' => '600', 'vigente_desde' => '2026-12'])
        ->assertSessionHasNoErrors();

    Carbon::setTestNow('2026-12-02 12:00:00');
    ($this->generar)();

    expect(($this->cargos)('2026-11-01'))->toContain('A-01 · Cuota mensual · 500.00')
        ->and(($this->cargos)('2026-12-01'))->toContain('A-01 · Cuota mensual · 600.00');
});

test('the scheduled task generates on the configured day and catches up', function () {
    $this->put(route('finanzas.cargos.dia'), ['dia' => '10'])->assertSessionHasNoErrors();

    Carbon::setTestNow('2026-11-05 12:00:00');
    $this->artisan('cargos:generar')->assertSuccessful();

    expect(Cargo::query()->count())->toBe(0);

    // El día 10 el servidor estaba apagado: el día 12 se pone al día
    Carbon::setTestNow('2026-11-12 08:00:00');
    $this->artisan('cargos:generar')->assertSuccessful();
    $this->artisan('cargos:generar')->assertSuccessful();

    $generacion = GeneracionCargos::query()->sole();
    $registro = Auditoria::query()->where('accion', 'cargos_generados')->sole();

    expect(Cargo::query()->count())->toBe(5)
        ->and($generacion->automatica)->toBeTrue()
        ->and($generacion->user_id)->toBeNull()
        ->and($registro->user_id)->toBeNull()
        ->and($registro->residencial_id)->toBe($this->residencial->id)
        ->and($registro->valor_nuevo)->toBe(['periodo' => '2026-11-01', 'cargos' => 5]);
});

test('suspended residenciales do not receive charges', function () {
    $this->residencial->update(['estado' => Residencial::SUSPENDIDO]);

    $this->artisan('cargos:generar')->assertSuccessful();

    expect(Cargo::query()->count())->toBe(0);
});

test('the treasurer generates now and chooses the day', function () {
    $this->post(route('finanzas.cargos.generar'))->assertInertiaFlash('toast.message', 'Se generaron 5 cargos.');
    $this->post(route('finanzas.cargos.generar'))->assertInertiaFlash('toast.message', 'Los cargos del mes ya estaban generados.');

    $this->put(route('finanzas.cargos.dia'), ['dia' => '29'])->assertSessionHasErrors('dia');
    $this->put(route('finanzas.cargos.dia'), ['dia' => '15'])->assertSessionHasNoErrors();

    expect(Auditoria::query()->where('accion', 'dia_generacion_cambiado')->sole()->valor_nuevo)->toBe(['dia_generacion_cargos' => 15]);

    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generacion.dia', 15)
            ->where('generacion.ultima.automatica', false)
            ->where('generacion.ultima.usuario', $this->tesorero->name));

    foreach ([Rol::PRESIDENTE, 'fiscal', Rol::SECRETARIO, 'cobrador'] as $rol) {
        $this->actingAs(($this->crear)($rol));
        $this->post(route('finanzas.cargos.generar'))->assertForbidden();
        $this->put(route('finanzas.cargos.dia'), ['dia' => '3'])->assertForbidden();
    }
});

test('the cash payment sees the pending months of the generated charges', function () {
    ($this->generar)();

    $pendientes = fn () => app(CargosPendientes::class)->pendientesDeVivienda($this->casa->id)
        ->map(fn (array $cargo) => $cargo['concepto'].' '.$cargo['periodo'].' '.$cargo['pendiente'])
        ->all();

    expect($pendientes())->toBe(['Cuota mensual 2026-11-01 500.00', 'Vigilancia 2026-11-01 100.00']);

    $cuota = Cargo::query()->where('vivienda_id', $this->casa->id)->whereHas('concepto', fn ($query) => $query->where('es_cuota_unificada', true))->sole();

    $pagar = function (string $monto, string $estado) use ($cuota) {
        $pago = Pago::query()->forceCreate([
            'residencial_id' => $this->residencial->id, 'vivienda_id' => $this->casa->id, 'forma_pago' => 'efectivo',
            'estado' => $estado, 'monto' => $monto, 'fecha_pago' => '2026-11-15',
        ]);

        PagoAplicacion::query()->create(['residencial_id' => $this->residencial->id, 'pago_id' => $pago->id, 'cargo_id' => $cuota->id, 'monto' => $monto]);
    };

    // Un pago anulado no cuenta; un abono confirmado reduce lo pendiente
    $pagar('500', 'anulado');
    $pagar('200', 'confirmado');

    expect($pendientes())->toBe(['Cuota mensual 2026-11-01 300.00', 'Vigilancia 2026-11-01 100.00']);

    $pagar('300', 'confirmado');

    expect($pendientes())->toBe(['Vigilancia 2026-11-01 100.00']);
});

test('each residencial generates only its own charges', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro']);
    Vivienda::query()->create(['residencial_id' => $otro->id, 'codigo' => 'X-01', 'tipo_vivienda_id' => tipoVivienda($otro->id)]);

    ($this->generar)();

    expect(Cargo::query()->withoutGlobalScope('residencial')->where('residencial_id', $otro->id)->count())->toBe(0)
        ->and(GeneracionCargos::query()->withoutGlobalScope('residencial')->where('residencial_id', $otro->id)->exists())->toBeFalse();
});
