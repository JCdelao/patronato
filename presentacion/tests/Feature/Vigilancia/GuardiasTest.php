<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Notificaciones\CuentaCreada;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->presidente = ($this->usuario)('presidente');

    $this->datos = fn (array $cambios = []): array => [
        'nombre' => '  Carlos Mejía ',
        'identidad' => '0801-1990-12345',
        'telefono' => '9999-0000',
        'turno' => 'noche',
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route('vigilancia.guardias.store'), ($this->datos)($cambios));

    $this->guardia = fn (array $datos = [], ?Residencial $residencial = null): Guardia => Guardia::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'nombre' => 'Pedro Rivera',
        'identidad' => '0801-1985-00001',
        'telefono' => '8888-0000',
        'turno' => '24_horas',
        'activo' => true,
        ...$datos,
    ]);

    $this->crearCuenta = fn (Guardia $guardia, string $correo = 'pedro@laspalmas.test', ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route('vigilancia.guardias.cuenta', $guardia), ['email' => $correo]);
});

test('the president registers a guard with name, identity, phone, shift and active status', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    $guardia = Guardia::query()->sole();

    expect($guardia->residencial_id)->toBe($this->residencial->id)
        ->and($guardia->nombre)->toBe('Carlos Mejía')
        ->and($guardia->identidad)->toBe('0801-1990-12345')
        ->and($guardia->telefono)->toBe('9999-0000')
        ->and($guardia->activo)->toBeTrue()
        ->and($guardia->turno)->toBe('noche')
        ->and($guardia->user_id)->toBeNull();

    $auditoria = Auditoria::query()->where('accion', 'guardia_registrado')->sole();

    expect($auditoria->modulo)->toBe('vigilancia')
        ->and($auditoria->user_id)->toBe($this->presidente->id)
        ->and($auditoria->entidad_id)->toBe($guardia->id)
        ->and($auditoria->valor_nuevo['identidad'])->toBe('0801-1990-12345');
});

test('every shift option can be assigned', function (string $turno) {
    ($this->registrar)(['turno' => $turno])->assertSessionHasNoErrors();

    expect(Guardia::query()->sole()->turno)->toBe($turno);
})->with(Guardia::TURNOS);

test('name, identity, phone and a valid shift are required', function (array $cambios, string $campo) {
    ($this->registrar)($cambios)->assertSessionHasErrors($campo);

    expect(Guardia::query()->count())->toBe(0);
})->with([
    'sin nombre' => [['nombre' => ''], 'nombre'],
    'sin identidad' => [['identidad' => ''], 'identidad'],
    'sin teléfono' => [['telefono' => ''], 'telefono'],
    'sin turno' => [['turno' => ''], 'turno'],
    'turno inválido' => [['turno' => 'madrugada'], 'turno'],
]);

test('the identity cannot repeat within the residencial but can in another one', function () {
    ($this->guardia)(['identidad' => '0801-1990-12345']);

    ($this->registrar)()->assertSessionHasErrors('identidad');

    ($this->registrar)([], ($this->usuario)('presidente', $this->otro))->assertSessionHasNoErrors();

    expect(Guardia::query()->withoutGlobalScopes()->where('identidad', '0801-1990-12345')->count())->toBe(2);
});

test('the president edits a guard and only the changed fields are audited', function () {
    $guardia = ($this->guardia)();

    $this->actingAs($this->presidente)
        ->put(route('vigilancia.guardias.update', $guardia), ($this->datos)(['nombre' => 'Pedro Rivera', 'identidad' => '0801-1985-00001', 'telefono' => '8888-0000']))
        ->assertSessionHasNoErrors();

    expect($guardia->fresh()->turno)->toBe('noche');

    $auditoria = Auditoria::query()->where('accion', 'guardia_editado')->sole();

    expect($auditoria->valor_anterior)->toBe(['turno' => '24_horas'])
        ->and($auditoria->valor_nuevo)->toBe(['turno' => 'noche']);
});

test('deactivating a guard also deactivates its account and activating restores both', function () {
    Notification::fake();
    $guardia = ($this->guardia)();
    ($this->crearCuenta)($guardia)->assertSessionHasNoErrors();
    $cuenta = User::query()->where('email', 'pedro@laspalmas.test')->sole();

    $this->post(route('vigilancia.guardias.desactivar', $guardia))->assertSessionHasNoErrors();

    expect($guardia->fresh()->activo)->toBeFalse()
        ->and($cuenta->fresh()->activo)->toBeFalse()
        ->and(Auditoria::query()->where('accion', 'guardia_desactivado')->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'cuenta_desactivada')->count())->toBe(1);

    $this->post(route('vigilancia.guardias.desactivar', $guardia))->assertSessionHasErrors('guardia');

    $this->post(route('vigilancia.guardias.activar', $guardia))->assertSessionHasNoErrors();

    expect($guardia->fresh()->activo)->toBeTrue()
        ->and($cuenta->fresh()->activo)->toBeTrue();
});

test('an inactive guard cannot be edited', function () {
    $guardia = ($this->guardia)(['activo' => false]);

    $this->actingAs($this->presidente)
        ->put(route('vigilancia.guardias.update', $guardia), ($this->datos)())
        ->assertNotFound();
});

