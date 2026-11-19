<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    // 9:00 del 17 de noviembre de 2026 en Honduras
    $this->travelTo('2026-11-17 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->secretario = ($this->usuario)('secretario');

    $this->vehiculo = function (string $placa = 'HAA1234', ?Residencial $residencial = null, bool $activo = true): Vehiculo {
        $residencial ??= $this->residencial;
        $vivienda = Vivienda::query()->create([
            'residencial_id' => $residencial->id,
            'codigo' => "V-{$placa}",
            'tipo_vivienda_id' => tipoVivienda($residencial->id),
            'estado_ocupacion' => 'ocupada',
            'activa' => true,
        ]);

        return Vehiculo::query()->create([
            'residencial_id' => $residencial->id,
            'vivienda_id' => $vivienda->id,
            'tipo' => 'carro',
            'placa' => $placa,
            'activo' => $activo,
        ]);
    };

    $this->sticker = fn (Vehiculo $vehiculo, string $numero = '0457', string $estado = Sticker::ACTIVO): Sticker => Sticker::query()->create([
        'residencial_id' => $vehiculo->residencial_id,
        'vehiculo_id' => $vehiculo->id,
        'numero' => $numero,
        'estado' => $estado,
        'fecha_emision' => '2026-01-10',
        'motivo' => $estado === Sticker::ACTIVO ? null : 'Motivo anterior.',
    ]);

    $this->carro = ($this->vehiculo)();

    $this->asignar = fn (Vehiculo $vehiculo, string $numero = ' a-0457 ', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.stickers.asignar', $vehiculo), ['numero' => $numero]);

    $this->reemplazar = fn (Sticker $sticker, string $numero = '0999', string $motivo = 'Se despegó al lavar el carro.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.stickers.reemplazar', $sticker), ['numero' => $numero, 'motivo' => $motivo]);

    $this->cambiar = fn (Sticker $sticker, string $accion, string $motivo = 'Revisión del comité de seguridad.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.stickers.estado', $sticker), ['accion' => $accion, 'motivo' => $motivo]);

    $this->bajaSticker = fn (Sticker $sticker, string $motivo = 'El residente devolvió el sticker.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.stickers.baja', $sticker), ['motivo' => $motivo]);
});

test('the secretary assigns a numbered sticker to a vehicle', function () {
    ($this->asignar)($this->carro)->assertSessionHasNoErrors();

    $sticker = Sticker::query()->sole();

    expect($sticker->vehiculo_id)->toBe($this->carro->id)
        ->and($sticker->numero)->toBe('A-0457')
        ->and($sticker->estado)->toBe(Sticker::ACTIVO)
        ->and($sticker->fecha_emision->toDateString())->toBe('2026-11-17');

    $auditoria = Auditoria::query()->where('accion', 'sticker_asignado')->sole();

    expect($auditoria->modulo)->toBe('vehiculos')
        ->and($auditoria->entidad_id)->toBe($sticker->id)
        ->and($auditoria->valor_nuevo)->toBe(['vehiculo_id' => $this->carro->id, 'placa' => 'HAA1234', 'numero' => 'A-0457']);
});

test('the number is required and admits only letters, numbers and dashes', function (string $numero) {
    ($this->asignar)($this->carro, $numero)->assertSessionHasErrors('numero');

    expect(Sticker::query()->count())->toBe(0);
})->with(['', 'A 457', '#457', 'A-0457-'.str_repeat('9', 30)]);

test('a number cannot repeat among the active stickers of the residential', function () {
    ($this->sticker)(($this->vehiculo)('BBB111'), 'A-0457');

    ($this->asignar)($this->carro, 'a-0457')
        ->assertSessionHasErrors(['numero' => 'Ya hay un sticker activo con ese número en el residencial.']);

    expect(Sticker::query()->count())->toBe(1);
});

test('the number of a sticker that is no longer active can be used again', function (string $estado) {
    ($this->sticker)(($this->vehiculo)('BBB111'), '0457', $estado);

    ($this->asignar)($this->carro, '0457')->assertSessionHasNoErrors();

    expect(Sticker::query()->where('numero', '0457')->count())->toBe(2);
})->with([Sticker::REEMPLAZADO, Sticker::DADO_DE_BAJA, Sticker::VENCIDO, Sticker::SUSPENDIDO]);

test('another residential may use the same number', function () {
    ($this->sticker)(($this->vehiculo)('OTR000', $this->otro), '0457');

    ($this->asignar)($this->carro, '0457')->assertSessionHasNoErrors();
});

test('the database also rejects two active stickers with the same number', function () {
    ($this->sticker)($this->carro, '0457');

    expect(fn () => ($this->sticker)(($this->vehiculo)('BBB111'), '0457'))->toThrow(UniqueConstraintViolationException::class);
});

