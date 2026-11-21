<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\Vivienda;

// Cambio a la E09 (HU-35): vehículos sin placa con un comprobante que valida su circulación

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Storage::fake('local');

    // 9:00 del 20 de noviembre de 2026 en Honduras
    $this->travelTo('2026-11-20 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->secretario = ($this->usuario)('secretario');

    $this->vivienda = fn (string $codigo, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)('A-01');

    $this->archivo = fn (string $nombre = 'permiso.pdf') => UploadedFile::fake()->create($nombre, 200, 'application/pdf');

    $this->datos = fn (array $cambios = []): array => [
        'vivienda_id' => $this->casa->id,
        'tipo' => 'carro',
        'sin_placa' => '1',
        'comprobante_tipo' => 'permiso_provisional',
        'comprobante_numero' => 'pp-2026 0457',
        'comprobante_vence' => '2026-12-31',
        'comprobante_archivo' => ($this->archivo)(),
        'marca' => 'Kia',
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.store'), ($this->datos)($cambios));

    // Vehículo sin placa creado directo en la tabla
    $this->sinPlaca = fn (array $datos = [], ?Vivienda $vivienda = null): Vehiculo => Vehiculo::query()->create([
        'residencial_id' => ($vivienda ?? $this->casa)->residencial_id,
        'vivienda_id' => ($vivienda ?? $this->casa)->id,
        'tipo' => 'carro',
        'placa' => null,
        'comprobante_tipo' => 'permiso_provisional',
        'comprobante_numero' => 'PP2026001',
        'comprobante_vence' => '2026-12-31',
        'comprobante_path' => 'comprobantes-vehiculos/permiso.pdf',
        'activo' => true,
        ...$datos,
    ]);

    $this->editar = fn (Vehiculo $vehiculo, array $datos, ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.update', $vehiculo), ['_method' => 'PUT', 'tipo' => 'carro', ...$datos]);

    $this->listado = function (array $consulta = [], ?User $usuario = null): array {
        $datos = [];

        $this->actingAs($usuario ?? $this->secretario)
            ->get(route('vehiculos.index', $consulta))
            ->assertInertia(function (AssertableInertia $page) use (&$datos) {
                $datos = $page->toArray()['props']['vehiculos']['data'];
            });

        return $datos;
    };
});

test('a vehicle without a plate is registered with its circulation document and file', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    $vehiculo = Vehiculo::query()->sole();

    expect($vehiculo->placa)->toBeNull()
        ->and($vehiculo->comprobante_tipo)->toBe('permiso_provisional')
        ->and($vehiculo->comprobante_numero)->toBe('PP20260457')
        ->and($vehiculo->comprobante_vence?->toDateString())->toBe('2026-12-31')
        ->and($vehiculo->comprobante_path)->toStartWith("comprobantes-vehiculos/{$this->residencial->id}/")
        ->and($vehiculo->comprobante_descripcion)->toBeNull();

    Storage::disk('local')->assertExists((string) $vehiculo->comprobante_path);

    $auditoria = Auditoria::query()->where('accion', 'vehiculo_registrado')->sole();

    expect($auditoria->valor_nuevo)->toMatchArray(['placa' => null, 'comprobante_tipo' => 'permiso_provisional', 'comprobante_numero' => 'PP20260457', 'comprobante_vence' => '2026-12-31']);

    expect(($this->listado)()[0])->toMatchArray([
        'placa' => null,
        'comprobante' => ['tipo' => 'permiso_provisional', 'descripcion' => null, 'numero' => 'PP20260457', 'vence' => '2026-12-31', 'tiene_archivo' => true],
        'comprobante_vencido' => false,
        'autorizado' => true,
    ]);
});

