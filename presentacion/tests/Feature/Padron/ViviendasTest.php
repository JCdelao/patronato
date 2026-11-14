<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Tarifa;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);

    $this->datos = ['codigo' => 'A-01', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id), 'calle_bloque' => 'Bloque A', 'numero' => '1', 'estado_ocupacion' => 'ocupada'];
});

test('only users with registry access can use it', function () {
    $this->get(route('padron.viviendas.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('padron.viviendas.index'))->assertForbidden();

    $plataforma = User::factory()->create(['rol_id' => Rol::query()->where('codigo', Rol::ADMIN_PLATAFORMA)->value('id')]);
    $this->actingAs($plataforma)->post(route('padron.viviendas.store'), $this->datos)->assertForbidden();

    $guardia = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', 'guardia')->value('id'),
    ]);
    $this->actingAs($guardia)->get(route('padron.viviendas.index'))->assertForbidden();

    $this->actingAs($this->usuario)->get(route('padron.viviendas.index'))->assertOk();
});

test('a housing unit is registered with its monthly fee', function () {
    $this->actingAs($this->usuario)
        ->post(route('padron.viviendas.store'), $this->datos)
        ->assertSessionHasNoErrors();

    $vivienda = Vivienda::query()->sole();

    expect($vivienda->residencial_id)->toBe($this->residencial->id)
        ->and($vivienda->codigo)->toBe('A-01')
        ->and($vivienda->calle_bloque)->toBe('Bloque A');

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('padron/viviendas')
            ->where('viviendas.data.0.codigo', 'A-01')
            ->where('opcionesViviendas.0.codigo', 'A-01'));
});

test('the code is unique per residencial and data is validated', function () {
    Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id)]);

    $this->actingAs($this->usuario)
        ->post(route('padron.viviendas.store'), $this->datos)
        ->assertSessionHasErrors('codigo');

    $this->post(route('padron.viviendas.store'), ['codigo' => '', 'tipo_vivienda_id' => 'otro', 'numero' => '', 'estado_ocupacion' => 'vacia'])
        ->assertSessionHasErrors(['codigo', 'tipo_vivienda_id', 'numero', 'estado_ocupacion']);

    Vivienda::query()->create(['residencial_id' => $this->otro->id, 'codigo' => 'B-01', 'tipo_vivienda_id' => tipoVivienda($this->otro->id)]);

    $this->post(route('padron.viviendas.store'), [...$this->datos, 'codigo' => 'B-01'])
        ->assertSessionHasNoErrors();
});

test('the housing unit form does not define fees', function () {
    $this->actingAs($this->usuario)->post(route('padron.viviendas.store'), [...$this->datos])
        ->assertSessionHasNoErrors();

    $this->put(route('padron.viviendas.update', Vivienda::query()->sole()->id), [...$this->datos])
        ->assertSessionHasNoErrors();

    expect(Tarifa::query()->count())->toBe(0);
});

test('an unused housing unit is deleted', function () {
    $this->actingAs($this->usuario)->post(route('padron.viviendas.store'), $this->datos);

    $this->delete(route('padron.viviendas.destroy', Vivienda::query()->sole()->id))->assertSessionHasNoErrors();

    expect(Vivienda::query()->count())->toBe(0)->and(Tarifa::query()->count())->toBe(0);
});

test('a housing unit with residents is not deleted', function () {
    $this->actingAs($this->usuario)->post(route('padron.viviendas.store'), $this->datos);

    $vivienda = Vivienda::query()->sole();

    $this->post(route('padron.residentes.store'), [
        'vivienda_id' => $vivienda->id, 'tipo' => 'propietario', 'nombres' => 'María', 'apellidos' => 'López',
    ]);

    $this->delete(route('padron.viviendas.destroy', $vivienda->id));

    expect($vivienda->refresh()->activa)->toBeTrue();
});

