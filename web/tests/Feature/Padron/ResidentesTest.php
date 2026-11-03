<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Identidad\Models\Auditoria;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Padron\Models\ViviendaResidente;
use Patronato\Modules\Padron\Services\ResidenteService;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->usuario = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);

    $this->vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);
    $this->segunda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-02', 'tipo' => 'casa']);

    $this->datos = fn (array $extra = []) => [
        'vivienda_id' => $this->vivienda->id,
        'tipo' => 'propietario',
        'nombres' => 'María',
        'apellidos' => 'López',
        'identidad' => '0801-1990-00001',
        'telefono' => '9999-1111',
        'correo' => 'maria@correo.test',
        ...$extra,
    ];

    $this->actingAs($this->usuario);
});

function responsableDe(Vivienda $vivienda): ?string
{
    return ViviendaResidente::query()
        ->where('vivienda_id', $vivienda->id)
        ->whereNull('fecha_fin')
        ->where('es_responsable_pago', true)
        ->sole()
        ->residente
        ->nombres;
}

test('a resident is registered and the first one is the payment responsible', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)())->assertSessionHasNoErrors();

    $residente = Residente::query()->sole();

    expect($residente->residencial_id)->toBe($this->residencial->id)
        ->and($residente->identidad)->toBe('0801-1990-00001')
        ->and(responsableDe($this->vivienda))->toBe('María');

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.residentes.0.nombres', 'María')
            ->where('viviendas.data.0.residentes.0.es_responsable_pago', true));
});

test('required fields and identity are validated', function () {
    $this->post(route('padron.residentes.store'), ['tipo' => 'otro'])
        ->assertSessionHasErrors(['vivienda_id', 'tipo', 'nombres', 'apellidos']);

    $this->post(route('padron.residentes.store'), ($this->datos)());

    $this->post(route('padron.residentes.store'), ($this->datos)(['nombres' => 'Otra']))
        ->assertSessionHasErrors('identidad');

    $this->post(route('padron.residentes.store'), ($this->datos)(['identidad' => null, 'correo' => 'mal']))
        ->assertSessionHasErrors('correo');
});

test('the payment responsible can be transferred and there is always one', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());
    $this->post(route('padron.residentes.store'), ($this->datos)([
        'nombres' => 'Carlos', 'identidad' => '2', 'es_responsable_pago' => '1',
    ]));

    expect(responsableDe($this->vivienda))->toBe('Carlos');

    $carlos = Residente::query()->where('nombres', 'Carlos')->sole();

    $this->put(route('padron.residentes.update', $carlos->id), ($this->datos)(['nombres' => 'Carlos', 'identidad' => '2']))
        ->assertSessionHasNoErrors();

    expect(responsableDe($this->vivienda))->toBe('María');
});

test('a resident can be edited', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());

    $residente = Residente::query()->sole();

    $this->put(route('padron.residentes.update', $residente->id), ($this->datos)(['telefono' => '8888-0000', 'tipo' => 'inquilino']))
        ->assertSessionHasNoErrors();

    expect($residente->refresh()->telefono)->toBe('8888-0000')
        ->and(ViviendaResidente::query()->sole()->tipo)->toBe('inquilino');
});

test('moving to another housing unit keeps the history', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());
    $this->post(route('padron.residentes.store'), ($this->datos)(['nombres' => 'Carlos', 'identidad' => '2']));

    $maria = Residente::query()->where('nombres', 'María')->sole();

    $this->put(route('padron.residentes.update', $maria->id), ($this->datos)(['vivienda_id' => $this->segunda->id]))
        ->assertSessionHasNoErrors();

    expect(ViviendaResidente::query()->where('residente_id', $maria->id)->count())->toBe(2)
        ->and(ViviendaResidente::query()->where('residente_id', $maria->id)->whereNull('fecha_fin')->sole()->vivienda_id)->toBe($this->segunda->id)
        ->and(responsableDe($this->vivienda))->toBe('Carlos')
        ->and(responsableDe($this->segunda))->toBe('María');
});

test('deleting a resident deactivates them and keeps the history', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());
    $this->post(route('padron.residentes.store'), ($this->datos)(['nombres' => 'Carlos', 'identidad' => '2']));

    $maria = Residente::query()->where('nombres', 'María')->sole();

    $this->delete(route('padron.residentes.destroy', $maria->id))->assertSessionHasNoErrors();

    expect($maria->refresh()->activo)->toBeFalse()
        ->and(ViviendaResidente::query()->where('residente_id', $maria->id)->sole()->fecha_fin)->not->toBeNull()
        ->and(responsableDe($this->vivienda))->toBe('Carlos');

    $this->delete(route('padron.residentes.destroy', $maria->id))->assertNotFound();
});

test('after every resident leaves the housing unit can be removed without losing history', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());

    $this->delete(route('padron.residentes.destroy', Residente::query()->sole()->id));
    $this->delete(route('padron.viviendas.destroy', $this->vivienda->id))->assertSessionHasNoErrors();

    expect($this->vivienda->refresh()->activa)->toBeFalse()
        ->and(ViviendaResidente::query()->count())->toBe(1);
});

