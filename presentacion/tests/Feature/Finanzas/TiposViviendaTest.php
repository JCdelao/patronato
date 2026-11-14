<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Tarifa;
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

    $this->registrar = fn (array $datos = []) => $this->post(route('finanzas.tipos-vivienda.store'), [
        'nombre' => 'Habitación',
        'monto' => '150',
        'vigente_desde' => '2026-11',
        ...$datos,
    ]);

    $this->editar = fn (TipoVivienda $tipo, array $datos) => $this->put(route('finanzas.tipos-vivienda.update', $tipo), [
        'nombre' => $tipo->nombre,
        'monto' => '150',
        'vigente_desde' => '2026-12',
        ...$datos,
    ]);

    // Cuotas del tipo: monto, inicio y fin de cada vigencia
    $this->cuotas = fn (TipoVivienda $tipo) => Tarifa::query()
        ->where('tipo_vivienda_id', $tipo->id)
        ->orderBy('vigente_desde')
        ->get()
        ->map(fn (Tarifa $tarifa) => [$tarifa->monto, $tarifa->vigente_desde->toDateString(), $tarifa->vigente_hasta?->toDateString()])
        ->all();

    $this->tipo = fn (string $nombre) => TipoVivienda::query()->where('nombre', $nombre)->sole();
});

afterEach(fn () => Carbon::setTestNow());

test('a new residencial starts with houses and apartments without a fee', function () {
    expect(TipoVivienda::query()->orderBy('nombre')->pluck('nombre')->all())->toBe(['Apartamento', 'Casa'])
        ->and(Tarifa::query()->count())->toBe(0);

    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/parametros')
            ->count('tipos', 2)
            ->where('tipos.0.nombre', 'Apartamento')
            ->where('tipos.0.actual', null));
});

test('the treasurer manages the housing types and other board members only see them', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    foreach ([Rol::PRESIDENTE, 'fiscal'] as $rol) {
        $this->actingAs(($this->crear)($rol))->get(route('finanzas.parametros.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeEditar', false)->count('tipos', 3));
        ($this->registrar)(['nombre' => 'Local'])->assertForbidden();
    }

    foreach ([Rol::SECRETARIO, 'guardia', 'cobrador', 'residente'] as $rol) {
        $this->actingAs(($this->crear)($rol))->post(route('finanzas.tipos-vivienda.store'), ['nombre' => 'Local'])->assertForbidden();
    }

    expect(TipoVivienda::query()->count())->toBe(3);
});

test('each housing type has a monthly fee with its start month', function () {
    ($this->registrar)(['nombre' => ' Habitación ', 'monto' => '150.5', 'vigente_desde' => '2026-12'])->assertSessionHasNoErrors();

    $tipo = ($this->tipo)('Habitación');

    expect(($this->cuotas)($tipo))->toBe([['150.50', '2026-12-01', null]])
        ->and(ConceptoCobro::query()->where('es_cuota_unificada', true)->sole()->nombre)->toBe('Cuota mensual')
        ->and(Auditoria::query()->where('accion', 'tipo_vivienda_creado')->sole()->valor_nuevo)
        ->toBe(['nombre' => 'Habitación', 'cuota' => '150.50', 'vigente_desde' => '2026-12-01']);

    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tipos.2.nombre', 'Habitación')
            ->where('tipos.2.actual', null)
            ->where('tipos.2.proximos.0.monto', '150.50'));
});

test('a fee change creates a new validity period and keeps the previous one', function () {
    $casa = ($this->tipo)('Casa');

    ($this->editar)($casa, ['monto' => '500', 'vigente_desde' => '2026-11'])->assertSessionHasNoErrors();
    ($this->editar)($casa, ['monto' => '550', 'vigente_desde' => '2027-01'])->assertSessionHasNoErrors();

    expect(($this->cuotas)($casa))->toBe([
        ['500.00', '2026-11-01', '2026-12-31'],
        ['550.00', '2027-01-01', null],
    ]);

    $registro = Auditoria::query()->where('accion', 'tipo_vivienda_editado')->latest('id')->first();

    expect($registro->valor_anterior)->toBe(['cuota' => '500.00', 'vigente_desde' => '2026-11-01'])
        ->and($registro->valor_nuevo)->toBe(['cuota' => '550.00', 'vigente_desde' => '2027-01-01']);

    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tipos.1.actual.monto', '500.00')
            ->where('tipos.1.proximos.0.monto', '550.00'));
});