test('a housing unit with history stays inactive instead of being deleted', function () {
    $this->actingAs($this->usuario)->post(route('padron.viviendas.store'), $this->datos);

    $vivienda = Vivienda::query()->sole();

    // Cuota que la vivienda tenía en el padrón antes de las tarifas por tipo
    $concepto = ConceptoCobro::query()->create([
        'residencial_id' => $this->residencial->id, 'nombre' => 'Cuota mensual', 'tipo' => 'recurrente', 'es_cuota_unificada' => true,
    ]);
    Tarifa::query()->create([
        'residencial_id' => $this->residencial->id, 'concepto_id' => $concepto->id, 'vivienda_id' => $vivienda->id,
        'monto' => 500, 'vigente_desde' => '2026-01-01', 'motivo' => 'Cuota definida en el padrón de viviendas',
    ]);

    DB::table('pagos')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $vivienda->id,
        'forma_pago' => 'efectivo',
        'estado' => 'confirmado',
        'monto' => 500,
        'fecha_pago' => '2026-01-05',
    ]);

    $this->delete(route('padron.viviendas.destroy', $vivienda->id))->assertSessionHasNoErrors();

    expect($vivienda->refresh()->activa)->toBeFalse()
        ->and(Tarifa::query()->count())->toBe(1);

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('viviendas.data', 0));
});

test('another residencial data is neither visible nor editable', function () {
    $ajena = Vivienda::query()->create(['residencial_id' => $this->otro->id, 'codigo' => 'X-01', 'tipo_vivienda_id' => tipoVivienda($this->otro->id)]);
    $residente = Residente::query()->create(['residencial_id' => $this->otro->id, 'nombres' => 'Ajeno', 'apellidos' => 'Otro']);

    $this->actingAs($this->usuario)->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('viviendas.data', 0)
            ->count('opcionesViviendas', 0));

    $this->put(route('padron.viviendas.update', $ajena->id), $this->datos)->assertForbidden();
    $this->delete(route('padron.viviendas.destroy', $ajena->id))->assertForbidden();
    $this->delete(route('padron.residentes.destroy', $residente->id))->assertForbidden();

    $this->post(route('padron.residentes.store'), [
        'vivienda_id' => $ajena->id, 'tipo' => 'propietario', 'nombres' => 'M', 'apellidos' => 'L',
    ])->assertSessionHasErrors('vivienda_id');

    expect($ajena->refresh()->codigo)->toBe('X-01')
        ->and(ViviendaResidente::query()->count())->toBe(0);
});

test('the search matches code, name and phone', function (string $buscar, int $resultados) {
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id)]);
    Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'B-02', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id)]);

    $residente = Residente::query()->create([
        'residencial_id' => $this->residencial->id, 'nombres' => 'Carlos', 'apellidos' => 'Mejia', 'telefono' => '9999-2222',
    ]);

    ViviendaResidente::query()->create([
        'residencial_id' => $this->residencial->id, 'vivienda_id' => $vivienda->id, 'residente_id' => $residente->id, 'tipo' => 'propietario',
    ]);

    $this->actingAs($this->usuario)
        ->get(route('padron.viviendas.index', ['buscar' => $buscar]))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('viviendas.data', $resultados));
})->with([
    'codigo' => ['a-01', 1],
    'nombre completo' => ['carlos mej', 1],
    'telefono' => ['9999-2222', 1],
    'sin resultados' => ['zzz', 0],
    'vacio' => ['', 2],
]);

test('the search ignores accents', function (string $buscar) {
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id)]);
    Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'B-02', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id)]);

    $residente = Residente::query()->create([
        'residencial_id' => $this->residencial->id, 'nombres' => 'José Ángel', 'apellidos' => 'Peña',
    ]);

    ViviendaResidente::query()->create([
        'residencial_id' => $this->residencial->id, 'vivienda_id' => $vivienda->id, 'residente_id' => $residente->id, 'tipo' => 'propietario',
    ]);

    $this->actingAs($this->usuario)
        ->get(route('padron.viviendas.index', ['buscar' => $buscar]))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('viviendas.data', 1));
})->with(['jose', 'JOSE', 'José', 'angel', 'pena', 'Peña', 'jose angel pena']);

test('the street and the building are both optional and both are kept', function () {
    $this->actingAs($this->usuario);

    $apartamento = [
        ...$this->datos, 'codigo' => 'T-304', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id, 'Apartamento'),
        'calle_bloque' => null, 'edificio_torre' => null, 'numero' => '304', 'estado_ocupacion' => 'desocupada',
    ];

    $this->post(route('padron.viviendas.store'), $apartamento)->assertSessionHasNoErrors();

    $vivienda = Vivienda::query()->sole();

    expect($vivienda->edificio_torre)->toBeNull()
        ->and($vivienda->calle_bloque)->toBeNull()
        ->and($vivienda->numero)->toBe('304')
        ->and($vivienda->estado_ocupacion)->toBe('desocupada');

    $this->put(route('padron.viviendas.update', $vivienda->id), [...$apartamento, 'calle_bloque' => 'Bloque A', 'edificio_torre' => 'Torre B'])
        ->assertSessionHasNoErrors();

    expect($vivienda->refresh()->edificio_torre)->toBe('Torre B')
        ->and($vivienda->calle_bloque)->toBe('Bloque A');

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.tipo', 'Apartamento')
            ->where('viviendas.data.0.edificio_torre', 'Torre B'));
});