test('an active guard gets an account with the guard role linked to it', function () {
    Notification::fake();
    $guardia = ($this->guardia)();

    ($this->crearCuenta)($guardia, '  PEDRO@laspalmas.test ')->assertSessionHasNoErrors();

    $cuenta = User::query()->where('email', 'pedro@laspalmas.test')->sole();

    expect($cuenta->rol?->codigo)->toBe(Rol::GUARDIA)
        ->and($cuenta->name)->toBe('Pedro Rivera')
        ->and($cuenta->residencial_id)->toBe($this->residencial->id)
        ->and($cuenta->debe_cambiar_password)->toBeTrue()
        ->and($guardia->fresh()->user_id)->toBe($cuenta->id)
        ->and(Auditoria::query()->where('accion', 'cuenta_creada')->where('entidad_id', $cuenta->id)->count())->toBe(1);

    Notification::assertSentTo($cuenta, CuentaCreada::class, fn (CuentaCreada $aviso) => Hash::check($aviso->passwordTemporal, $cuenta->password)
        && $aviso->rol === 'Guardia');
});

test('the temporary password of the guard is shown once when the email fails', function () {
    config(['mail.default' => 'log']);
    $guardia = ($this->guardia)();

    ($this->crearCuenta)($guardia)->assertSessionHasNoErrors();

    $this->get(route('vigilancia.guardias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('credenciales.correo', 'pedro@laspalmas.test'));

    $this->get(route('vigilancia.guardias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('credenciales', null));
});

test('an inactive guard or one that already has an account does not get a new one', function () {
    Notification::fake();
    $inactivo = ($this->guardia)(['activo' => false]);

    ($this->crearCuenta)($inactivo)->assertSessionHasErrors('email');

    $guardia = ($this->guardia)(['identidad' => '0801-1985-00002']);
    ($this->crearCuenta)($guardia)->assertSessionHasNoErrors();
    ($this->crearCuenta)($guardia, 'otra@laspalmas.test')->assertSessionHasErrors('email');

    expect(User::query()->whereIn('email', ['pedro@laspalmas.test', 'otra@laspalmas.test'])->count())->toBe(1);
});

test('a repeated email is rejected', function () {
    User::factory()->create(['email' => 'usado@laspalmas.test']);

    ($this->crearCuenta)(($this->guardia)(), 'usado@laspalmas.test')->assertSessionHasErrors('email');
});

test('the list shows active or inactive guards with their shift and searches them', function () {
    ($this->guardia)();
    ($this->guardia)(['nombre' => 'Óscar Núñez', 'identidad' => '0801-1985-00002', 'activo' => false]);
    ($this->guardia)(['nombre' => 'Ajeno'], $this->otro);

    $this->actingAs($this->presidente)->get(route('vigilancia.guardias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/guardias')
            ->has('guardias.data', 1)
            ->where('guardias.data.0.nombre', 'Pedro Rivera')
            ->where('guardias.data.0.turno', '24_horas')
            ->where('guardias.data.0.cuenta', null));

    $this->get(route('vigilancia.guardias.index', ['estado' => 'inactivos', 'buscar' => 'oscar']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('guardias.data', 1)
            ->where('guardias.data.0.nombre', 'Óscar Núñez'));
});

test('only roles with the vigilance permission see and register guards', function () {
    $secretario = ($this->usuario)('secretario');

    $this->actingAs($secretario)->get(route('vigilancia.guardias.index'))->assertForbidden();
    ($this->registrar)([], $secretario)->assertForbidden();
    ($this->registrar)([], ($this->usuario)('guardia'))->assertForbidden();

    expect(Guardia::query()->count())->toBe(0);
});

test('the guard role does not see guards, shift scheduling or the incident catalog', function () {
    $guardia = ($this->usuario)('guardia');

    $this->actingAs($guardia)->get(route('vigilancia.guardias.index'))->assertForbidden();
    $this->get(route('vigilancia.turnos.index'))->assertForbidden();
    $this->get(route('vigilancia.incidencias.index'))->assertForbidden();
    $this->post(route('vigilancia.turnos.programar'), [])->assertForbidden();
    $this->post(route('vigilancia.tipos-incidencia.store'), ['nombre' => 'Otro tipo'])->assertForbidden();

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('entrega_turnos.registrar')
            && collect($permisos)->contains('incidencias.registrar')
            && ! collect($permisos)->contains('guardias.ver')));
});

test('each part of the vigilance can be allowed on its own', function () {
    $secretario = ($this->usuario)('secretario');
    $rolId = (int) $secretario->rol_id;

    DB::table('rol_permisos')->insert(['residencial_id' => $this->residencial->id, 'rol_id' => $rolId, 'modulo' => 'turnos', 'accion' => 'ver']);

    $this->actingAs($secretario)->get(route('vigilancia.turnos.index'))->assertOk();
    $this->get(route('vigilancia.guardias.index'))->assertForbidden();
    $this->get(route('vigilancia.incidencias.index'))->assertForbidden();
    $this->post(route('vigilancia.turnos.programar'), [])->assertForbidden();
});

test('a guard of another residencial cannot be changed', function () {
    $ajeno = ($this->guardia)([], $this->otro);

    $this->actingAs($this->presidente)->post(route('vigilancia.guardias.desactivar', $ajeno))->assertForbidden();
    ($this->crearCuenta)($ajeno)->assertForbidden();

    expect($ajeno->fresh()->activo)->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->count())->toBe(2);
});