test('the fee of a month with charges for that type cannot be corrected', function () {
    $casa = ($this->tipo)('Casa');
    ($this->editar)($casa, ['monto' => '500', 'vigente_desde' => '2026-11']);

    // Sin cargos, la cuota del mes se corrige en la misma vigencia
    ($this->editar)($casa, ['monto' => '510', 'vigente_desde' => '2026-11'])->assertSessionHasNoErrors();

    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => $casa->id]);

    Cargo::query()->forceCreate([
        'residencial_id' => $this->residencial->id, 'vivienda_id' => $vivienda->id,
        'concepto_id' => ConceptoCobro::query()->where('es_cuota_unificada', true)->value('id'),
        'periodo' => '2026-11-01', 'monto' => 510,
    ]);

    ($this->editar)($casa, ['monto' => '520', 'vigente_desde' => '2026-11'])->assertSessionHasErrors('vigente_desde');

    expect(($this->cuotas)($casa))->toBe([['510.00', '2026-11-01', null]]);
});

test('a type in use cannot be deleted, only deactivated', function () {
    $casa = ($this->tipo)('Casa');
    Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => $casa->id]);

    $this->delete(route('finanzas.tipos-vivienda.destroy', $casa))->assertInertiaFlash('toast.type', 'error');

    expect(TipoVivienda::query()->count())->toBe(2);

    $this->post(route('finanzas.tipos-vivienda.desactivar', $casa))->assertSessionHasNoErrors();

    expect($casa->refresh()->activo)->toBeFalse();

    // Un tipo inactivo no se edita ni se asigna, pero se puede reactivar
    ($this->editar)($casa, ['monto' => '1'])->assertNotFound();

    $this->post(route('finanzas.tipos-vivienda.reactivar', $casa))->assertSessionHasNoErrors();

    expect($casa->refresh()->activo)->toBeTrue()
        ->and(Auditoria::query()->whereIn('accion', ['tipo_vivienda_desactivado', 'tipo_vivienda_reactivado'])->count())->toBe(2);
});

test('an unused type is deleted with its fees', function () {
    ($this->registrar)();
    $tipo = ($this->tipo)('Habitación');

    $this->delete(route('finanzas.tipos-vivienda.destroy', $tipo))->assertInertiaFlash('toast.message', 'Tipo de vivienda eliminado.');

    expect(TipoVivienda::query()->where('nombre', 'Habitación')->exists())->toBeFalse()
        ->and(Tarifa::query()->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'tipo_vivienda_eliminado')->count())->toBe(1);
});

test('the housing types are validated', function (array $datos, string $campo) {
    ($this->registrar)($datos)->assertSessionHasErrors($campo);

    expect(TipoVivienda::query()->count())->toBe(2);
})->with([
    'nombre repetido' => [['nombre' => 'CASA'], 'nombre'],
    'sin nombre' => [['nombre' => ''], 'nombre'],
    'sin cuota' => [['monto' => ''], 'monto'],
    'cuota negativa' => [['monto' => '-1'], 'monto'],
    'mes invalido' => [['vigente_desde' => '2026-13'], 'vigente_desde'],
    'mes pasado' => [['vigente_desde' => '2026-10'], 'vigente_desde'],
]);

test('each residencial has its own housing types', function () {
    $casa = ($this->tipo)('Casa');

    $otro = Residencial::query()->create(['nombre' => 'Otro']);
    $this->actingAs(($this->crear)('tesorero', $otro));

    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('tipos', 2));

    ($this->editar)($casa, ['monto' => '1'])->assertForbidden();

    // Otro residencial puede usar el mismo nombre
    ($this->registrar)()->assertSessionHasNoErrors();
    ($this->registrar)()->assertSessionHasErrors('nombre');
});