test('a vehicle has a single sticker at a time and a deactivated vehicle cannot get one', function () {
    ($this->sticker)($this->carro, '0457', Sticker::SUSPENDIDO);

    ($this->asignar)($this->carro, '0500')->assertSessionHasErrors(['numero' => 'El vehículo ya tiene un sticker; para cambiarlo, reemplázalo.']);

    ($this->asignar)(($this->vehiculo)('BAJ001', null, false), '0600')->assertSessionHasErrors(['numero' => 'El vehículo está dado de baja.']);

    expect(Sticker::query()->count())->toBe(1);
});

test('a new sticker can be assigned once the previous one ended', function () {
    ($this->sticker)($this->carro, '0457', Sticker::DADO_DE_BAJA);

    ($this->asignar)($this->carro, '0500')->assertSessionHasNoErrors();

    expect(Sticker::query()->where('estado', Sticker::ACTIVO)->sole()->numero)->toBe('0500');
});

test('a sticker is replaced with a reason and the new one becomes active', function () {
    $anterior = ($this->sticker)($this->carro, '0457');

    ($this->reemplazar)($anterior, '0999')->assertSessionHasNoErrors();

    $anterior->refresh();
    $nuevo = Sticker::query()->where('estado', Sticker::ACTIVO)->sole();

    expect($anterior->estado)->toBe(Sticker::REEMPLAZADO)
        ->and($anterior->motivo)->toBe('Se despegó al lavar el carro.')
        ->and($nuevo->numero)->toBe('0999')
        ->and($nuevo->vehiculo_id)->toBe($this->carro->id)
        ->and($nuevo->fecha_emision->toDateString())->toBe('2026-11-17');

    $auditoria = Auditoria::query()->where('accion', 'sticker_reemplazado')->sole();

    expect($auditoria->entidad_id)->toBe($anterior->id)
        ->and($auditoria->motivo)->toBe('Se despegó al lavar el carro.')
        ->and($auditoria->valor_anterior)->toBe(['numero' => '0457', 'estado' => 'activo'])
        ->and($auditoria->valor_nuevo)->toBe(['numero' => '0999', 'sticker_id' => $nuevo->id]);
});

test('a replacement may keep the same number but not take the number of another active sticker', function () {
    $anterior = ($this->sticker)($this->carro, '0457');
    ($this->sticker)(($this->vehiculo)('BBB111'), '0888');

    ($this->reemplazar)($anterior, '0888')->assertSessionHasErrors(['numero' => 'Ya hay un sticker activo con ese número en el residencial.']);

    expect($anterior->fresh()->estado)->toBe(Sticker::ACTIVO);

    ($this->reemplazar)($anterior, '0457')->assertSessionHasNoErrors();

    expect(Sticker::query()->where('vehiculo_id', $this->carro->id)->pluck('estado', 'numero')->all())->toBe(['0457' => Sticker::ACTIVO]);
});

test('an expired sticker is renewed by replacing it', function () {
    $vencido = ($this->sticker)($this->carro, '0457', Sticker::VENCIDO);

    ($this->reemplazar)($vencido, '1001', 'Renovación del sticker para 2027.')->assertSessionHasNoErrors();

    expect($vencido->fresh()->estado)->toBe(Sticker::REEMPLAZADO)
        ->and(Sticker::query()->where('estado', Sticker::ACTIVO)->sole()->numero)->toBe('1001');
});

test('a replacement needs a reason', function () {
    $sticker = ($this->sticker)($this->carro);

    ($this->reemplazar)($sticker, '0999', 'corto')->assertSessionHasErrors('motivo');

    expect($sticker->fresh()->estado)->toBe(Sticker::ACTIVO);
});

test('the secretary suspends, reactivates and expires a sticker following its states', function () {
    $sticker = ($this->sticker)($this->carro);

    ($this->cambiar)($sticker, 'suspender')->assertSessionHasNoErrors();
    expect($sticker->fresh()->estado)->toBe(Sticker::SUSPENDIDO);

    ($this->cambiar)($sticker, 'reactivar')->assertSessionHasNoErrors();
    expect($sticker->fresh()->estado)->toBe(Sticker::ACTIVO);

    ($this->cambiar)($sticker, 'vencer', 'Terminó la vigencia de 2026.')->assertSessionHasNoErrors();
    expect($sticker->fresh())->estado->toBe(Sticker::VENCIDO)->motivo->toBe('Terminó la vigencia de 2026.');

    expect(Auditoria::query()->where('entidad', 'stickers')->orderBy('id')->pluck('accion')->all())
        ->toBe(['sticker_suspendido', 'sticker_reactivado', 'sticker_vencido']);
});

