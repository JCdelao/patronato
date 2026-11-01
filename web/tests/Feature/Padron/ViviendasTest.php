<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Finanzas\Models\Tarifa;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Padron\Models\ViviendaResidente;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);

    $this->datos = ['codigo' => 'A-01', 'direccion' => 'Bloque A, Casa 1', 'tipo' => 'casa', 'cuota' => '500'];
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
        ->and($vivienda->calle_bloque)->toBe('Bloque A, Casa 1');

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('padron/viviendas')
            ->where('viviendas.data.0.codigo', 'A-01')
            ->where('viviendas.data.0.cuota', '500.00')
            ->where('opcionesViviendas.0.codigo', 'A-01'));
});

test('the code is unique per residencial and data is validated', function () {
    Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);

    $this->actingAs($this->usuario)
        ->post(route('padron.viviendas.store'), $this->datos)
        ->assertSessionHasErrors('codigo');

    $this->post(route('padron.viviendas.store'), ['codigo' => '', 'direccion' => '', 'tipo' => 'otro', 'cuota' => '-1'])
        ->assertSessionHasErrors(['codigo', 'direccion', 'tipo', 'cuota']);

    Vivienda::query()->create(['residencial_id' => $this->otro->id, 'codigo' => 'B-01', 'tipo' => 'casa']);

    $this->post(route('padron.viviendas.store'), [...$this->datos, 'codigo' => 'B-01'])
        ->assertSessionHasNoErrors();
});

test('a fee change keeps the previous validity', function () {
    $this->actingAs($this->usuario)->post(route('padron.viviendas.store'), $this->datos);

    $vivienda = Vivienda::query()->sole();
    $tarifa = Tarifa::query()->sole();

    $this->put(route('padron.viviendas.update', $vivienda->id), [...$this->datos, 'cuota' => '600'])
        ->assertSessionHasNoErrors();

    expect(Tarifa::query()->sole()->monto)->toBe('600.00');

    DB::table('tarifas')->where('id', $tarifa->id)->update(['vigente_desde' => now()->subMonth()->toDateString()]);

    $this->put(route('padron.viviendas.update', $vivienda->id), [...$this->datos, 'codigo' => 'A-99', 'cuota' => '700'])
        ->assertSessionHasNoErrors();

    expect(Tarifa::query()->count())->toBe(2)
        ->and(Tarifa::query()->whereNull('vigente_hasta')->sole()->monto)->toBe('700.00')
        ->and(Tarifa::query()->whereNotNull('vigente_hasta')->sole()->monto)->toBe('600.00')
        ->and($vivienda->refresh()->codigo)->toBe('A-99');
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
    $ajena = Vivienda::query()->create(['residencial_id' => $this->otro->id, 'codigo' => 'X-01', 'tipo' => 'casa']);
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
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);
    Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'B-02', 'tipo' => 'casa']);

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
