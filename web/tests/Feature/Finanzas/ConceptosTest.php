<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\TarifaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Tarifa;
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

    $this->registrar = fn (array $datos = []) => $this->post(route('finanzas.conceptos.store'), [
        'nombre' => 'Agua',
        'tipo' => 'recurrente',
        'monto' => '150',
        'vigente_desde' => '2026-11',
        ...$datos,
    ]);

    $this->vigencias = fn (ConceptoCobro $concepto) => Tarifa::query()
        ->where('concepto_id', $concepto->id)
        ->orderBy('vigente_desde')
        ->get()
        ->map(fn (Tarifa $tarifa) => [$tarifa->monto, $tarifa->vigente_desde->toDateString(), $tarifa->vigente_hasta?->toDateString()])
        ->all();

    $this->cargar = function (ConceptoCobro $concepto, string $periodo, int $monto) {
        $vivienda = Vivienda::query()->firstOrCreate(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01'], ['tipo' => 'casa']);

        return Cargo::query()->forceCreate([
            'residencial_id' => $this->residencial->id, 'vivienda_id' => $vivienda->id,
            'concepto_id' => $concepto->id, 'periodo' => $periodo, 'monto' => $monto,
        ]);
    };
});

afterEach(fn () => Carbon::setTestNow());

test('the treasurer manages the concepts and other board members only see them', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    $this->get(route('finanzas.conceptos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/conceptos')
            ->where('puedeEditar', true)
            ->where('conceptos.0.nombre', 'Agua'));

    foreach ([Rol::PRESIDENTE, 'fiscal'] as $rol) {
        $this->actingAs(($this->crear)($rol))->get(route('finanzas.conceptos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeEditar', false));
        ($this->registrar)(['nombre' => 'Vigilancia'])->assertForbidden();
    }

    foreach ([Rol::SECRETARIO, 'guardia', 'cobrador', 'residente'] as $rol) {
        $this->actingAs(($this->crear)($rol))->get(route('finanzas.conceptos.index'))->assertForbidden();
    }

    expect(ConceptoCobro::query()->count())->toBe(1);
});

test('each concept has a name, an amount, a start month and a type', function () {
    ($this->registrar)(['nombre' => ' Cuota extraordinaria ', 'tipo' => 'unico', 'monto' => '1200.5', 'vigente_desde' => '2026-12'])
        ->assertSessionHasNoErrors();

    $concepto = ConceptoCobro::query()->sole();

    expect($concepto->nombre)->toBe('Cuota extraordinaria')
        ->and($concepto->tipo)->toBe('unico')
        ->and($concepto->activo)->toBeTrue()
        ->and(($this->vigencias)($concepto))->toBe([['1200.50', '2026-12-01', null]])
        ->and(Auditoria::query()->where('accion', 'concepto_creado')->count())->toBe(1);

    $this->get(route('finanzas.conceptos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conceptos.0.actual', null)
            ->where('conceptos.0.proximos.0.monto', '1200.50')
            ->where('conceptos.0.proximos.0.desde', '2026-12-01'));
});

test('a change of amount creates a new validity period', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();

    $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua potable', 'tipo' => 'recurrente', 'monto' => '200', 'vigente_desde' => '2027-01',
    ])->assertSessionHasNoErrors();

    expect(($this->vigencias)($concepto))->toBe([
        ['150.00', '2026-11-01', '2026-12-31'],
        ['200.00', '2027-01-01', null],
    ])->and($concepto->refresh()->nombre)->toBe('Agua potable');

    $registro = Auditoria::query()->where('accion', 'concepto_editado')->sole();

    expect($registro->valor_anterior)->toBe(['nombre' => 'Agua', 'monto' => '150.00', 'vigente_desde' => '2026-11-01'])
        ->and($registro->valor_nuevo)->toBe(['nombre' => 'Agua potable', 'monto' => '200.00', 'vigente_desde' => '2027-01-01']);

    $this->get(route('finanzas.conceptos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conceptos.0.actual.monto', '150.00')
            ->where('conceptos.0.proximos.0.monto', '200.00'));
});

test('the charges already generated keep their original amount', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();
    $cargo = ($this->cargar)($concepto, '2026-11-01', 150);

    $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua', 'tipo' => 'recurrente', 'monto' => '200', 'vigente_desde' => '2026-12',
    ])->assertSessionHasNoErrors();

    expect($cargo->refresh()->monto)->toBe('150.00');
});

test('an amount that already has charges in its month cannot be corrected', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();

    // Sin cargos, el monto del mes se corrige en la misma vigencia
    $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua', 'tipo' => 'recurrente', 'monto' => '160', 'vigente_desde' => '2026-11',
    ])->assertSessionHasNoErrors();

    expect(($this->vigencias)($concepto))->toBe([['160.00', '2026-11-01', null]]);

    ($this->cargar)($concepto, '2026-11-01', 160);

    $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua', 'tipo' => 'recurrente', 'monto' => '170', 'vigente_desde' => '2026-11',
    ])->assertSessionHasErrors('vigente_desde');

    expect(($this->vigencias)($concepto))->toBe([['160.00', '2026-11-01', null]]);
});