test('a resident can live in more than one housing unit', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());

    $maria = Residente::query()->sole();

    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->segunda->id, 'tipo' => 'propietario'])
        ->assertSessionHasNoErrors();

    expect($maria->vinculosVigentes()->count())->toBe(2)
        ->and(responsableDe($this->vivienda))->toBe('María')
        ->and(responsableDe($this->segunda))->toBe('María')
        ->and(app(ResidenteService::class)->paraCuentas()[0]['vivienda'])->toBe('A-01, A-02');

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.residentes.0.otras_viviendas', ['A-02'])
            ->where('viviendas.data.1.residentes.0.otras_viviendas', ['A-01']));

    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->segunda->id, 'tipo' => 'familiar'])
        ->assertSessionHasErrors('vivienda_id');
});

test('editing one housing unit of a resident leaves the others untouched', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());

    $maria = Residente::query()->sole();

    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->segunda->id, 'tipo' => 'propietario']);

    $this->put(route('padron.residentes.update', $maria->id), ($this->datos)([
        'vivienda_id' => $this->segunda->id, 'vivienda_origen_id' => $this->segunda->id, 'tipo' => 'inquilino',
    ]))->assertSessionHasNoErrors();

    expect($maria->vinculosVigentes()->where('vivienda_id', $this->vivienda->id)->sole()->tipo)->toBe('propietario')
        ->and($maria->vinculosVigentes()->where('vivienda_id', $this->segunda->id)->sole()->tipo)->toBe('inquilino');

    $this->put(route('padron.residentes.update', $maria->id), ($this->datos)([
        'vivienda_id' => $this->segunda->id, 'vivienda_origen_id' => $this->vivienda->id,
    ]))->assertSessionHasErrors('vivienda_id');
});

test('leaving one housing unit keeps the resident active until the last one', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());

    $maria = Residente::query()->sole();

    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->segunda->id, 'tipo' => 'propietario']);

    $this->delete(route('padron.residentes.destroy', ['residente' => $maria->id, 'vivienda' => $this->vivienda->id]))
        ->assertSessionHasNoErrors();

    expect($maria->refresh()->activo)->toBeTrue()
        ->and($maria->vinculosVigentes()->sole()->vivienda_id)->toBe($this->segunda->id);

    $this->delete(route('padron.residentes.destroy', ['residente' => $maria->id, 'vivienda' => $this->segunda->id]));

    expect($maria->refresh()->activo)->toBeFalse()
        ->and(ViviendaResidente::query()->where('residente_id', $maria->id)->count())->toBe(2);
});

test('inactive residents are listed with their history and can be reactivated', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());
    $this->post(route('padron.residentes.store'), ($this->datos)(['nombres' => 'Carlos', 'identidad' => '2']));

    $maria = Residente::query()->where('nombres', 'María')->sole();

    $this->delete(route('padron.residentes.destroy', $maria->id));

    $this->get(route('padron.residentes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('padron/residentes')
            ->count('residentes.data', 1)
            ->where('residentes.data.0.nombres', 'María')
            ->where('residentes.data.0.historial.0.vivienda', 'A-01')
            ->where('residentes.data.0.historial.0.hasta', now()->toDateString()));

    $this->get(route('padron.residentes.index', ['buscar' => 'carlos']))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('residentes.data', 0));

    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->segunda->id, 'tipo' => 'inquilino'])
        ->assertSessionHasNoErrors();

    expect($maria->refresh()->activo)->toBeTrue()
        ->and($maria->vinculosVigentes()->sole()->vivienda_id)->toBe($this->segunda->id)
        ->and(ViviendaResidente::query()->where('residente_id', $maria->id)->count())->toBe(2);

    $this->get(route('padron.residentes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('residentes.data', 0));
});

test('users without registry access cannot reactivate residents', function () {
    $residente = Residente::query()->create(['residencial_id' => $this->residencial->id, 'nombres' => 'Ana', 'apellidos' => 'Paz', 'activo' => false]);

    $this->actingAs(User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', 'tesorero')->value('id'),
    ]));

    $this->get(route('padron.residentes.index'))->assertOk();

    $this->post(route('padron.residentes.agregar-vivienda', $residente->id), ['vivienda_id' => $this->vivienda->id, 'tipo' => 'propietario'])
        ->assertForbidden();

    expect($residente->refresh()->activo)->toBeFalse();
});

test('every change to residents is audited', function () {
    $this->post(route('padron.residentes.store'), ($this->datos)());

    $maria = Residente::query()->sole();

    $this->put(route('padron.residentes.update', $maria->id), ($this->datos)(['telefono' => '8888-0000']));
    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->segunda->id, 'tipo' => 'propietario']);
    $this->delete(route('padron.residentes.destroy', $maria->id));
    $this->post(route('padron.residentes.agregar-vivienda', $maria->id), ['vivienda_id' => $this->vivienda->id, 'tipo' => 'propietario']);

    $registros = Auditoria::query()->where('modulo', 'padron')->orderBy('id')->get();

    expect($registros->pluck('accion')->all())->toBe([
        'residente_registrado', 'residente_editado', 'residente_vinculado', 'residente_dado_de_baja', 'residente_reactivado',
    ])
        ->and($registros->every(fn (Auditoria $registro) => $registro->user_id === $this->usuario->id && $registro->entidad_id === $maria->id))->toBeTrue()
        ->and($registros[1]->valor_anterior)->toBe(['telefono' => '9999-1111'])
        ->and($registros[1]->valor_nuevo)->toBe(['telefono' => '8888-0000']);
});
