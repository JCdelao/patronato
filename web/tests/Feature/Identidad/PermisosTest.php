<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Identidad\Models\Auditoria;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro']);

    $this->rol = fn (string $codigo) => Rol::query()->where('codigo', $codigo)->value('id');

    $this->crear = fn (string $codigo, ?Residencial $residencial = null) => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($codigo),
    ]);

    $this->admin = ($this->crear)(Rol::ADMIN_TECNICO);
    $this->secretario = ($this->crear)(Rol::SECRETARIO);

    $this->vivienda = ['codigo' => 'A-01', 'direccion' => 'x', 'tipo' => 'casa', 'cuota' => '1'];
});

test('only the technical administrator configures permissions', function (string $rol) {
    $usuario = ($this->crear)($rol);

    $this->actingAs($usuario)->get(route('usuarios.permisos.index'))->assertForbidden();
    $this->actingAs($usuario)
        ->put(route('usuarios.permisos.update', ($this->rol)('fiscal')), ['permisos' => ['padron.ver']])
        ->assertForbidden();
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'fiscal', 'residente']);

test('the matrix lists the eight roles of the story', function () {
    $this->actingAs($this->admin)->get(route('usuarios.permisos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('identidad/permisos')
            ->count('roles', 8)
            ->where('roles', fn ($roles) => collect($roles)->pluck('nombre')->sort()->values()->all() === [
                'Cobrador', 'Fiscal', 'Guardia', 'Presidente', 'Residente', 'Secretario', 'Tesorero', 'Vocal',
            ]));
});

test('a new residencial starts with the default permissions', function () {
    expect(DB::table('rol_permisos')->where('residencial_id', $this->residencial->id)->count())->toBeGreaterThan(0)
        ->and($this->secretario->puede('padron', 'registrar'))->toBeTrue()
        ->and(($this->crear)('guardia')->puede('padron', 'ver'))->toBeFalse();
});

test('a user without permission is denied with the access denied message', function () {
    $guardia = ($this->crear)('guardia');

    $this->actingAs($guardia)->get(route('padron.viviendas.index'))
        ->assertForbidden()
        ->assertSee('Acceso denegado');
});

test('each action is checked separately', function () {
    $tesorero = ($this->crear)('tesorero');
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'B-01', 'tipo' => 'casa']);

    $this->actingAs($tesorero)->get(route('padron.viviendas.index'))->assertOk();
    $this->post(route('padron.viviendas.store'), $this->vivienda)->assertForbidden();
    $this->put(route('padron.viviendas.update', $vivienda->id), $this->vivienda)->assertForbidden();
    $this->delete(route('padron.viviendas.destroy', $vivienda->id))->assertForbidden();

    expect(Vivienda::query()->count())->toBe(1);
});

test('a change takes effect immediately and only for that residencial', function () {
    $otroSecretario = ($this->crear)(Rol::SECRETARIO, $this->otro);

    $this->actingAs($this->admin)
        ->put(route('usuarios.permisos.update', ($this->rol)(Rol::SECRETARIO)), ['permisos' => ['padron.ver']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->secretario)->post(route('padron.viviendas.store'), $this->vivienda)->assertForbidden();
    $this->get(route('padron.viviendas.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permisos', ['padron.ver']));

    $this->actingAs($otroSecretario)->post(route('padron.viviendas.store'), $this->vivienda)->assertSessionHasNoErrors();
});

test('a permission can be granted and all can be removed', function () {
    $cobrador = ($this->crear)('cobrador');

    $this->actingAs($cobrador)->get(route('padron.viviendas.index'))->assertForbidden();

    $this->actingAs($this->admin)->put(route('usuarios.permisos.update', ($this->rol)('cobrador')), ['permisos' => ['padron.ver']]);
    $this->actingAs($cobrador)->get(route('padron.viviendas.index'))->assertOk();

    $this->actingAs($this->admin)->put(route('usuarios.permisos.update', ($this->rol)('cobrador')), []);
    $this->actingAs($cobrador)->get(route('padron.viviendas.index'))->assertForbidden();
});

test('every change is recorded in the audit log', function () {
    $this->actingAs($this->admin)->put(route('usuarios.permisos.update', ($this->rol)(Rol::SECRETARIO)), ['permisos' => ['padron.ver']]);

    $registro = Auditoria::query()->where('accion', 'permisos_actualizados')->sole();

    expect($registro->user_id)->toBe($this->admin->id)
        ->and($registro->residencial_id)->toBe($this->residencial->id)
        ->and($registro->valor_anterior['permisos'])->toContain('padron.registrar')
        ->and($registro->valor_nuevo)->toBe(['permisos' => ['padron.ver']]);

    $this->put(route('usuarios.permisos.update', ($this->rol)(Rol::SECRETARIO)), ['permisos' => ['padron.ver']]);

    expect(Auditoria::query()->where('accion', 'permisos_actualizados')->count())->toBe(1);
});

test('invalid permissions and protected roles are rejected', function () {
    $this->actingAs($this->admin)
        ->put(route('usuarios.permisos.update', ($this->rol)('fiscal')), ['permisos' => ['padron.inventado']])
        ->assertSessionHasErrors('permisos.0');

    $this->put(route('usuarios.permisos.update', ($this->rol)(Rol::ADMIN_TECNICO)), ['permisos' => []])->assertNotFound();
    $this->put(route('usuarios.permisos.update', ($this->rol)(Rol::ADMIN_PLATAFORMA)), ['permisos' => []])->assertNotFound();
});

test('the technical administrator always keeps access', function () {
    $this->actingAs($this->admin)->get(route('padron.viviendas.index'))->assertOk();
    $this->get(route('auditoria.index'))->assertOk();
});

test('the home screen follows the permissions', function () {
    $this->actingAs($this->admin)->put(route('usuarios.permisos.update', ($this->rol)(Rol::SECRETARIO)), []);

    expect($this->secretario->rutaInicio())->toBe('dashboard');
});