test('a later change replaces the amounts that have not started yet', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();

    $editar = fn (string $monto, string $mes) => $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua', 'tipo' => 'recurrente', 'monto' => $monto, 'vigente_desde' => $mes,
    ]);

    $editar('200', '2027-02');
    $editar('180', '2026-12')->assertSessionHasNoErrors();

    expect(($this->vigencias)($concepto))->toBe([
        ['150.00', '2026-11-01', '2026-11-30'],
        ['180.00', '2026-12-01', null],
    ]);
});

test('the amount cannot start in a past month', function () {
    ($this->registrar)(['vigente_desde' => '2026-10'])->assertSessionHasErrors('vigente_desde');

    expect(ConceptoCobro::query()->count())->toBe(0);
});

test('a concept with charges cannot be deleted, only deactivated', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();
    ($this->cargar)($concepto, '2026-11-01', 150);

    $this->delete(route('finanzas.conceptos.destroy', $concepto))
        ->assertInertiaFlash('toast.type', 'error');

    expect(ConceptoCobro::query()->count())->toBe(1);

    $this->post(route('finanzas.conceptos.desactivar', $concepto))->assertSessionHasNoErrors();

    expect($concepto->refresh()->activo)->toBeFalse();

    // Un concepto inactivo no se edita, pero se puede reactivar
    $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua', 'tipo' => 'recurrente', 'monto' => '150', 'vigente_desde' => '2026-12',
    ])->assertNotFound();

    $this->post(route('finanzas.conceptos.reactivar', $concepto))->assertSessionHasNoErrors();

    expect($concepto->refresh()->activo)->toBeTrue()
        ->and(Auditoria::query()->whereIn('accion', ['concepto_desactivado', 'concepto_reactivado'])->count())->toBe(2);
});

test('a concept without charges can be deleted', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();

    $this->delete(route('finanzas.conceptos.destroy', $concepto))
        ->assertInertiaFlash('toast.message', 'Concepto eliminado.');

    expect(ConceptoCobro::query()->count())->toBe(0)
        ->and(Tarifa::query()->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'concepto_eliminado')->count())->toBe(1);
});

test('the monthly fee of each housing unit is managed in the registry', function () {
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);
    app(TarifaService::class)->asignarCuota($this->residencial->id, $vivienda->id, 500, $this->tesorero->id);

    $cuota = ConceptoCobro::query()->sole();

    $this->get(route('finanzas.conceptos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conceptos.0.es_cuota_unificada', true)
            ->where('conceptos.0.actual', null));

    $this->put(route('finanzas.conceptos.update', $cuota), [
        'nombre' => 'Cuota', 'tipo' => 'recurrente', 'monto' => '1', 'vigente_desde' => '2026-12',
    ])->assertNotFound();
    $this->post(route('finanzas.conceptos.desactivar', $cuota))->assertNotFound();
    $this->delete(route('finanzas.conceptos.destroy', $cuota))->assertNotFound();

    ($this->registrar)(['nombre' => 'cuota MENSUAL'])->assertSessionHasErrors('nombre');
});

test('the concepts are validated', function (array $datos, string $campo) {
    ($this->registrar)();

    ($this->registrar)($datos)->assertSessionHasErrors($campo);

    expect(ConceptoCobro::query()->count())->toBe(1);
})->with([
    'nombre repetido' => [['nombre' => 'AGUA'], 'nombre'],
    'sin nombre' => [['nombre' => ''], 'nombre'],
    'tipo invalido' => [['nombre' => 'Luz', 'tipo' => 'mensual'], 'tipo'],
    'monto negativo' => [['nombre' => 'Luz', 'monto' => '-1'], 'monto'],
    'mes invalido' => [['nombre' => 'Luz', 'vigente_desde' => '2026-13'], 'vigente_desde'],
]);

test('each residencial has its own concepts', function () {
    ($this->registrar)();
    $concepto = ConceptoCobro::query()->sole();

    $otro = ($this->crear)('tesorero', Residencial::query()->create(['nombre' => 'Otro']));

    $this->actingAs($otro)->get(route('finanzas.conceptos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('conceptos', 0));

    $this->put(route('finanzas.conceptos.update', $concepto), [
        'nombre' => 'Agua', 'tipo' => 'recurrente', 'monto' => '1', 'vigente_desde' => '2026-12',
    ])->assertForbidden();

    // Otro residencial puede usar el mismo nombre
    ($this->registrar)()->assertSessionHasNoErrors();
});
