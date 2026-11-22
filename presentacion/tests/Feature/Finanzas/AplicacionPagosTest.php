<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

// Pagos sin meses elegidos: se aplican a los períodos pendientes más antiguos

beforeEach(function () {
    Storage::fake(TransferenciaService::DISCO);
    Notification::fake();

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->usuario = fn (string $rol): User => User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->cobrador = ($this->usuario)('cobrador');
    $this->tesorero = ($this->usuario)('tesorero');
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE);

    $this->vivienda = fn (string $codigo): Vivienda => Vivienda::query()->create([
        'residencial_id' => $this->residencial->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)('A-01');

    $this->concepto = fn (string $nombre): int => DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => $nombre,
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cuota = ($this->concepto)('Cuota mensual');
    $agua = ($this->concepto)('Agua');

    $this->cargo = fn (Vivienda $vivienda, int $concepto, string $periodo, string $monto): int => DB::table('cargos')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $vivienda->id,
        'concepto_id' => $concepto,
        'periodo' => $periodo,
        'monto' => $monto,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Se guardan en desorden para comprobar que el orden sale del período y no del registro
    $this->noviembre = ($this->cargo)($this->casa, $cuota, '2026-11-01', '500.00');
    $this->septiembre = ($this->cargo)($this->casa, $cuota, '2026-09-01', '500.00');
    $this->octubre = ($this->cargo)($this->casa, $cuota, '2026-10-01', '500.00');
    $this->aguaSeptiembre = ($this->cargo)($this->casa, $agua, '2026-09-01', '100.00');

    $this->permitirAbonos = fn (bool $permite) => DB::table('configuracion_residencial')
        ->updateOrInsert(['residencial_id' => $this->residencial->id], ['permite_abono_parcial' => $permite, 'updated_at' => now()]);

    // Casi todos los casos dejan un mes cubierto en parte
    ($this->permitirAbonos)(true);

    $this->cobrar = fn (string $monto, array $cargos = [], ?Vivienda $vivienda = null) => $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => ($vivienda ?? $this->casa)->id, 'monto' => $monto, 'cargos' => $cargos]);

    // Aplicaciones de un pago en el orden en que se guardaron
    $this->aplicaciones = fn (Pago $pago): array => DB::table('pago_aplicaciones')
        ->where('pago_id', $pago->id)
        ->orderBy('id')
        ->get(['cargo_id', 'monto'])
        ->map(fn ($fila): array => [(int) $fila->cargo_id, number_format((float) $fila->monto, 2, '.', '')])
        ->all();

    $this->registrarTransferencia = function (string $monto): Pago {
        $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), [
            'vivienda_id' => $this->casa->id,
            'monto' => $monto,
            'fecha_pago' => TransferenciaService::hoy(),
            'banco' => 'BAC Credomatic',
            'numero_referencia' => 'REF-2001',
            'cuenta_receptora' => '730-123456-7',
            'comprobante' => UploadedFile::fake()->createWithContent('comprobante.pdf', "%PDF-1.4\n%comprobante de prueba\n"),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Pago::query()->where('forma_pago', Pago::TRANSFERENCIA)->latest('id')->firstOrFail();
    };

    $this->confirmar = fn (Pago $pago, array $cargos = []) => $this->actingAs($this->presidente)
        ->post(route('finanzas.transferencias.verificar.confirmar', $pago), ['cargos' => $cargos]);
});

// Cobro en efectivo

test('a cash payment without months covers the oldest periods first, each concept separately', function () {
    ($this->cobrar)('1100')->assertSessionHasNoErrors();

    $pago = Pago::query()->sole();

    // Septiembre con sus dos conceptos y luego octubre; noviembre queda pendiente
    expect(($this->aplicaciones)($pago))->toBe([
        [$this->septiembre, '500.00'],
        [$this->aguaSeptiembre, '100.00'],
        [$this->octubre, '500.00'],
    ]);

    $auditoria = Auditoria::query()->where('accion', 'pago_registrado')->sole();

    expect($auditoria->valor_nuevo['meses'])->toBe([$this->septiembre, $this->aguaSeptiembre, $this->octubre])
        ->and($auditoria->valor_nuevo['abono_parcial'])->toBeTrue();
});

