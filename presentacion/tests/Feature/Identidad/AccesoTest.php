<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    DB::table('configuracion_residencial')->insert(['residencial_id' => $this->residencial->id]);

    $this->usuario = User::factory()->create([
        'email' => 'ana@laspalmas.test',
        'password' => 'clave-correcta1',
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);

    $this->fallar = function (int $veces, string $correo = 'ana@laspalmas.test') {
        foreach (range(1, $veces) as $intento) {
            $this->post(route('login.store'), ['email' => $correo, 'password' => 'mala']);
        }
    };

    $this->entrar = fn () => $this->post(route('login.store'), ['email' => 'ana@laspalmas.test', 'password' => 'clave-correcta1']);
});

test('each role lands on its home screen', function (string $rol, string $ruta) {
    $residencial = $rol === Rol::ADMIN_PLATAFORMA ? null : $this->residencial->id;

    $usuario = User::factory()->create([
        'residencial_id' => $residencial,
        'rol_id' => Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'password'])
        ->assertRedirect(route($ruta));
})->with([
    'plataforma' => [Rol::ADMIN_PLATAFORMA, 'plataforma.residenciales.index'],
    'tecnico' => [Rol::ADMIN_TECNICO, 'padron.viviendas.index'],
    'secretario' => [Rol::SECRETARIO, 'padron.viviendas.index'],
    'cobrador' => ['cobrador', 'dashboard'],
]);

test('the account locks after the configured failed attempts', function () {
    ($this->fallar)(4);
    ($this->entrar)();
    $this->assertAuthenticated();
    auth()->logout();

    ($this->fallar)(5);

    ($this->entrar)()->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain('Demasiados intentos');
});

test('a correct login resets the failed attempts', function () {
    ($this->fallar)(4);
    ($this->entrar)();
    auth()->logout();

    ($this->fallar)(4);
    ($this->entrar)();

    $this->assertAuthenticated();
});

test('the lock expires after the configured minutes', function () {
    ($this->fallar)(5);

    $this->travel(16)->minutes();

    ($this->entrar)();

    $this->assertAuthenticated();
});

test('the lock uses the values of the residencial', function () {
    DB::table('configuracion_residencial')->where('residencial_id', $this->residencial->id)
        ->update(['max_intentos_login' => 2, 'minutos_bloqueo_login' => 60]);

    ($this->fallar)(2);

    $this->travel(30)->minutes();

    ($this->entrar)();
    $this->assertGuest();

    $this->travel(31)->minutes();

    ($this->entrar)();
    $this->assertAuthenticated();
});

test('unknown emails behave like existing ones', function () {
    ($this->fallar)(5, 'nadie@laspalmas.test');

    $this->post(route('login.store'), ['email' => 'nadie@laspalmas.test', 'password' => 'x'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Demasiados intentos');
});

test('the session closes after the configured inactivity', function () {
    $this->actingAs($this->usuario)->get(route('dashboard'))->assertOk();

    $this->travel(29)->minutes();
    $this->get(route('dashboard'))->assertOk();

    $this->travel(31)->minutes();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->assertGuest();
});

test('the inactivity limit is configurable', function () {
    DB::table('configuracion_residencial')->where('residencial_id', $this->residencial->id)
        ->update(['minutos_inactividad_sesion' => 120]);

    $this->actingAs($this->usuario)->get(route('dashboard'))->assertOk();

    $this->travel(60)->minutes();

    $this->get(route('dashboard'))->assertOk();
});
