<?php

use Database\Seeders\RolesSeeder;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->secretario = User::factory()->create([
        'name' => 'Sara Secretaria',
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);

    $this->vivienda = ['codigo' => 'A-01', 'tipo' => 'casa', 'calle_bloque' => 'Bloque A', 'numero' => '1', 'estado_ocupacion' => 'ocupada', 'cuota' => '500'];

    $this->actingAs($this->secretario);
});

test('the history of a housing unit shows each change with its user and date, newest first', function () {
    $this->post(route('padron.viviendas.store'), $this->vivienda)->assertSessionHasNoErrors();
    $vivienda = Vivienda::query()->sole();

    $this->post(route('padron.residentes.store'), [
        'vivienda_id' => $vivienda->id, 'tipo' => 'propietario', 'nombres' => 'María', 'apellidos' => 'López', 'telefono' => '9999-1111',
    ])->assertSessionHasNoErrors();
    $residente = Residente::query()->sole();

    $this->put(route('padron.viviendas.update', $vivienda->id), [...$this->vivienda, 'numero' => '2'])->assertSessionHasNoErrors();

    $this->put(route('padron.residentes.update', $residente->id), [
        'vivienda_id' => $vivienda->id, 'vivienda_origen_id' => $vivienda->id, 'tipo' => 'propietario',
        'nombres' => 'María', 'apellidos' => 'López', 'telefono' => '9999-2222', 'es_responsable_pago' => true,
    ])->assertSessionHasNoErrors();

    $historial = $this->getJson(route('padron.viviendas.historial', $vivienda->id))->assertOk()->json();

    expect(collect($historial)->pluck('accion')->all())
        ->toBe(['residente_editado', 'vivienda_editada', 'residente_registrado', 'vivienda_registrada']);

    expect($historial[0])
        ->usuario->toBe('Sara Secretaria')
        ->fecha->not->toBeEmpty()
        ->referencia->toBe('Residente: María López')
        ->cambios->toBe([['campo' => 'Teléfono', 'antes' => '9999-1111', 'despues' => '9999-2222']]);

    expect($historial[1])
        ->referencia->toBe('Vivienda: A-01')
        ->cambios->toBe([['campo' => 'Número', 'antes' => '1', 'despues' => '2']]);
});

test('the history does not include other housing units', function () {
    $this->post(route('padron.viviendas.store'), $this->vivienda);
    $this->post(route('padron.viviendas.store'), [...$this->vivienda, 'codigo' => 'B-01']);

    $vivienda = Vivienda::query()->where('codigo', 'A-01')->sole();

    $this->getJson(route('padron.viviendas.historial', $vivienda->id))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.referencia', 'Vivienda: A-01');
});

test('the history requires registry access and stays inside the residencial', function () {
    $this->post(route('padron.viviendas.store'), $this->vivienda);
    $vivienda = Vivienda::query()->sole();

    $guardia = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', 'guardia')->value('id'),
    ]);
    $this->actingAs($guardia)->getJson(route('padron.viviendas.historial', $vivienda->id))->assertForbidden();

    $otro = Residencial::query()->create(['nombre' => 'Otro']);
    $ajeno = User::factory()->create([
        'residencial_id' => $otro->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);
    $this->actingAs($ajeno)->getJson(route('padron.viviendas.historial', $vivienda->id))->assertForbidden();

    expect(Auditoria::query()->where('accion', 'acceso_denegado')->exists())->toBeTrue();
});
