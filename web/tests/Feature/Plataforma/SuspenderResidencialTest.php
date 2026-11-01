<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Identidad\Models\Auditoria;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->admin = User::factory()->create([
        'rol_id' => Rol::query()->where('codigo', Rol::ADMIN_PLATAFORMA)->value('id'),
    ]);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro']);

    $this->usuario = User::factory()->create([
        'email' => 'ana@laspalmas.test',
        'password' => 'clave-correcta',
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::SECRETARIO)->value('id'),
    ]);

    $this->suspender = fn () => $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.suspender', $this->residencial->id), ['motivo' => 'Fin de contrato']);
});

test('only platform administrators can change the state', function () {
    $this->actingAs($this->usuario)
        ->post(route('plataforma.residenciales.suspender', $this->otro->id), ['motivo' => 'x'])
        ->assertForbidden();

    $this->post(route('plataforma.residenciales.reactivar', $this->otro->id), ['motivo' => 'x'])
        ->assertForbidden();

    expect($this->otro->refresh()->estado)->toBe(Residencial::ACTIVO)
        ->and(Auditoria::query()->count())->toBe(0);
});

test('the reason is required', function () {
    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.suspender', $this->residencial->id), ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    expect($this->residencial->refresh()->estado)->toBe(Residencial::ACTIVO);
});

test('users of a suspended residencial cannot log in and see the explanation', function () {
    ($this->suspender)();
    auth()->logout();

    $this->post(route('login.store'), ['email' => 'ana@laspalmas.test', 'password' => 'clave-correcta'])
        ->assertSessionHasErrors(['email' => User::MENSAJE_RESIDENCIAL_SUSPENDIDO]);

    $this->assertGuest();
});

test('a wrong password does not reveal the suspension', function () {
    ($this->suspender)();
    auth()->logout();

    $respuesta = $this->post(route('login.store'), ['email' => 'ana@laspalmas.test', 'password' => 'mala']);

    $respuesta->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->not->toBe(User::MENSAJE_RESIDENCIAL_SUSPENDIDO);
});

test('an open session is closed when its residencial is suspended', function () {
    $this->actingAs($this->usuario)->get(route('padron.viviendas.index'))->assertOk();

    $this->residencial->update(['estado' => Residencial::SUSPENDIDO]);

    $this->actingAs($this->usuario->fresh())
        ->get(route('padron.viviendas.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', User::MENSAJE_RESIDENCIAL_SUSPENDIDO);

    $this->assertGuest();
});

test('other residenciales and the platform administrator keep working', function () {
    $otroUsuario = User::factory()->create([
        'email' => 'luis@otro.test',
        'password' => 'clave-correcta',
        'residencial_id' => $this->otro->id,
    ]);

    ($this->suspender)();

    $this->actingAs($this->admin)->get(route('plataforma.residenciales.index'))->assertOk();
    auth()->logout();

    $this->post(route('login.store'), ['email' => $otroUsuario->email, 'password' => 'clave-correcta']);

    $this->assertAuthenticatedAs($otroUsuario);
});

test('reactivating restores access with the data intact', function () {
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo' => 'casa']);

    ($this->suspender)();

    $this->post(route('plataforma.residenciales.reactivar', $this->residencial->id), ['motivo' => 'Contrato renovado'])
        ->assertSessionHasNoErrors();

    expect($this->residencial->refresh()->estado)->toBe(Residencial::ACTIVO);

    auth()->logout();

    $this->post(route('login.store'), ['email' => 'ana@laspalmas.test', 'password' => 'clave-correcta']);

    $this->assertAuthenticatedAs($this->usuario);

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas.data.0.id', $vivienda->id));
});

test('both changes are recorded with date, user and reason', function () {
    ($this->suspender)();

    $this->post(route('plataforma.residenciales.reactivar', $this->residencial->id), ['motivo' => 'Contrato renovado']);

    $registros = Auditoria::query()->orderBy('id')->get();

    expect($registros)->toHaveCount(2);

    [$suspension, $reactivacion] = $registros;

    expect($suspension->accion)->toBe('residencial_suspendido')
        ->and($suspension->user_id)->toBe($this->admin->id)
        ->and($suspension->residencial_id)->toBe($this->residencial->id)
        ->and($suspension->entidad_id)->toBe($this->residencial->id)
        ->and($suspension->motivo)->toBe('Fin de contrato')
        ->and($suspension->created_at)->not->toBeNull()
        ->and($suspension->valor_nuevo)->toBe(['estado' => 'suspendido'])
        ->and($reactivacion->accion)->toBe('residencial_activo')
        ->and($reactivacion->motivo)->toBe('Contrato renovado');
});

test('repeating the current state changes nothing', function () {
    ($this->suspender)();
    ($this->suspender)();

    expect(Auditoria::query()->count())->toBe(1);
});

test('the list shows the suspension reason only while suspended', function () {
    ($this->suspender)();

    $motivo = fn (?string $esperado) => fn (AssertableInertia $page) => $page->where('residenciales.0.motivo_suspension', $esperado);

    $this->get(route('plataforma.residenciales.index'))->assertInertia($motivo('Fin de contrato'));

    $this->post(route('plataforma.residenciales.reactivar', $this->residencial->id), ['motivo' => 'Renovado']);

    $this->get(route('plataforma.residenciales.index'))->assertInertia($motivo(null));
});