test('the type comes from the active types of the residencial', function () {
    $this->actingAs($this->usuario);

    $inactivo = TipoVivienda::query()->create(['residencial_id' => $this->residencial->id, 'nombre' => 'Local', 'activo' => false]);
    $ajeno = tipoVivienda($this->otro->id);

    $this->post(route('padron.viviendas.store'), [...$this->datos, 'tipo_vivienda_id' => $inactivo->id])->assertSessionHasErrors('tipo_vivienda_id');
    $this->post(route('padron.viviendas.store'), [...$this->datos, 'tipo_vivienda_id' => $ajeno])->assertSessionHasErrors('tipo_vivienda_id');
    $this->post(route('padron.viviendas.store'), [...$this->datos, 'tipo_vivienda_id' => null])->assertSessionHasErrors('tipo_vivienda_id');

    expect(Vivienda::query()->count())->toBe(0);
});

test('a room belongs to an apartment and has its own record', function () {
    $this->actingAs($this->usuario);

    $this->post(route('padron.viviendas.store'), [...$this->datos, 'codigo' => 'T-304', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id, 'Apartamento')]);
    $apartamento = Vivienda::query()->sole();

    $habitacion = [...$this->datos, 'codigo' => 'T-304-H1', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id, 'Habitación'), 'vivienda_padre_id' => $apartamento->id];

    $this->post(route('padron.viviendas.store'), $habitacion)->assertSessionHasNoErrors();

    $registrada = Vivienda::query()->where('codigo', 'T-304-H1')->sole();

    expect($registrada->vivienda_padre_id)->toBe($apartamento->id)
        ->and(Auditoria::query()->where('accion', 'vivienda_registrada')->latest('id')->first()->valor_nuevo['pertenece_a'])->toBe('T-304');

    $this->get(route('padron.viviendas.index', ['buscar' => 'H1']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.tipo', 'Habitación')
            ->where('viviendas.data.0.pertenece_a', 'T-304'));

    // Solo hay un nivel y una vivienda no pertenece a sí misma
    $this->post(route('padron.viviendas.store'), [...$habitacion, 'codigo' => 'T-304-H1-A', 'vivienda_padre_id' => $registrada->id])
        ->assertSessionHasErrors('vivienda_padre_id');
    $this->put(route('padron.viviendas.update', $apartamento->id), [...$this->datos, 'codigo' => 'T-304', 'vivienda_padre_id' => $apartamento->id])
        ->assertSessionHasErrors('vivienda_padre_id');
    $this->put(route('padron.viviendas.update', $apartamento->id), [...$this->datos, 'codigo' => 'T-304', 'vivienda_padre_id' => Vivienda::query()->create([
        'residencial_id' => $this->residencial->id, 'codigo' => 'T-305', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
    ])->id])->assertSessionHasErrors('vivienda_padre_id');

    expect($apartamento->refresh()->vivienda_padre_id)->toBeNull();
});

test('every change to housing units is audited', function () {
    $this->actingAs($this->usuario)->post(route('padron.viviendas.store'), $this->datos);

    $vivienda = Vivienda::query()->sole();

    $this->put(route('padron.viviendas.update', $vivienda->id), [...$this->datos, 'numero' => '2']);
    $this->put(route('padron.viviendas.update', $vivienda->id), [...$this->datos, 'numero' => '2']);
    $this->delete(route('padron.viviendas.destroy', $vivienda->id));

    $registros = Auditoria::query()->where('modulo', 'padron')->orderBy('id')->get();

    expect($registros->pluck('accion')->all())->toBe(['vivienda_registrada', 'vivienda_editada', 'vivienda_eliminada'])
        ->and($registros[0]->valor_nuevo['codigo'])->toBe('A-01')
        ->and($registros[1]->valor_anterior)->toBe(['numero' => '1'])
        ->and($registros[1]->valor_nuevo)->toBe(['numero' => '2'])
        ->and($registros[2]->valor_anterior['codigo'])->toBe('A-01');
});
