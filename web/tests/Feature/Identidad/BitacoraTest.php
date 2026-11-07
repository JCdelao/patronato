<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Identidad\Models\Auditoria;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro']);

    $rol = fn (string $codigo) => Rol::query()->where('codigo', $codigo)->value('id');

    $this->fiscal = User::factory()->create(['residencial_id' => $this->residencial->id, 'rol_id' => $rol('fiscal')]);
    $this->secretario = User::factory()->create(['residencial_id' => $this->residencial->id, 'rol_id' => $rol(Rol::SECRETARIO)]);
    $this->guardia = User::factory()->create(['residencial_id' => $this->residencial->id, 'rol_id' => $rol('guardia')]);

    $this->registro = fn (array $datos) => Auditoria::query()->forceCreate([
        'residencial_id' => $this->residencial->id,
        'user_id' => $this->secretario->id,
        'modulo' => 'finanzas',
        'accion' => 'pago_anulado',
        ...$datos,
    ]);
});

test('only roles with the permission can read the log', function () {
    $this->actingAs($this->fiscal)->get(route('auditoria.index'))->assertOk();
    $this->actingAs($this->secretario)->get(route('auditoria.index'))->assertForbidden();
    $this->actingAs($this->guardia)->get(route('auditoria.index'))->assertForbidden();
    auth()->logout();
    $this->get(route('auditoria.index'))->assertRedirect(route('login'));
});

test('the log shows who, what, when, why and the values', function () {
    ($this->registro)(['motivo' => 'Error de digitación', 'valor_anterior' => ['monto' => 500], 'valor_nuevo' => ['monto' => 0]]);

    $this->actingAs($this->fiscal)->get(route('auditoria.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('identidad/auditoria')
            ->where('registros.data.0.usuario', $this->secretario->name)
            ->where('registros.data.0.accion', 'pago_anulado')
            ->where('registros.data.0.motivo', 'Error de digitación')
            ->where('registros.data.0.cambios', [['campo' => 'Monto', 'antes' => '500', 'despues' => '0']])
            ->has('registros.data.0.fecha'));
});

test('the log shows names instead of ids', function () {
    $rol = Rol::query()->where('codigo', Rol::RESIDENTE)->sole();
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);

    ($this->registro)([
        'modulo' => 'identidad',
        'accion' => 'cuenta_creada',
        'entidad' => 'users',
        'entidad_id' => $this->guardia->id,
        'valor_nuevo' => ['email' => 'luis@laspalmas.test', 'rol_id' => $rol->id, 'vivienda_id' => $vivienda->id],
    ]);
    ($this->registro)(['entidad' => 'viviendas', 'entidad_id' => 999, 'valor_anterior' => ['permisos' => ['padron.ver']]]);

    $this->actingAs($this->fiscal)->get(route('auditoria.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registros.data.1.referencia', "Cuenta: {$this->guardia->name}")
            ->where('registros.data.1.cambios', [
                ['campo' => 'Correo', 'antes' => null, 'despues' => 'luis@laspalmas.test'],
                ['campo' => 'Rol', 'antes' => null, 'despues' => $rol->nombre],
                ['campo' => 'Vivienda', 'antes' => null, 'despues' => 'A-01'],
            ])
            ->where('registros.data.0.referencia', 'Vivienda (ya no existe)')
            ->where('registros.data.0.cambios.0.antes', 'Viviendas y residentes: ver'));
});

test('only records of the residencial are visible', function () {
    ($this->registro)(['accion' => 'propio']);
    ($this->registro)(['accion' => 'ajeno', 'residencial_id' => $this->otro->id]);

    $this->actingAs($this->fiscal)->get(route('auditoria.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('registros.data', 1)
            ->where('registros.data.0.accion', 'propio')
            ->where('acciones', ['propio']));
});

test('the log can be filtered', function (array $filtros, array $esperadas) {
    ($this->registro)(['accion' => 'a', 'modulo' => 'finanzas', 'created_at' => '2026-03-10 18:00:00']);
    ($this->registro)(['accion' => 'b', 'modulo' => 'identidad', 'created_at' => '2026-03-20 18:00:00']);
    ($this->registro)(['accion' => 'c', 'modulo' => 'finanzas', 'user_id' => $this->fiscal->id, 'created_at' => '2026-03-30 18:00:00']);

    if (isset($filtros['user_id'])) {
        $filtros['user_id'] = $this->fiscal->id;
    }

    $this->actingAs($this->fiscal)->get(route('auditoria.index', $filtros))
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'registros.data',
            fn ($datos) => collect($datos)->pluck('accion')->sort()->values()->all() === $esperadas,
        ));
})->with([
    'sin filtros' => [[], ['a', 'b', 'c']],
    'opcion todos' => [['modulo' => 'todos', 'accion' => 'todos', 'user_id' => null], ['a', 'b', 'c']],
    'desde' => [['desde' => '2026-03-15'], ['b', 'c']],
    'hasta' => [['hasta' => '2026-03-15'], ['a']],
    'rango' => [['desde' => '2026-03-15', 'hasta' => '2026-03-25'], ['b']],
    'modulo' => [['modulo' => 'identidad'], ['b']],
    'accion' => [['accion' => 'c'], ['c']],
    'usuario' => [['user_id' => 'x'], ['c']],
    'combinados' => [['modulo' => 'finanzas', 'desde' => '2026-03-15'], ['c']],
]);

test('date filters use the local day, not the UTC day', function () {
    // 20 de marzo a las 10 p. m. en Honduras son las 4 a. m. del 21 en UTC
    ($this->registro)(['accion' => 'noche', 'created_at' => '2026-03-21 04:00:00']);

    $this->actingAs($this->fiscal)->get(route('auditoria.index', ['hasta' => '2026-03-20']))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('registros.data', 1));

    $this->get(route('auditoria.index', ['desde' => '2026-03-21']))
        ->assertInertia(fn (AssertableInertia $page) => $page->count('registros.data', 0));
});

test('no route can change the log', function () {
    $this->actingAs($this->fiscal);

    $this->put(route('auditoria.index'))->assertMethodNotAllowed();
    $this->delete(route('auditoria.index'))->assertMethodNotAllowed();
});

test('the model refuses to edit or delete records', function (string $operacion) {
    $registro = ($this->registro)([]);

    expect(fn () => $operacion === 'editar' ? $registro->update(['accion' => 'x']) : $registro->delete())
        ->toThrow(LogicException::class);

    expect(Auditoria::query()->sole()->accion)->toBe('pago_anulado');
})->with(['editar', 'eliminar']);

test('pages are limited', function () {
    Carbon::setTestNow('2026-03-01 12:00:00');

    foreach (range(1, 30) as $numero) {
        ($this->registro)([]);
    }

    $this->actingAs($this->fiscal)->get(route('auditoria.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('registros.data', 25)
            ->whereNot('registros.next_page_url', null));
});