test('the type, number, expiration and file of the document are required', function (string $campo) {
    ($this->registrar)([$campo => ''])->assertSessionHasErrors($campo);

    expect(Vehiculo::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['comprobante_tipo', 'comprobante_numero', 'comprobante_vence', 'comprobante_archivo']);

test('invalid document data is rejected', function (array $cambios, string $campo) {
    ($this->registrar)($cambios)->assertSessionHasErrors($campo);

    expect(Vehiculo::query()->count())->toBe(0);
})->with([
    'unknown type' => [['comprobante_tipo' => 'factura'], 'comprobante_tipo'],
    'other without description' => [['comprobante_tipo' => 'otro', 'comprobante_descripcion' => ''], 'comprobante_descripcion'],
    'already expired' => [['comprobante_vence' => '2026-11-19'], 'comprobante_vence'],
    'number with symbols' => [['comprobante_numero' => '#123'], 'comprobante_numero'],
    'file of another format' => [['comprobante_archivo' => UploadedFile::fake()->create('permiso.docx', 100, 'application/msword')], 'comprobante_archivo'],
    'file too large' => [['comprobante_archivo' => UploadedFile::fake()->create('permiso.pdf', 5121, 'application/pdf')], 'comprobante_archivo'],
]);

test('the type "other" keeps its description and a document valid until today is accepted', function () {
    ($this->registrar)(['comprobante_tipo' => 'otro', 'comprobante_descripcion' => 'Carta de la distribuidora', 'comprobante_vence' => '2026-11-20'])->assertSessionHasNoErrors();

    expect(Vehiculo::query()->sole())->comprobante_tipo->toBe('otro')->comprobante_descripcion->toBe('Carta de la distribuidora');
});

test('without the option, the plate is still required', function () {
    ($this->registrar)(['sin_placa' => '0'])->assertSessionHasErrors('placa');
});

test('a document number cannot repeat among the active vehicles of the residential, however it is written', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    ($this->registrar)(['comprobante_numero' => 'PP-2026-0457', 'vivienda_id' => ($this->vivienda)('A-02')->id])
        ->assertSessionHasErrors(['comprobante_numero' => 'Ya hay un vehículo activo con ese número de comprobante en el residencial.']);

    ($this->registrar)(['vivienda_id' => ($this->vivienda)('B-01', $this->otro)->id], ($this->usuario)('secretario', $this->otro))->assertSessionHasNoErrors();

    expect(Vehiculo::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('the database also rejects two active vehicles with the same document number', function () {
    ($this->sinPlaca)();

    expect(fn () => ($this->sinPlaca)([], ($this->vivienda)('A-02')))->toThrow(UniqueConstraintViolationException::class);
});

test('several vehicles without a plate do not collide with each other', function () {
    ($this->sinPlaca)(['comprobante_numero' => 'PP1']);
    ($this->sinPlaca)(['comprobante_numero' => 'PP2'], ($this->vivienda)('A-02'));

    expect(Vehiculo::query()->whereNull('placa')->count())->toBe(2);
});

test('an expired document marks the vehicle and it stops being authorized, without deactivating it', function () {
    ($this->sinPlaca)(['comprobante_vence' => '2026-11-19']);
    ($this->sinPlaca)(['comprobante_numero' => 'PP2', 'comprobante_vence' => '2026-11-20'], ($this->vivienda)('A-02'));
    Vehiculo::query()->create(['residencial_id' => $this->residencial->id, 'vivienda_id' => $this->casa->id, 'tipo' => 'moto', 'placa' => 'MOT777', 'activo' => true]);

    $listado = collect(($this->listado)())->keyBy(fn (array $fila) => $fila['placa'] ?? $fila['comprobante']['numero']);

    expect($listado['PP2026001'])->toMatchArray(['comprobante_vencido' => true, 'autorizado' => false])
        ->and($listado['PP2'])->toMatchArray(['comprobante_vencido' => false, 'autorizado' => true])
        ->and($listado['MOT777'])->toMatchArray(['comprobante_vencido' => false, 'autorizado' => true])
        ->and(Vehiculo::query()->where('activo', true)->count())->toBe(3);
});

test('the document is renewed with a new file, which replaces the previous one', function () {
    ($this->registrar)()->assertSessionHasNoErrors();
    $vehiculo = Vehiculo::query()->sole();
    $anterior = (string) $vehiculo->comprobante_path;

    ($this->editar)($vehiculo, [
        'sin_placa' => '1',
        'comprobante_tipo' => 'constancia_tramite',
        'comprobante_numero' => 'CT-88',
        'comprobante_vence' => '2027-02-28',
        'comprobante_archivo' => ($this->archivo)('constancia.pdf'),
    ])->assertSessionHasNoErrors();

    $vehiculo->refresh();

    expect($vehiculo->comprobante_tipo)->toBe('constancia_tramite')
        ->and($vehiculo->comprobante_numero)->toBe('CT88')
        ->and($vehiculo->comprobante_vence?->toDateString())->toBe('2027-02-28')
        ->and($vehiculo->comprobante_path)->not->toBe($anterior);

    Storage::disk('local')->assertExists((string) $vehiculo->comprobante_path);
    Storage::disk('local')->assertMissing($anterior);
});

test('editing without a new file keeps the current one, and other data can be fixed even if the document already expired', function () {
    $vehiculo = ($this->sinPlaca)(['comprobante_vence' => '2026-11-10']);

    ($this->editar)($vehiculo, [
        'sin_placa' => '1',
        'comprobante_tipo' => 'permiso_provisional',
        'comprobante_numero' => 'PP2026001',
        'comprobante_vence' => '2026-11-10',
        'color' => 'Rojo',
    ])->assertSessionHasNoErrors();

    expect($vehiculo->fresh())->color->toBe('Rojo')->comprobante_path->toBe('comprobantes-vehiculos/permiso.pdf');

    ($this->editar)($vehiculo, [
        'sin_placa' => '1',
        'comprobante_tipo' => 'permiso_provisional',
        'comprobante_numero' => 'PP2026001',
        'comprobante_vence' => '2026-11-15',
    ])->assertSessionHasErrors(['comprobante_vence' => 'El comprobante ya venció; escribe una fecha de hoy en adelante.']);
});

test('when the plate arrives, the document is kept as history', function () {
    $vehiculo = ($this->sinPlaca)(['comprobante_vence' => '2026-11-10']);

    ($this->editar)($vehiculo, ['placa' => 'hcd-1234'])->assertSessionHasNoErrors();

    $vehiculo->refresh();

    expect($vehiculo->placa)->toBe('HCD1234')
        ->and($vehiculo->comprobante_numero)->toBe('PP2026001')
        ->and($vehiculo->comprobante_path)->toBe('comprobantes-vehiculos/permiso.pdf');

    expect(($this->listado)()[0])->toMatchArray(['placa' => 'HCD1234', 'comprobante_vencido' => false, 'autorizado' => true])
        ->and(($this->listado)()[0]['comprobante']['numero'])->toBe('PP2026001');

    $auditoria = Auditoria::query()->where('accion', 'vehiculo_editado')->sole();

    expect($auditoria->valor_anterior)->toBe(['placa' => null])
        ->and($auditoria->valor_nuevo)->toBe(['placa' => 'HCD1234']);
});

test('a vehicle with a plate cannot go back to having none', function () {
    $vehiculo = Vehiculo::query()->create(['residencial_id' => $this->residencial->id, 'vivienda_id' => $this->casa->id, 'tipo' => 'carro', 'placa' => 'HAA1234', 'activo' => true]);

    ($this->editar)($vehiculo, ['sin_placa' => '1', 'comprobante_tipo' => 'permiso_provisional', 'comprobante_numero' => 'PP9', 'comprobante_vence' => '2026-12-31'])
        ->assertSessionHasErrors('placa');

    expect($vehiculo->fresh()->placa)->toBe('HAA1234');
});

test('a vehicle without a plate can get a sticker', function () {
    $vehiculo = ($this->sinPlaca)();

    $this->actingAs($this->secretario)
        ->post(route('vehiculos.stickers.asignar', $vehiculo), ['numero' => '0457'])
        ->assertSessionHasNoErrors();

    expect(Sticker::query()->sole()->vehiculo_id)->toBe($vehiculo->id);
});

test('the document number finds the vehicle and its housing unit', function () {
    ($this->sinPlaca)(['comprobante_numero' => 'PP2026001']);

    expect(($this->listado)(['buscar' => 'pp-2026']))->toHaveCount(1);

    $this->actingAs($this->secretario)
        ->get(route('padron.viviendas.index', ['buscar' => 'PP 2026 001']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('viviendas.data', 1)->where('viviendas.data.0.codigo', 'A-01'));
});

test('the document file opens for whoever sees the vehicles, including the guard', function () {
    ($this->registrar)();
    $vehiculo = Vehiculo::query()->sole();
    $sinArchivo = ($this->sinPlaca)(['comprobante_numero' => 'PP2', 'comprobante_path' => null], ($this->vivienda)('A-02'));

    $this->actingAs(($this->usuario)('guardia'))->get(route('vehiculos.comprobante', $vehiculo))->assertOk();
    $this->actingAs($this->secretario)->get(route('vehiculos.comprobante', $sinArchivo))->assertNotFound();
    $this->actingAs(($this->usuario)('cobrador'))->get(route('vehiculos.comprobante', $vehiculo))->assertForbidden();
});

test('the document of another residential is denied and audited', function () {
    $vehiculo = ($this->sinPlaca)();
    $ajeno = ($this->usuario)('secretario', $this->otro);

    $this->actingAs($ajeno)->get(route('vehiculos.comprobante', $vehiculo))->assertForbidden();

    expect(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(1);
});

test('if the vehicle is not saved, the uploaded file does not stay behind', function () {
    $vecino = Residente::query()->create(['residencial_id' => $this->residencial->id, 'nombres' => 'Vecino', 'apellidos' => 'Ajeno']);

    ($this->registrar)(['conductores' => [$vecino->id]])->assertSessionHasErrors('conductores');

    expect(Vehiculo::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
