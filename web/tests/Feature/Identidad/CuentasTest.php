<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Identidad\Models\Auditoria;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Padron\Models\ViviendaResidente;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->rol = fn (string $codigo) => Rol::query()->where('codigo', $codigo)->value('id');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro']);

    $this->admin = User::factory()->create([
        'name' => 'Aaa Administrador',
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)(Rol::ADMIN_TECNICO),
    ]);

    $this->ajena = User::factory()->create([
        'residencial_id' => $this->otro->id,
        'rol_id' => ($this->rol)('cobrador'),
    ]);

    $this->datos = fn (array $extra = []) => [
        'name' => 'Luis Cobrador',
        'email' => 'luis@laspalmas.test',
        'rol_id' => ($this->rol)('cobrador'),
        ...$extra,
    ];

    $this->actingAs($this->admin);
});

function residenteCon(Residencial $residencial, string $codigo = 'A-01'): Residente
{
    $vivienda = Vivienda::query()->create(['residencial_id' => $residencial->id, 'codigo' => $codigo, 'tipo' => 'casa']);
    $residente = Residente::query()->create(['residencial_id' => $residencial->id, 'nombres' => 'María', 'apellidos' => $codigo]);

    ViviendaResidente::query()->create([
        'residencial_id' => $residencial->id, 'vivienda_id' => $vivienda->id, 'residente_id' => $residente->id, 'tipo' => 'propietario',
    ]);

    return $residente;
}

test('only the technical administrator manages accounts', function (string $rol) {
    $this->actingAs(User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)($rol),
    ]));

    $this->get(route('usuarios.index'))->assertForbidden();
    $this->post(route('usuarios.store'), ($this->datos)())->assertForbidden();
})->with(['presidente', 'secretario', 'cobrador', 'residente']);

test('the platform administrator does not manage residencial accounts', function () {
    $this->actingAs(User::factory()->create(['rol_id' => ($this->rol)(Rol::ADMIN_PLATAFORMA)]));

    $this->get(route('usuarios.index'))->assertForbidden();
});

