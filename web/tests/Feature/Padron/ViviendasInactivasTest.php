<?php

use Database\Seeders\RolesSeeder;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\TarifaService;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->actingAs(User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]));

    $this->activa = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);
    $this->inactiva = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'B-01', 'tipo' => 'casa', 'activa' => false]);
});

test('active and inactive housing units are listed separately', function () {
    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('estado', 'activas')
            ->count('viviendas.data', 1)
            ->where('viviendas.data.0.codigo', 'A-01'));

    $this->get(route('padron.viviendas.index', ['estado' => 'inactivas']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('estado', 'inactivas')
            ->count('viviendas.data', 1)
            ->where('viviendas.data.0.codigo', 'B-01')
            ->count('opcionesViviendas', 1));
});

test('the search also works on inactive housing units', function () {
    $this->get(route('padron.viviendas.index', ['estado' => 'inactivas', 'buscar' => 'b-0']))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('viviendas.data', 1));

    $this->get(route('padron.viviendas.index', ['estado' => 'inactivas', 'buscar' => 'a-0']))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('viviendas.data', 0));
});

test('an inactive housing unit can be reactivated and keeps its fee', function () {
    app(TarifaService::class)->asignarCuota($this->residencial->id, $this->inactiva->id, 400, auth()->id());

    $this->post(route('padron.viviendas.reactivar', $this->inactiva->id))->assertSessionHasNoErrors();

    expect($this->inactiva->refresh()->activa)->toBeTrue();

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('viviendas.data', 2)
            ->where('viviendas.data.1.codigo', 'B-01')
            ->where('viviendas.data.1.cuota', '400.00')
            ->count('opcionesViviendas', 2));
});

test('an inactive housing unit cannot be edited or deleted until reactivated', function () {
    $datos = ['codigo' => 'B-01', 'tipo' => 'casa', 'calle_bloque' => 'x', 'numero' => '1', 'estado_ocupacion' => 'ocupada', 'cuota' => '1'];

    $this->put(route('padron.viviendas.update', $this->inactiva->id), $datos)->assertNotFound();
    $this->delete(route('padron.viviendas.destroy', $this->inactiva->id))->assertNotFound();

    expect(Vivienda::query()->whereKey($this->inactiva->id)->exists())->toBeTrue();
});

test('its code stays reserved while inactive', function () {
    $this->post(route('padron.viviendas.store'), ['codigo' => 'B-01', 'tipo' => 'casa', 'calle_bloque' => 'x', 'numero' => '1', 'estado_ocupacion' => 'ocupada', 'cuota' => '1'])
        ->assertSessionHasErrors('codigo');
});

test('users without registry access cannot reactivate', function () {
    $this->actingAs(User::factory()->create(['residencial_id' => $this->residencial->id]))
        ->post(route('padron.viviendas.reactivar', $this->inactiva->id))
        ->assertForbidden();

    expect($this->inactiva->refresh()->activa)->toBeFalse();
});
