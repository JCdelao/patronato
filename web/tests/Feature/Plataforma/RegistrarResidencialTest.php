<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Plataforma\Models\Residencial;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->admin = User::factory()->create([
        'rol_id' => Rol::query()->where('codigo', Rol::ADMIN_PLATAFORMA)->value('id'),
    ]);

    $this->datos = [
        'nombre' => 'Residencial Las Palmas',
        'direccion' => 'Col. Las Palmas',
        'telefono' => '2222-3333',
        'correo' => 'patronato@laspalmas.test',
        'admin_nombre' => 'Ana Técnica',
        'admin_correo' => 'ana@laspalmas.test',
    ];
});

test('only platform administrators can manage residenciales', function () {
    $this->get(route('plataforma.residenciales.index'))->assertRedirect(route('login'));

    $otro = User::factory()->create();

    $this->actingAs($otro)->get(route('plataforma.residenciales.index'))->assertForbidden();
    $this->actingAs($otro)->get(route('plataforma.residenciales.create'))->assertForbidden();
    $this->actingAs($otro)->post(route('plataforma.residenciales.store'), $this->datos)->assertForbidden();

    expect(Residencial::query()->count())->toBe(0);

    $this->actingAs($this->admin)->get(route('plataforma.residenciales.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('plataforma.residenciales.create'))->assertOk();
});

test('a residencial is registered with its technical administrator', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), [
            ...$this->datos,
            'logo' => UploadedFile::fake()->create('logo.png', 100, 'image/png'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('plataforma.residenciales.index'));

    $residencial = Residencial::query()->sole();

    expect($residencial->nombre)->toBe('Residencial Las Palmas')
        ->and($residencial->estado)->toBe('activo')
        ->and($residencial->logo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($residencial->logo_path);

    expect(DB::table('configuracion_residencial')->where('residencial_id', $residencial->id)->exists())->toBeTrue();

    $administrador = User::query()->where('email', 'ana@laspalmas.test')->sole();

    expect($administrador->residencial_id)->toBe($residencial->id)
        ->and($administrador->rol?->codigo)->toBe(Rol::ADMIN_TECNICO)
        ->and($administrador->debe_cambiar_password)->toBeTrue();
});

test('the temporary password is shown only once and is valid', function () {
    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), $this->datos)
        ->assertSessionHasNoErrors();

    $passwordTemporal = null;

    $this->get(route('plataforma.residenciales.index'))
        ->assertInertia(function (AssertableInertia $page) use (&$passwordTemporal) {
            $page->component('plataforma/residenciales/index')
                ->where('credenciales.correo', 'ana@laspalmas.test')
                ->where('credenciales.password_temporal', function (string $valor) use (&$passwordTemporal) {
                    $passwordTemporal = $valor;

                    return strlen($valor) === 16;
                });
        });

    $administrador = User::query()->where('email', 'ana@laspalmas.test')->sole();

    expect(Hash::check($passwordTemporal, $administrador->password))->toBeTrue();

    $this->get(route('plataforma.residenciales.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('credenciales', null));
});

test('the temporary password never reaches the session in plain text', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), $this->datos);

    $credenciales = $response->getSession()->get('credenciales');

    expect($credenciales)->toBeString()
        ->and($credenciales)->not->toContain('ana@laspalmas.test');
});

test('the name is required and cannot be repeated', function (string $nombre) {
    Residencial::query()->create(['nombre' => 'Residencial Las Palmas']);

    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), [...$this->datos, 'nombre' => $nombre])
        ->assertSessionHasErrors('nombre');

    expect(Residencial::query()->count())->toBe(1);
})->with([
    'vacio' => '',
    'repetido' => 'Residencial Las Palmas',
    'repetido con otras mayusculas' => 'RESIDENCIAL las palmas',
]);

test('the administrator email cannot belong to another account', function () {
    User::factory()->create(['email' => 'ana@laspalmas.test']);

    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), [...$this->datos, 'admin_correo' => 'ANA@laspalmas.test'])
        ->assertSessionHasErrors('admin_correo');

    expect(Residencial::query()->count())->toBe(0);
});

test('the logo must be an allowed image', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), [
            ...$this->datos,
            'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('logo');

    expect(Residencial::query()->count())->toBe(0);
});

test('nothing is kept when the registration fails', function () {
    Storage::fake('public');

    Rol::query()->where('codigo', Rol::ADMIN_TECNICO)->delete();

    $this->withoutExceptionHandling();

    try {
        $this->actingAs($this->admin)->post(route('plataforma.residenciales.store'), [
            ...$this->datos,
            'logo' => UploadedFile::fake()->create('logo.png', 100, 'image/png'),
        ]);
    } catch (Throwable) {
    }

    expect(Residencial::query()->count())->toBe(0)
        ->and(DB::table('configuracion_residencial')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a new residencial does not inherit data from other residenciales', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    DB::table('viviendas')->insert(['residencial_id' => $otro->id, 'codigo' => 'A-1', 'tipo' => 'casa']);
    User::factory()->create(['residencial_id' => $otro->id]);

    $this->actingAs($this->admin)
        ->post(route('plataforma.residenciales.store'), $this->datos)
        ->assertSessionHasNoErrors();

    $nuevo = Residencial::query()->where('nombre', 'Residencial Las Palmas')->sole();

    expect(DB::table('viviendas')->where('residencial_id', $nuevo->id)->count())->toBe(0)
        ->and(User::query()->where('residencial_id', $nuevo->id)->pluck('email')->all())->toBe(['ana@laspalmas.test']);
});

test('the platform admin command assigns the role', function () {
    $usuario = User::factory()->create(['email' => 'nuevo@plataforma.test']);

    $this->artisan('plataforma:admin', ['email' => 'nuevo@plataforma.test'])->assertSuccessful();

    expect($usuario->refresh()->esAdminPlataforma())->toBeTrue();

    $this->artisan('plataforma:admin', ['email' => 'nadie@plataforma.test'])->assertFailed();
});