test('a state only admits its own transitions', function (string $estado, string $accion) {
    $sticker = ($this->sticker)($this->carro, '0457', $estado);

    $respuesta = $accion === 'reemplazar'
        ? ($this->reemplazar)($sticker)
        : ($accion === 'dar_de_baja' ? ($this->bajaSticker)($sticker) : ($this->cambiar)($sticker, $accion));

    $respuesta->assertSessionHasErrors(['accion' => 'El sticker ya no admite ese cambio; actualiza la página.']);

    expect($sticker->fresh()->estado)->toBe($estado);
})->with([
    'reactivate an active one' => [Sticker::ACTIVO, 'reactivar'],
    'expire a suspended one' => [Sticker::SUSPENDIDO, 'vencer'],
    'suspend an expired one' => [Sticker::VENCIDO, 'suspender'],
    'reactivate an expired one' => [Sticker::VENCIDO, 'reactivar'],
    'replace a replaced one' => [Sticker::REEMPLAZADO, 'reemplazar'],
    'deactivate a deactivated one' => [Sticker::DADO_DE_BAJA, 'dar_de_baja'],
    'reactivate a replaced one' => [Sticker::REEMPLAZADO, 'reactivar'],
]);

test('a suspended sticker cannot be reactivated if another active sticker took its number', function () {
    $suspendido = ($this->sticker)($this->carro, '0457', Sticker::SUSPENDIDO);
    ($this->sticker)(($this->vehiculo)('BBB111'), '0457');

    ($this->cambiar)($suspendido, 'reactivar')
        ->assertSessionHasErrors(['accion' => 'Otro sticker activo ya usa ese número; reemplázalo por uno nuevo.']);

    expect($suspendido->fresh()->estado)->toBe(Sticker::SUSPENDIDO);
});

test('an unknown action is rejected', function () {
    ($this->cambiar)(($this->sticker)($this->carro), 'reemplazar')->assertSessionHasErrors('accion');
});

test('a sticker is deactivated with a reason and its number becomes free', function () {
    $sticker = ($this->sticker)($this->carro);

    ($this->bajaSticker)($sticker)->assertSessionHasNoErrors();

    expect($sticker->fresh())->estado->toBe(Sticker::DADO_DE_BAJA)->motivo->toBe('El residente devolvió el sticker.');

    ($this->asignar)(($this->vehiculo)('BBB111'), '0457')->assertSessionHasNoErrors();
});

test('deactivating a vehicle deactivates its current sticker with the same reason', function () {
    $sticker = ($this->sticker)($this->carro, '0457', Sticker::SUSPENDIDO);
    $anterior = ($this->sticker)($this->carro, '0100', Sticker::REEMPLAZADO);

    $this->actingAs($this->secretario)
        ->post(route('vehiculos.baja', $this->carro), ['motivo' => 'Se vendió el vehículo.'])
        ->assertSessionHasNoErrors();

    expect($sticker->fresh())->estado->toBe(Sticker::DADO_DE_BAJA)->motivo->toBe('Se vendió el vehículo.')
        ->and($anterior->fresh()->estado)->toBe(Sticker::REEMPLAZADO);

    $auditoria = Auditoria::query()->where('accion', 'sticker_dado_de_baja')->sole();

    expect($auditoria->user_id)->toBe($this->secretario->id)
        ->and($auditoria->valor_anterior)->toBe(['estado' => 'suspendido']);
});

test('the vehicle list shows each sticker with the actions its state allows', function () {
    ($this->sticker)($this->carro, '0100', Sticker::REEMPLAZADO);
    ($this->sticker)($this->carro, '0457', Sticker::SUSPENDIDO);

    $this->actingAs($this->secretario)
        ->get(route('vehiculos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('vehiculos.data.0.stickers', 2)
            ->where('vehiculos.data.0.stickers.0.numero', '0457')
            ->where('vehiculos.data.0.stickers.0.acciones', ['reactivar', 'reemplazar', 'dar_de_baja'])
            ->where('vehiculos.data.0.stickers.1.acciones', []));
});

test('the president and the guard cannot assign or change stickers', function (string $rol) {
    $usuario = ($this->usuario)($rol);
    $sticker = ($this->sticker)(($this->vehiculo)('BBB111'));

    ($this->asignar)($this->carro, '0500', $usuario)->assertForbidden();
    ($this->reemplazar)($sticker, '0999', 'Se despegó al lavar el carro.', $usuario)->assertForbidden();
    ($this->cambiar)($sticker, 'suspender', 'Revisión del comité de seguridad.', $usuario)->assertForbidden();
    ($this->bajaSticker)($sticker, 'El residente devolvió el sticker.', $usuario)->assertForbidden();

    expect(Sticker::query()->count())->toBe(1)
        ->and($sticker->fresh()->estado)->toBe(Sticker::ACTIVO);
})->with(['presidente', 'guardia']);

test('the stickers of another residential are denied and audited', function () {
    $ajeno = ($this->usuario)('secretario', $this->otro);
    $sticker = ($this->sticker)($this->carro);

    ($this->asignar)(($this->vehiculo)('BBB111'), '0500', $ajeno)->assertForbidden();
    ($this->cambiar)($sticker, 'suspender', 'Revisión del comité de seguridad.', $ajeno)->assertForbidden();
    ($this->bajaSticker)($sticker, 'El residente devolvió el sticker.', $ajeno)->assertForbidden();

    expect($sticker->fresh()->estado)->toBe(Sticker::ACTIVO)
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(3);
});
