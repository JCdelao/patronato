<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Negocio\Services\QrResidenteService;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\QrResidente;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\QrResidenteRepositorio;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'El Bosque']);
    $this->cuenta = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', Rol::RESIDENTE)->value('id'),
        'activo' => true,
    ]);
    $this->residente = Residente::query()->create([
        'residencial_id' => $this->residencial->id,
        'user_id' => $this->cuenta->id,
        'nombres' => 'Rosa',
        'apellidos' => 'Vecina',
        'activo' => true,
    ]);
    $hoy = app(PeriodoService::class)->hoy()->toDateString();
    $this->carnet = Carnet::query()->create([
        'residencial_id' => $this->residencial->id,
        'residente_id' => $this->residente->id,
        'numero' => 'C-0001',
        'fecha_emision' => $hoy,
        'vigente_hasta' => $hoy,
        'estado' => Carnet::ACTIVO,
    ]);
});

test('the resident receives only a random UUID and repeated issuance keeps the active code', function () {
    $response = $this->actingAs($this->cuenta)->postJson(route('portal.qr.emitir'), [
        'residente_id' => $this->residente->id + 1,
        'residencial_id' => $this->otro->id,
        'token' => 'untrusted',
    ])->assertOk();

    $token = $response->json('token');
    expect(Str::isUuid($token, 4))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    $response->assertExactJson(['token' => $token]);
    $this->postJson(route('portal.qr.emitir'))->assertExactJson(['token' => $token]);
    $this->assertDatabaseHas('qr_residentes', [
        'residente_id' => $this->residente->id,
        'residencial_id' => $this->residencial->id,
        'token' => $token,
        'activo' => true,
    ]);
    $this->assertDatabaseCount('qr_residentes', 1);
    expect(QrResidente::query()->firstOrFail()->toArray())->not->toHaveKey('token');
});

test('opening the QR page does not issue a code', function () {
    $this->withoutVite();

    $this->actingAs($this->cuenta)->get(route('portal.qr.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('portal/mi-qr'));

    $this->assertDatabaseCount('qr_residentes', 0);
});

test('the portal can request a local QR image and replace it by regenerating', function () {
    $this->actingAs($this->cuenta);
    $headers = ['Accept' => 'image/svg+xml'];
    $anterior = $this->post(route('portal.qr.emitir'), [], $headers)->assertOk();
    $nuevo = $this->post(route('portal.qr.regenerar'), [], $headers)->assertOk();

    foreach ([$anterior, $nuevo] as $respuesta) {
        $respuesta->assertHeader('Content-Type', 'image/svg+xml');
        expect($respuesta->headers->hasCacheControlDirective('no-store'))->toBeTrue()
            ->and($respuesta->getContent())->toContain('<svg', '<path');
    }

    expect($nuevo->getContent())->not->toBe($anterior->getContent());
    $this->assertDatabaseCount('qr_residentes', 2);
    expect(QrResidente::query()->where('activo', true)->count())->toBe(1);
});

test('regeneration immediately invalidates the previous code', function () {
    $this->actingAs($this->cuenta);
    $anterior = $this->postJson(route('portal.qr.emitir'))->assertOk()->json('token');
    $nuevo = $this->postJson(route('portal.qr.regenerar'))->assertOk()->json('token');
    $servicio = app(QrResidenteService::class);

    expect($nuevo)->not->toBe($anterior)
        ->and($servicio->esValido($this->residencial->id, $anterior))->toBeFalse()
        ->and($servicio->esValido($this->residencial->id, $nuevo))->toBeTrue();
    $this->assertDatabaseHas('qr_residentes', ['token' => $anterior, 'activo' => false]);
    expect(QrResidente::query()->where('token', $anterior)->whereNotNull('invalidado_en')->exists())->toBeTrue()
        ->and(QrResidente::query()->where('activo', true)->count())->toBe(1);
});

test('failed regeneration preserves the previous active code', function () {
    $servicio = app(QrResidenteService::class);
    $anterior = $servicio->emitir($this->cuenta);
    $this->partialMock(QrResidenteRepositorio::class)
        ->shouldReceive('emitir')->once()->andThrow(new RuntimeException('Error de escritura simulado.'));

    expect(fn () => app(QrResidenteService::class)->emitir($this->cuenta, regenerar: true))
        ->toThrow(RuntimeException::class, 'Error de escritura simulado.');

    $this->assertDatabaseHas('qr_residentes', ['token' => $anterior, 'activo' => true, 'invalidado_en' => null]);
    $this->assertDatabaseCount('qr_residentes', 1);
});

test('validation is scoped to the residential even without an authenticated session', function () {
    $servicio = app(QrResidenteService::class);
    $token = $servicio->emitir($this->cuenta);

    expect($servicio->esValido($this->residencial->id, $token))->toBeTrue()
        ->and($servicio->esValido($this->otro->id, $token))->toBeFalse()
        ->and($servicio->esValido($this->residencial->id, 'invalid'))->toBeFalse();
});

test('codes become unusable when account, resident or card eligibility changes', function (string $caso) {
    $servicio = app(QrResidenteService::class);
    $token = $servicio->emitir($this->cuenta);

    match ($caso) {
        'cuenta inactiva' => $this->cuenta->forceFill(['activo' => false])->save(),
        'residente inactivo' => $this->residente->update(['activo' => false]),
        'cuenta desvinculada' => $this->residente->update(['user_id' => null]),
        'carnet suspendido' => $this->carnet->update(['estado' => Carnet::SUSPENDIDO]),
        'carnet dado de baja' => $this->carnet->update(['estado' => Carnet::DADO_DE_BAJA]),
        'fecha vencida' => $this->carnet->update([
            'vigente_hasta' => app(PeriodoService::class)->hoy()->subDay()->toDateString(),
        ]),
    };

    expect($servicio->esValido($this->residencial->id, $token))->toBeFalse();
    $respuesta = $this->actingAs($this->cuenta)->postJson(route('portal.qr.regenerar'));

    if ($caso === 'cuenta inactiva') {
        $respuesta->assertRedirect(route('login'));
    } else {
        $respuesta->assertForbidden();
    }

    $this->assertDatabaseCount('qr_residentes', 1);
})->with([
    'cuenta inactiva', 'residente inactivo', 'cuenta desvinculada',
    'carnet suspendido', 'carnet dado de baja', 'fecha vencida',
]);

test('the QR routes require authentication and portal permission', function () {
    $this->get(route('portal.qr.show'))->assertRedirect(route('login'));
    $this->postJson(route('portal.qr.emitir'))->assertUnauthorized();
    $cuenta = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', 'guardia')->value('id'),
    ]);
    $this->actingAs($cuenta)->get(route('portal.qr.show'))->assertForbidden();
    $this->postJson(route('portal.qr.emitir'))->assertForbidden();
    $this->assertDatabaseCount('qr_residentes', 0);
});