test('an account is created with a role and a temporary password shown once', function () {
    $this->post(route('usuarios.store'), ($this->datos)())->assertSessionHasNoErrors();

    $cuenta = User::query()->where('email', 'luis@laspalmas.test')->sole();

    expect($cuenta->residencial_id)->toBe($this->residencial->id)
        ->and($cuenta->rol?->codigo)->toBe('cobrador')
        ->and($cuenta->debe_cambiar_password)->toBeTrue();

    $password = null;

    $this->get(route('usuarios.index'))->assertInertia(function (AssertableInertia $page) use (&$password) {
        $page->where('credenciales.correo', 'luis@laspalmas.test')
            ->where('credenciales.password_temporal', function (string $valor) use (&$password) {
                $password = $valor;

                return true;
            });
    });

    expect(Hash::check($password, $cuenta->password))->toBeTrue();

    $this->get(route('usuarios.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('credenciales', null));
});

test('only accounts of the residencial are listed', function () {
    $this->get(route('usuarios.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('identidad/usuarios')
        ->count('cuentas', 1)
        ->where('cuentas.0.id', $this->admin->id));
});

test('a repeated email or a privileged role is rejected', function (array $extra, string $campo) {
    User::factory()->create(['email' => 'usado@laspalmas.test']);

    $this->post(route('usuarios.store'), ($this->datos)($extra))->assertSessionHasErrors($campo);

    expect(User::query()->where('name', 'Luis Cobrador')->count())->toBe(0);
})->with([
    'correo repetido' => [['email' => 'USADO@laspalmas.test'], 'email'],
    'sin nombre' => [['name' => ''], 'name'],
]);

test('privileged roles cannot be assigned', function (string $rol) {
    $this->post(route('usuarios.store'), ($this->datos)(['rol_id' => ($this->rol)($rol)]))
        ->assertSessionHasErrors('rol_id');
})->with([Rol::ADMIN_PLATAFORMA, Rol::ADMIN_TECNICO]);

test('a resident account requires a resident of the residencial', function () {
    $datos = ($this->datos)(['rol_id' => ($this->rol)(Rol::RESIDENTE)]);

    $this->post(route('usuarios.store'), $datos)->assertSessionHasErrors('residente_id');

    $ajeno = residenteCon($this->otro);

    $this->post(route('usuarios.store'), [...$datos, 'residente_id' => $ajeno->id])
        ->assertSessionHasErrors('residente_id');

    $residente = residenteCon($this->residencial);

    $this->post(route('usuarios.store'), [...$datos, 'residente_id' => $residente->id])
        ->assertSessionHasNoErrors();

    $cuenta = User::query()->where('email', 'luis@laspalmas.test')->sole();

    expect($residente->refresh()->user_id)->toBe($cuenta->id);

    $this->get(route('usuarios.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('cuentas.1.vivienda', 'A-01')
        ->where('cuentas.1.residente_id', $residente->id));

    $this->post(route('usuarios.store'), [...$datos, 'email' => 'otra@laspalmas.test', 'residente_id' => $residente->id])
        ->assertSessionHasErrors('residente_id');
});

test('changing the role away from resident releases the link', function () {
    $residente = residenteCon($this->residencial);

    $this->post(route('usuarios.store'), ($this->datos)(['rol_id' => ($this->rol)(Rol::RESIDENTE), 'residente_id' => $residente->id]));

    $cuenta = User::query()->where('email', 'luis@laspalmas.test')->sole();

    $this->put(route('usuarios.update', $cuenta->id), ($this->datos)())->assertSessionHasNoErrors();

    expect($residente->refresh()->user_id)->toBeNull()
        ->and($cuenta->refresh()->rol?->codigo)->toBe('cobrador');
});

test('an account can be edited keeping its email when unchanged', function () {
    $cuenta = User::factory()->create([
        'email' => 'luis@laspalmas.test',
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('vocal'),
    ]);

    $this->put(route('usuarios.update', $cuenta->id), ($this->datos)(['name' => 'Nuevo']))->assertSessionHasNoErrors();

    expect($cuenta->refresh()->name)->toBe('Nuevo')
        ->and(Auditoria::query()->where('accion', 'cuenta_editada')->count())->toBe(1);
});

test('a deactivated account cannot log in and keeps its records', function () {
    $cuenta = User::factory()->create([
        'email' => 'luis@laspalmas.test',
        'password' => 'clave-correcta1',
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('cobrador'),
    ]);

    DB::table('cajas')->insert(['residencial_id' => $this->residencial->id, 'cobrador_id' => $cuenta->id, 'abierta_en' => now()]);

    $this->post(route('usuarios.desactivar', $cuenta->id))->assertSessionHasNoErrors();

    auth()->logout();

    $this->post(route('login.store'), ['email' => 'luis@laspalmas.test', 'password' => 'clave-correcta1'])
        ->assertSessionHasErrors(['email' => User::MENSAJE_CUENTA_INACTIVA]);

    $this->assertGuest();

    expect(User::query()->whereKey($cuenta->id)->exists())->toBeTrue()
        ->and(DB::table('cajas')->where('cobrador_id', $cuenta->id)->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('usuarios.activar', $cuenta->id))->assertSessionHasNoErrors();
    auth()->logout();

    $this->post(route('login.store'), ['email' => 'luis@laspalmas.test', 'password' => 'clave-correcta1']);

    $this->assertAuthenticatedAs($cuenta);
});

test('an open session ends when the account is deactivated', function () {
    $cuenta = User::factory()->create(['residencial_id' => $this->residencial->id, 'activo' => false]);

    $this->actingAs($cuenta->refresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', User::MENSAJE_CUENTA_INACTIVA);
});

test('another residencial account is denied and audited, even with invalid data', function (Closure $peticion) {
    $peticion($this, $this->ajena->id)->assertForbidden()->assertSee('Acceso denegado');

    expect(Auditoria::query()->where('accion', 'acceso_denegado')->where('entidad_id', $this->ajena->id)->count())->toBe(1)
        ->and($this->ajena->refresh()->activo)->toBeTrue();
})->with([
    'editar' => [fn ($t, int $id) => $t->put(route('usuarios.update', $id), [])],
    'desactivar' => [fn ($t, int $id) => $t->post(route('usuarios.desactivar', $id))],
    'activar' => [fn ($t, int $id) => $t->post(route('usuarios.activar', $id))],
]);

test('the own account and other technical administrators are protected', function () {
    $otroTecnico = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)(Rol::ADMIN_TECNICO),
    ]);

    foreach ([$this->admin, $otroTecnico] as $protegida) {
        $this->post(route('usuarios.desactivar', $protegida->id))->assertForbidden();
        $this->put(route('usuarios.update', $protegida->id), ($this->datos)())->assertForbidden();
    }

    $this->get(route('usuarios.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('cuentas.0.editable', false)
        ->where('cuentas.1.editable', false));
});

test('access parameters are saved per residencial and validated', function () {
    $datos = ['max_intentos_login' => 3, 'minutos_bloqueo_login' => 40, 'minutos_inactividad_sesion' => 60];

    $this->put(route('usuarios.parametros'), $datos)->assertSessionHasNoErrors();

    $this->get(route('usuarios.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('parametros.max_intentos_login', 3)
        ->where('parametros.minutos_inactividad_sesion', 60));

    expect(DB::table('configuracion_residencial')->where('residencial_id', $this->otro->id)->exists())->toBeFalse();

    $this->put(route('usuarios.parametros'), [...$datos, 'max_intentos_login' => 0, 'minutos_inactividad_sesion' => 1])
        ->assertSessionHasErrors(['max_intentos_login', 'minutos_inactividad_sesion']);
});