test('a cash payment without months that covers everything pending is not a partial payment', function () {
    ($this->permitirAbonos)(false);

    ($this->cobrar)('1600')->assertSessionHasNoErrors();

    expect(($this->aplicaciones)(Pago::query()->sole()))->toHaveCount(4)
        ->and(Auditoria::query()->where('accion', 'pago_registrado')->sole()->valor_nuevo['abono_parcial'])->toBeFalse();
});

test('without months the last covered month can stay partially paid when partial payments are allowed', function () {
    ($this->cobrar)('700')->assertSessionHasNoErrors();

    expect(($this->aplicaciones)(Pago::query()->sole()))->toBe([
        [$this->septiembre, '500.00'],
        [$this->aguaSeptiembre, '100.00'],
        [$this->octubre, '100.00'],
    ]);

    // El siguiente pago sin meses empieza por lo que falta de octubre
    ($this->cobrar)('600')->assertSessionHasNoErrors();

    expect(($this->aplicaciones)(Pago::query()->latest('id')->firstOrFail()))->toBe([
        [$this->octubre, '400.00'],
        [$this->noviembre, '200.00'],
    ]);
});

test('without months a payment below everything pending is rejected when partial payments are not allowed', function () {
    ($this->permitirAbonos)(false);

    ($this->cobrar)('600')->assertSessionHasErrors([
        'monto' => 'El patronato no permite abonos parciales: sin meses elegidos, el monto debe cubrir todo lo pendiente de la vivienda.',
    ]);

    expect(Pago::query()->count())->toBe(0)
        ->and(DB::table('pago_aplicaciones')->count())->toBe(0);
});

test('without months the amount cannot exceed everything pending yet', function () {
    ($this->cobrar)('1600.01')->assertSessionHasErrors(['monto' => 'El monto supera lo pendiente de la vivienda.']);

    expect(Pago::query()->count())->toBe(0);
});

test('without months only the charges of the paying housing unit are covered', function () {
    $otra = ($this->vivienda)('A-02');
    $ajeno = ($this->cargo)($otra, (int) DB::table('conceptos_cobro')->where('nombre', 'Cuota mensual')->value('id'), '2026-08-01', '500.00');

    ($this->cobrar)('500')->assertSessionHasNoErrors();

    expect(($this->aplicaciones)(Pago::query()->sole()))->toBe([[$this->septiembre, '500.00']])
        ->and(DB::table('pago_aplicaciones')->where('cargo_id', $ajeno)->exists())->toBeFalse();
});

test('a cash payment sent without the months field is applied to the oldest periods', function () {
    // La pantalla no envía el campo cuando no se marca ningún mes
    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(($this->aplicaciones)(Pago::query()->sole()))->toBe([[$this->septiembre, '500.00']]);
});

test('a cash payment without months is rejected for a housing unit with nothing pending', function () {
    $alDia = ($this->vivienda)('A-03');

    ($this->cobrar)('500', [], $alDia)->assertSessionHasErrors(['monto' => 'El monto supera lo pendiente de la vivienda.']);

    expect(Pago::query()->count())->toBe(0)
        ->and(DB::table('pago_aplicaciones')->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'pago_registrado')->exists())->toBeFalse();
});

test('a cash payment with chosen months keeps applying only to those months', function () {
    // Se elige octubre aunque septiembre es más antiguo
    ($this->cobrar)('500', [$this->octubre])->assertSessionHasNoErrors();

    expect(($this->aplicaciones)(Pago::query()->sole()))->toBe([[$this->octubre, '500.00']])
        ->and(Auditoria::query()->where('accion', 'pago_registrado')->sole()->valor_nuevo['meses'])->toBe([$this->octubre]);

    ($this->cobrar)('600', [$this->septiembre])->assertSessionHasErrors(['monto' => 'El monto supera lo pendiente de los meses elegidos.']);
});

