<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\TarifaEspecialService;
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

    $this->casa = TipoVivienda::query()->where('nombre', 'Casa')->sole();

    // La cuota de las casas es L. 500 desde noviembre
    $this->put(route('finanzas.tipos-vivienda.update', $this->casa), ['nombre' => 'Casa', 'monto' => '500', 'vigente_desde' => '2026-11']);

    $this->vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => $this->casa->id]);

    $this->asignar = fn (array $datos = []) => $this->post(route('finanzas.tarifas-especiales.store'), [
        'vivienda_id' => $this->vivienda->id,
        'monto' => '300',
        'motivo' => 'Acuerdo de la Junta Directiva',
        'vigente_desde' => '2026-11',
        ...$datos,
    ]);

    $this->cuotaDe = fn (string $periodo) => app(TarifaEspecialService::class)->cuotaDe($this->vivienda->refresh(), Carbon::parse($periodo));
});

afterEach(fn () => Carbon::setTestNow());

test('the special fee of a housing unit prevails over the fee of its type', function () {
    expect(($this->cuotaDe)('2026-11-01'))->toBe(['monto' => '500.00', 'origen' => 'tipo']);

    ($this->asignar)()->assertSessionHasNoErrors();

    expect(($this->cuotaDe)('2026-11-01'))->toBe(['monto' => '300.00', 'origen' => 'especial']);

    // Las demás casas siguen pagando la cuota de su tipo
    $otra = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-02', 'tipo_vivienda_id' => $this->casa->id]);

    expect(app(TarifaEspecialService::class)->cuotaDe($otra, Carbon::parse('2026-11-01')))->toBe(['monto' => '500.00', 'origen' => 'tipo']);
});

test('a special fee requires a reason and is audited', function () {
    ($this->asignar)(['motivo' => ''])->assertSessionHasErrors('motivo');

    expect(Tarifa::query()->whereNotNull('vivienda_id')->count())->toBe(0);

    ($this->asignar)(['motivo' => ' Casa del guardia ', 'monto' => '0'])->assertSessionHasNoErrors();

    $tarifa = Tarifa::query()->whereNotNull('vivienda_id')->sole();
    $registro = Auditoria::query()->where('accion', 'tarifa_especial_asignada')->sole();

    expect($tarifa->monto)->toBe('0.00')
        ->and($tarifa->motivo)->toBe('Casa del guardia')
        ->and($registro->entidad)->toBe('viviendas')
        ->and($registro->entidad_id)->toBe($this->vivienda->id)
        ->and($registro->motivo)->toBe('Casa del guardia')
        ->and($registro->valor_nuevo)->toBe(['cuota_especial' => '0.00', 'vigente_desde' => '2026-11-01']);
});

test('a change of special fee creates a new validity period', function () {
    ($this->asignar)();
    ($this->asignar)(['monto' => '350', 'vigente_desde' => '2027-01'])->assertSessionHasNoErrors();

    expect(Tarifa::query()->whereNotNull('vivienda_id')->orderBy('vigente_desde')->get()
        ->map(fn (Tarifa $tarifa) => [$tarifa->monto, $tarifa->vigente_desde->toDateString(), $tarifa->vigente_hasta?->toDateString()])->all())
        ->toBe([['300.00', '2026-11-01', '2026-12-31'], ['350.00', '2027-01-01', null]])
        ->and(($this->cuotaDe)('2026-12-01')['monto'])->toBe('300.00')
        ->and(($this->cuotaDe)('2027-01-01')['monto'])->toBe('350.00')
        ->and(Auditoria::query()->where('accion', 'tarifa_especial_asignada')->latest('id')->first()->valor_anterior)->toBe(['cuota_especial' => '300.00']);
});

test('removing a special fee returns to the fee of the type from the chosen month', function () {
    ($this->asignar)();

    $this->post(route('finanzas.tarifas-especiales.quitar', $this->vivienda), ['vigente_desde' => '2026-12'])->assertSessionHasNoErrors();

    expect(($this->cuotaDe)('2026-11-01'))->toBe(['monto' => '300.00', 'origen' => 'especial'])
        ->and(($this->cuotaDe)('2026-12-01'))->toBe(['monto' => '500.00', 'origen' => 'tipo'])
        ->and(Auditoria::query()->where('accion', 'tarifa_especial_quitada')->count())->toBe(1);

    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('especiales.0.codigo', 'A-01')
            ->where('especiales.0.actual.monto', '300.00'));
});

test('a special fee that already has charges in its month is kept', function () {
    ($this->asignar)();

    Cargo::query()->forceCreate([
        'residencial_id' => $this->residencial->id, 'vivienda_id' => $this->vivienda->id,
        'concepto_id' => ConceptoCobro::query()->where('es_cuota_unificada', true)->value('id'),
        'periodo' => '2026-11-01', 'monto' => 300,
    ]);

    ($this->asignar)(['monto' => '250'])->assertSessionHasErrors('vigente_desde');
    $this->post(route('finanzas.tarifas-especiales.quitar', $this->vivienda), ['vigente_desde' => '2026-11'])->assertSessionHasErrors('vigente_desde');

    expect(($this->cuotaDe)('2026-11-01')['monto'])->toBe('300.00');
});

test('the registry shows which housing units have a special fee', function () {
    ($this->asignar)();

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.codigo', 'A-01')
            ->where('viviendas.data.0.cuota_especial', '300.00'));
});

test('only the treasurer assigns special fees', function () {
    foreach ([Rol::PRESIDENTE, 'fiscal', Rol::SECRETARIO, 'cobrador'] as $rol) {
        $this->actingAs(($this->crear)($rol));
        ($this->asignar)()->assertForbidden();
    }

    expect(Tarifa::query()->whereNotNull('vivienda_id')->count())->toBe(0);
});

test('the special fees are validated', function (array $datos, string $campo) {
    ($this->asignar)($datos)->assertSessionHasErrors($campo);

    expect(Tarifa::query()->whereNotNull('vivienda_id')->count())->toBe(0);
})->with([
    'sin vivienda' => [['vivienda_id' => ''], 'vivienda_id'],
    'cuota negativa' => [['monto' => '-1'], 'monto'],
    'mes pasado' => [['vigente_desde' => '2026-10'], 'vigente_desde'],
]);

test('each residencial manages only its own housing units', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro']);
    $this->actingAs(($this->crear)('tesorero', $otro));

    ($this->asignar)()->assertSessionHasErrors('vivienda_id');
    $this->post(route('finanzas.tarifas-especiales.quitar', $this->vivienda), ['vigente_desde' => '2026-12'])->assertForbidden();

    expect(Tarifa::query()->whereNotNull('vivienda_id')->count())->toBe(0);
});
