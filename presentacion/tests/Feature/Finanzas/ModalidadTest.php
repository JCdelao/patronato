<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\ModalidadService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\ModalidadCobro;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
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

    $this->modalidades = app(ModalidadService::class);
    $this->modalidadDe = fn (string $fecha) => $this->modalidades->modalidadDe($this->residencial->id, Carbon::parse($fecha));
});

afterEach(fn () => Carbon::setTestNow());

test('the treasurer edits the modality and other board members only see it', function () {
    $this->actingAs($this->tesorero)->get(route('finanzas.modalidad.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/modalidad')
            ->where('actual', null)
            ->where('puedeEditar', true));

    foreach ([Rol::PRESIDENTE, 'fiscal'] as $rol) {
        $usuario = ($this->crear)($rol);

        $this->actingAs($usuario)->get(route('finanzas.modalidad.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeEditar', false));
        $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada'])->assertForbidden();
    }

    foreach ([Rol::SECRETARIO, 'guardia', 'cobrador', 'residente'] as $rol) {
        $this->actingAs(($this->crear)($rol))->get(route('finanzas.modalidad.index'))->assertForbidden();
    }

    expect(ModalidadCobro::query()->count())->toBe(0);
});

test('without a defined modality the charges use the unified one', function () {
    expect(($this->modalidadDe)('2026-11-01'))->toBe(ModalidadCobro::UNIFICADA);
});

test('the first modality applies from the current period', function () {
    $this->actingAs($this->tesorero)
        ->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada'])
        ->assertSessionHasNoErrors();

    $fila = ModalidadCobro::query()->sole();

    expect($fila->vigente_desde->toDateString())->toBe('2026-11-01')
        ->and(($this->modalidadDe)('2026-11-01'))->toBe(ModalidadCobro::DIVERSIFICADA)
        ->and(Auditoria::query()->where('accion', 'modalidad_definida')->count())->toBe(1);
});

test('a change of modality applies from the next period', function () {
    $this->actingAs($this->tesorero);
    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'unificada']);

    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'El cambio se aplicará desde diciembre de 2026.');

    expect(($this->modalidadDe)('2026-11-01'))->toBe(ModalidadCobro::UNIFICADA)
        ->and(($this->modalidadDe)('2026-12-01'))->toBe(ModalidadCobro::DIVERSIFICADA)
        ->and(Auditoria::query()->where('accion', 'cambio_de_modalidad_programado')->count())->toBe(1);

    $this->get(route('finanzas.modalidad.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('actual.modalidad', 'unificada')
            ->where('programada.modalidad', 'diversificada')
            ->where('programada.desde', '2026-12-01'));
});

test('the residencial has only one active modality at a time', function () {
    $this->actingAs($this->tesorero);
    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'unificada']);
    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada']);
    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada']);

    expect(ModalidadCobro::query()->whereDate('vigente_desde', '>', '2026-11-01')->count())->toBe(1);

    // Volver a la modalidad actual cancela el cambio pendiente
    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'unificada']);

    expect(($this->modalidadDe)('2026-12-01'))->toBe(ModalidadCobro::UNIFICADA)
        ->and(ModalidadCobro::query()->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'cambio_de_modalidad_cancelado')->count())->toBe(1);
});

test('a change of modality does not alter the charges already generated', function () {
    $this->actingAs($this->tesorero);
    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'unificada']);

    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);
    $concepto = ConceptoCobro::query()->create(['residencial_id' => $this->residencial->id, 'nombre' => 'Cuota mensual', 'tipo' => 'recurrente', 'es_cuota_unificada' => true]);

    DB::table('cargos')->insert([
        'residencial_id' => $this->residencial->id, 'vivienda_id' => $vivienda->id, 'concepto_id' => $concepto->id,
        'periodo' => '2026-11-01', 'monto' => 500, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $antes = DB::table('cargos')->get()->toArray();

    $this->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada'])->assertSessionHasNoErrors();

    expect(DB::table('cargos')->get()->toArray())->toEqual($antes);
});

test('the unified modality keeps an informative breakdown', function () {
    $this->actingAs($this->tesorero)->put(route('finanzas.modalidad.update'), [
        'modalidad' => 'unificada',
        'desglose' => [
            ['concepto' => ' Agua ', 'monto' => '150'],
            ['concepto' => 'Vigilancia', 'monto' => '350.5'],
        ],
    ])->assertSessionHasNoErrors();

    expect(ModalidadCobro::query()->sole()->desglose)->toBe([
        ['concepto' => 'Agua', 'monto' => '150.00'],
        ['concepto' => 'Vigilancia', 'monto' => '350.50'],
    ]);

    // Corregir el desglose no espera al período siguiente
    $this->put(route('finanzas.modalidad.update'), [
        'modalidad' => 'unificada',
        'desglose' => [['concepto' => 'Agua', 'monto' => '200']],
    ]);

    expect(ModalidadCobro::query()->sole()->desglose)->toBe([['concepto' => 'Agua', 'monto' => '200.00']])
        ->and(Auditoria::query()->where('accion', 'desglose_actualizado')->count())->toBe(1);
});

test('the diversified modality does not keep a breakdown', function () {
    $this->actingAs($this->tesorero)->put(route('finanzas.modalidad.update'), [
        'modalidad' => 'diversificada',
        'desglose' => [['concepto' => 'Agua', 'monto' => '150']],
    ])->assertSessionHasNoErrors();

    expect(ModalidadCobro::query()->sole()->desglose)->toBeNull();
});

test('the modality and the breakdown are validated', function (array $datos, string $campo) {
    $this->actingAs($this->tesorero)
        ->put(route('finanzas.modalidad.update'), $datos)
        ->assertSessionHasErrors($campo);

    expect(ModalidadCobro::query()->count())->toBe(0);
})->with([
    'sin modalidad' => [[], 'modalidad'],
    'modalidad invalida' => [['modalidad' => 'mixta'], 'modalidad'],
    'concepto vacio' => [['modalidad' => 'unificada', 'desglose' => [['concepto' => '', 'monto' => '1']]], 'desglose.0.concepto'],
    'monto negativo' => [['modalidad' => 'unificada', 'desglose' => [['concepto' => 'Agua', 'monto' => '-1']]], 'desglose.0.monto'],
    'concepto repetido' => [['modalidad' => 'unificada', 'desglose' => [['concepto' => 'Agua', 'monto' => '1'], ['concepto' => 'agua', 'monto' => '2']]], 'desglose.1.concepto'],
]);

test('each residencial has its own modality', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro']);

    $this->actingAs(($this->crear)('tesorero', $otro))
        ->put(route('finanzas.modalidad.update'), ['modalidad' => 'diversificada']);

    expect(($this->modalidadDe)('2026-11-01'))->toBe(ModalidadCobro::UNIFICADA);

    $this->actingAs($this->tesorero)->get(route('finanzas.modalidad.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('actual', null));
});