test('a payment without months leaves the housing unit balance and pending months consistent', function () {
    ($this->cobrar)('1100')->assertSessionHasNoErrors();

    $saldo = (float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo');

    expect(number_format($saldo, 2, '.', ''))->toBe('500.00');

    $this->actingAs($this->cobrador)
        ->get(route('finanzas.cobros.create', ['vivienda' => $this->casa->id]))
        ->assertInertia(fn ($page) => $page
            ->has('pendientes', 1)
            ->where('pendientes.0.cargo_id', $this->noviembre)
            ->where('pendientes.0.pendiente', '500.00'));
});

// Confirmación de transferencias

test('a transfer confirmed without months covers the oldest periods first', function () {
    $pago = ($this->registrarTransferencia)('1100');

    ($this->confirmar)($pago)->assertSessionHasNoErrors()->assertRedirect();

    $pago->refresh();

    expect($pago->estado)->toBe(Pago::CONFIRMADO)
        ->and($pago->numero_recibo)->not->toBeNull()
        ->and(($this->aplicaciones)($pago))->toBe([
            [$this->septiembre, '500.00'],
            [$this->aguaSeptiembre, '100.00'],
            [$this->octubre, '500.00'],
        ])
        ->and(Auditoria::query()->where('accion', 'transferencia_confirmada')->sole()->valor_nuevo['meses'])
        ->toBe([$this->septiembre, $this->aguaSeptiembre, $this->octubre]);
});

test('a transfer confirmed without the months field is applied to the oldest periods', function () {
    $pago = ($this->registrarTransferencia)('500');

    // La pantalla no envía el campo cuando se desmarcan todos los meses
    $this->actingAs($this->presidente)
        ->post(route('finanzas.transferencias.verificar.confirmar', $pago))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->aplicaciones)($pago))->toBe([[$this->septiembre, '500.00']]);
});

test('a transfer without months is rejected when the housing unit has nothing pending', function () {
    // Un cobro deja la vivienda al día antes de verificar la transferencia
    ($this->cobrar)('1600')->assertSessionHasNoErrors();

    $pago = ($this->registrarTransferencia)('500');

    ($this->confirmar)($pago)->assertSessionHasErrors(['cargos' => 'El monto de la transferencia supera lo pendiente de la vivienda.']);

    $pago->refresh();

    expect($pago->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($pago->numero_recibo)->toBeNull()
        ->and(DB::table('pago_aplicaciones')->where('pago_id', $pago->id)->count())->toBe(0);
});

test('a transfer without months above everything pending stays pending', function () {
    $pago = ($this->registrarTransferencia)('2000');

    ($this->confirmar)($pago)->assertSessionHasErrors(['cargos' => 'El monto de la transferencia supera lo pendiente de la vivienda.']);

    expect($pago->refresh()->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($pago->numero_recibo)->toBeNull()
        ->and(DB::table('pago_aplicaciones')->count())->toBe(0);
});

test('a transfer without months below everything pending is rejected when partial payments are not allowed', function () {
    ($this->permitirAbonos)(false);

    $pago = ($this->registrarTransferencia)('1000');

    ($this->confirmar)($pago)->assertSessionHasErrors([
        'cargos' => 'El patronato no permite abonos parciales: sin meses elegidos, el monto de la transferencia debe cubrir todo lo pendiente de la vivienda.',
    ]);

    $pago->refresh();

    expect($pago->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($pago->numero_recibo)->toBeNull()
        ->and($pago->verificado_por)->toBeNull()
        ->and(DB::table('pago_aplicaciones')->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'transferencia_confirmada')->exists())->toBeFalse();
});

test('a transfer with chosen months keeps applying only to those months', function () {
    $pago = ($this->registrarTransferencia)('500');

    ($this->confirmar)($pago, [$this->noviembre])->assertSessionHasNoErrors();

    expect(($this->aplicaciones)($pago))->toBe([[$this->noviembre, '500.00']]);
});
