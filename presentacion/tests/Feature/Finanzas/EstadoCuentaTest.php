<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

// HU-27 · Consultar el estado de cuenta de una vivienda

beforeEach(function () {
    Notification::fake();

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
        ...$datos,
    ]);

    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE, null, ['name' => 'Paula Presidenta']);

    $this->vivienda = fn (Residencial $residencial, string $codigo, bool $activa = true): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)($this->residencial, 'A-01');

    $this->concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->cargo = fn (string $periodo, string $monto = '500.00', ?Vivienda $vivienda = null, string $origen = 'sistema'): int => DB::table('cargos')->insertGetId([
        'residencial_id' => ($vivienda ?? $this->casa)->residencial_id,
        'vivienda_id' => ($vivienda ?? $this->casa)->id,
        'concepto_id' => $this->concepto,
        'periodo' => $periodo,
        'monto' => $monto,
        'origen' => $origen,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->recibo = 0;

    // Pago con sus aplicaciones: [cargo_id => monto]
    $this->pago = function (string $fecha, string $monto, array $aplicaciones = [], array $datos = []): int {
        $id = DB::table('pagos')->insertGetId([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $this->casa->id,
            'forma_pago' => Pago::EFECTIVO,
            'estado' => Pago::CONFIRMADO,
            'origen' => 'sistema',
            'monto' => $monto,
            'fecha_pago' => $fecha,
            'numero_recibo' => str_pad((string) ++$this->recibo, 6, '0', STR_PAD_LEFT),
            'registrado_por' => $this->tesorero->id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$datos,
        ]);

        foreach ($aplicaciones as $cargoId => $aplicado) {
            DB::table('pago_aplicaciones')->insert([
                'residencial_id' => $this->residencial->id,
                'pago_id' => $id,
                'cargo_id' => $cargoId,
                'monto' => $aplicado,
                'created_at' => now(),
            ]);
        }

        return $id;
    };

    $this->ajuste = fn (string $fecha, string $monto, string $tipo = 'descuento', array $datos = []): int => DB::table('ajustes')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'tipo' => $tipo,
        'monto' => $monto,
        'fecha' => $fecha,
        'motivo' => 'Acuerdo de la junta directiva',
        'autorizado_por' => $this->presidente->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$datos,
    ]);

    $this->ver = fn (array $consulta = [], ?User $usuario = null, ?Vivienda $vivienda = null): TestResponse => $this->actingAs($usuario ?? $this->tesorero)
        ->get(route('finanzas.estado-cuenta.show', [$vivienda ?? $this->casa, ...$consulta]));

    $this->estado = fn (array $consulta = []): array => ($this->ver)($consulta)->assertOk()->viewData('page')['props']['estado'];

    $this->saldoOficial = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', '');

    $this->permitirAbonos = fn () => DB::table('configuracion_residencial')
        ->updateOrInsert(['residencial_id' => $this->residencial->id], ['permite_abono_parcial' => true, 'updated_at' => now()]);
});

test('the treasurer searches active and inactive housing units by code or responsible person', function () {
    $responsable = Residente::query()->create([
        'residencial_id' => $this->residencial->id,
        'nombres' => 'Rosa',
        'apellidos' => 'Vecina',
        'activo' => true,
    ]);
    ViviendaResidente::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'residente_id' => $responsable->id,
        'tipo' => 'propietario',
        'es_responsable_pago' => true,
        'fecha_inicio' => '2026-01-01',
    ]);
    ($this->vivienda)($this->residencial, 'A-02', activa: false);
    ($this->vivienda)($this->otro, 'A-03');

    $this->actingAs($this->tesorero)->get(route('finanzas.estado-cuenta.index', ['buscar' => 'a-0']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/estado-cuenta')
            ->where('estado', null)
            ->has('viviendas', 2)
            ->where('viviendas.0.codigo', 'A-01')
            ->where('viviendas.0.responsable', 'Rosa Vecina')
            ->where('viviendas.0.activa', true)
            ->where('viviendas.1.codigo', 'A-02')
            ->where('viviendas.1.activa', false));

    $this->actingAs($this->tesorero)->get(route('finanzas.estado-cuenta.index', ['buscar' => 'rosa']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('viviendas', 1)->where('viviendas.0.codigo', 'A-01'));

    // Sin texto no lista nada
    $this->actingAs($this->tesorero)->get(route('finanzas.estado-cuenta.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas', []));
});

test('movements are listed in date order with a running balance that matches the official balance', function () {
    $sept = ($this->cargo)('2026-09-01');
    $oct = ($this->cargo)('2026-10-01');
    ($this->cargo)('2026-11-01');
    ($this->pago)('2026-09-01', '500.00', [$sept => '500.00']);
    ($this->pago)('2026-10-15', '500.00', [$oct => '500.00']);
    ($this->ajuste)('2026-10-20', '-100.00');

    $estado = ($this->estado)();

    expect(array_map(fn (array $movimiento): array => [$movimiento['fecha'], $movimiento['tipo'], $movimiento['saldo']], $estado['movimientos']))->toBe([
        // El mismo día, el cargo va antes que el pago
        ['2026-09-01', 'cargo', '500.00'],
        ['2026-09-01', 'pago', '0.00'],
        ['2026-10-01', 'cargo', '500.00'],
        ['2026-10-15', 'pago', '0.00'],
        ['2026-10-20', 'ajuste', '-100.00'],
        ['2026-11-01', 'cargo', '400.00'],
    ])
        ->and($estado['totales'])->toBe([
            'cargos' => '1500.00',
            'pagos_confirmados' => '1000.00',
            'ajustes' => '-100.00',
            'saldo' => '400.00',
            'por_verificar' => '0.00',
        ])
        ->and($estado['totales']['saldo'])->toBe(($this->saldoOficial)())
        ->and($estado['cuadra'])->toBeTrue()
        ->and($estado['vivienda'])->toMatchArray(['codigo' => 'A-01', 'activa' => true])
        ->and($estado['mora'])->toHaveKeys(['estado', 'meses_vencidos', 'vencido']);
});

test('each movement shows its concept, payment method, receipt or reference and the months it covered', function () {
    $sept = ($this->cargo)('2026-09-01');
    $oct = ($this->cargo)('2026-10-01');
    ($this->pago)('2026-10-02', '1000.00', [$sept => '500.00', $oct => '500.00'], [
        'forma_pago' => Pago::TRANSFERENCIA,
        'banco' => 'BAC',
        'numero_referencia' => 'REF-77',
        'verificado_por' => $this->presidente->id,
        'verificado_en' => now(),
    ]);

    $movimientos = ($this->estado)()['movimientos'];
    $cargo = $movimientos[0];
    $pago = $movimientos[2];

    expect($cargo)->toMatchArray([
        'tipo' => 'cargo',
        'concepto' => 'Cuota mensual',
        'periodo' => '2026-09-01',
        'debe' => '500.00',
        'haber' => null,
        'estado_cargo' => 'pagado',
        'pendiente' => '0.00',
        'migrado' => false,
    ])
        ->and($pago)->toMatchArray([
            'tipo' => 'pago',
            'forma_pago' => 'transferencia',
            'numero_recibo' => '000001',
            'referencia' => 'BAC · ref. REF-77',
            'responsable' => 'Paula Presidenta',
            'debe' => null,
            'haber' => '1000.00',
            'saldo' => '0.00',
        ])
        ->and($pago['meses'])->toBe([
            ['periodo' => '2026-09-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00'],
            ['periodo' => '2026-10-01', 'concepto' => 'Cuota mensual', 'monto' => '500.00'],
        ]);
});

test('a payment that leaves a month partly covered is an abono, and the one that completes it is a payment', function () {
    $sept = ($this->cargo)('2026-09-01');
    $oct = ($this->cargo)('2026-10-01');
    // 700: cubre septiembre y deja octubre en parte
    $abono = ($this->pago)('2026-09-05', '700.00', [$sept => '500.00', $oct => '200.00']);
    // 300: completa octubre
    $pago = ($this->pago)('2026-10-05', '300.00', [$oct => '300.00']);
    // Una parte de noviembre todavía pendiente
    $nov = ($this->cargo)('2026-11-01');
    $abonoNov = ($this->pago)('2026-11-03', '100.00', [$nov => '100.00']);

    $movimientos = collect(($this->estado)()['movimientos']);
    $tipo = fn (int $id): string => $movimientos->firstWhere(fn (array $m) => $m['clase'] === 'pago' && $m['id'] === $id)['tipo'];
    $cargo = fn (int $id): array => $movimientos->firstWhere(fn (array $m) => $m['clase'] === 'cargo' && $m['id'] === $id);

    expect($tipo($abono))->toBe('abono')
        ->and($tipo($pago))->toBe('pago')
        ->and($tipo($abonoNov))->toBe('abono')
        ->and($cargo($oct)['estado_cargo'])->toBe('pagado')
        ->and($cargo($nov))->toMatchArray(['estado_cargo' => 'parcial', 'pendiente' => '400.00']);
});

test('an annulled payment is not counted when deciding whether a later one completed the month', function () {
    $sept = ($this->cargo)('2026-09-01');
    // El primer abono se anuló: el segundo, de 500, es el que cubre el mes
    ($this->pago)('2026-09-02', '200.00', [$sept => '200.00'], ['estado' => Pago::ANULADO, 'motivo_anulacion' => 'Recibo duplicado por error']);
    $pago = ($this->pago)('2026-09-03', '500.00', [$sept => '500.00']);

    $movimientos = ($this->estado)()['movimientos'];

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[1])->toMatchArray(['id' => $pago, 'tipo' => 'pago', 'saldo' => '0.00']);
});

test('the abono label matches a real partial cash collection', function () {
    ($this->permitirAbonos)();
    $sept = ($this->cargo)('2026-09-01');
    $cobrador = ($this->usuario)('cobrador');

    $this->actingAs($cobrador)->post(route('finanzas.cobros.store'), [
        'vivienda_id' => $this->casa->id,
        'monto' => '200',
        'cargos' => [$sept],
    ])->assertSessionHasNoErrors();

    // CobroService lo registró como abono parcial y el estado de cuenta dice lo mismo
    $auditoria = Auditoria::query()->where('accion', 'pago_registrado')->sole();
    $movimientos = ($this->estado)()['movimientos'];

    expect($auditoria->valor_nuevo['abono_parcial'])->toBeTrue()
        ->and($movimientos[1])->toMatchArray(['tipo' => 'abono', 'haber' => '200.00', 'saldo' => '300.00'])
        ->and($movimientos[0]['estado_cargo'])->toBe('parcial');
});

test('adjustments add or subtract with their type, reason and authorizer', function () {
    ($this->cargo)('2026-09-01');
    ($this->ajuste)('2026-09-10', '-150.00', 'condonacion');
    ($this->ajuste)('2026-09-11', '25.50', 'correccion');

    $movimientos = ($this->estado)()['movimientos'];

    expect($movimientos[1])->toMatchArray([
        'tipo' => 'ajuste',
        'concepto' => 'Condonación',
        'motivo' => 'Acuerdo de la junta directiva',
        'autorizado_por' => 'Paula Presidenta',
        'debe' => null,
        'haber' => '150.00',
        'saldo' => '350.00',
    ])
        ->and($movimientos[2])->toMatchArray(['concepto' => 'Corrección', 'debe' => '25.50', 'haber' => null, 'saldo' => '375.50'])
        ->and(($this->estado)()['totales']['saldo'])->toBe(($this->saldoOficial)());
});

test('pending transfers are shown apart and do not reduce the balance', function () {
    $sept = ($this->cargo)('2026-09-01');
    $pendiente = ($this->pago)('2026-09-05', '500.00', [], [
        'forma_pago' => Pago::TRANSFERENCIA,
        'estado' => Pago::PENDIENTE_VERIFICACION,
        'numero_recibo' => null,
        'banco' => 'BAC',
        'numero_referencia' => 'REF-1',
        'created_at' => now()->subDays(3),
    ]);
    ($this->pago)('2026-09-06', '500.00', [], [
        'forma_pago' => Pago::TRANSFERENCIA,
        'estado' => Pago::REPORTADO_POR_VECINO,
        'origen' => 'portal',
        'numero_recibo' => null,
        'banco' => 'Atlántida',
        'numero_referencia' => 'REF-2',
    ]);

    $estado = ($this->estado)();

    expect($estado['movimientos'])->toHaveCount(1)
        ->and($estado['totales'])->toMatchArray(['saldo' => '500.00', 'por_verificar' => '1000.00'])
        ->and($estado['por_verificar'])->toHaveCount(2)
        ->and($estado['por_verificar'][0])->toMatchArray([
            'id' => $pendiente,
            'monto' => '500.00',
            'referencia' => 'BAC · ref. REF-1',
            'dias_sin_verificar' => 3,
        ])
        ->and($estado['por_verificar'][1]['origen'])->toBe('portal')
        ->and($sept)->toBeInt();
});

test('a transfer confirmed through HU-26 moves from pending to the movements', function () {
    $sept = ($this->cargo)('2026-09-01');
    $pago = ($this->pago)('2026-09-05', '500.00', [], [
        'forma_pago' => Pago::TRANSFERENCIA,
        'estado' => Pago::PENDIENTE_VERIFICACION,
        'numero_recibo' => null,
        'banco' => 'BAC',
        'numero_referencia' => 'REF-9',
        'cuenta_receptora' => '730-1',
    ]);

    $this->actingAs($this->presidente)->post(route('finanzas.transferencias.verificar.confirmar', $pago), ['cargos' => [$sept]])
        ->assertSessionHasNoErrors();

    $estado = ($this->estado)();

    expect($estado['por_verificar'])->toBe([])
        ->and($estado['movimientos'][1])->toMatchArray(['id' => $pago, 'tipo' => 'pago', 'responsable' => 'Paula Presidenta', 'saldo' => '0.00'])
        ->and($estado['totales']['saldo'])->toBe('0.00');
});

test('annulled and rejected payments are listed apart with their reason and do not affect the balance', function () {
    $sept = ($this->cargo)('2026-09-01');
    ($this->pago)('2026-09-02', '500.00', [$sept => '500.00'], [
        'estado' => Pago::ANULADO,
        'motivo_anulacion' => 'Recibo duplicado por error',
        'anulado_por' => $this->presidente->id,
    ]);
    ($this->pago)('2026-09-03', '500.00', [], [
        'forma_pago' => Pago::TRANSFERENCIA,
        'estado' => Pago::RECHAZADO,
        'numero_recibo' => null,
        'banco' => 'BAC',
        'numero_referencia' => 'REF-5',
        'motivo_rechazo' => 'No aparece en el banco.',
        'verificado_por' => $this->tesorero->id,
    ]);

    $estado = ($this->estado)();

    expect($estado['movimientos'])->toHaveCount(1)
        ->and($estado['totales']['saldo'])->toBe('500.00')
        ->and($estado['sin_efecto'])->toHaveCount(2)
        ->and($estado['sin_efecto'][0])->toMatchArray(['estado' => 'anulado', 'motivo' => 'Recibo duplicado por error', 'responsable' => 'Paula Presidenta', 'numero_recibo' => '000001'])
        ->and($estado['sin_efecto'][1])->toMatchArray(['estado' => 'rechazado', 'motivo' => 'No aparece en el banco.', 'responsable' => 'Tomás Tesorero', 'referencia' => 'BAC · ref. REF-5']);
});

test('migrated movements are marked and may have no receipt, months or authorizer', function () {
    $lote = DB::table('lotes_migracion')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'tipo' => 'definitiva',
        'estado' => 'importado',
        'archivo_nombre' => 'historico-2023.xlsx',
        'archivo_sha256' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->cargo)('2023-01-01', '400.00', origen: 'migracion');
    ($this->pago)('2023-01-15', '400.00', [], ['origen' => 'migracion', 'numero_recibo' => 'R-2023-15', 'registrado_por' => null, 'lote_migracion_id' => $lote]);
    ($this->ajuste)('2023-02-01', '-50.00', 'correccion', ['autorizado_por' => null, 'lote_migracion_id' => $lote]);

    $movimientos = ($this->estado)()['movimientos'];

    expect(array_column($movimientos, 'migrado'))->toBe([true, true, true])
        ->and($movimientos[1])->toMatchArray(['tipo' => 'pago', 'numero_recibo' => 'R-2023-15', 'meses' => [], 'saldo' => '0.00'])
        ->and($movimientos[2])->toMatchArray(['autorizado_por' => null, 'saldo' => '-50.00'])
        ->and(($this->estado)()['cuadra'])->toBeTrue();
});

test('a negative balance is money in favor of the housing unit', function () {
    ($this->cargo)('2026-09-01');
    ($this->ajuste)('2026-09-02', '-600.00', 'correccion');

    $estado = ($this->estado)();

    expect($estado['totales']['saldo'])->toBe('-100.00')
        ->and(end($estado['movimientos'])['saldo'])->toBe('-100.00');
});

test('the year filter shows the opening balance and only that year', function () {
    $dic = ($this->cargo)('2025-12-01');
    ($this->cargo)('2026-01-01');
    ($this->pago)('2026-01-10', '300.00', [$dic => '300.00']);
    ($this->pago)('2025-12-20', '100.00', [], ['estado' => Pago::ANULADO, 'motivo_anulacion' => 'Error de digitación']);

    $completo = ($this->estado)();
    $anio = ($this->estado)(['anio' => 2026]);

    expect($completo['anios'])->toBe([2026, 2025])
        ->and($completo['anio'])->toBeNull()
        ->and($completo['saldo_inicial'])->toBeNull()
        ->and($completo['sin_efecto'])->toHaveCount(1)
        ->and($anio['anio'])->toBe(2026)
        ->and($anio['saldo_inicial'])->toBe('500.00')
        ->and(array_column($anio['movimientos'], 'saldo'))->toBe(['1000.00', '700.00'])
        ->and($anio['sin_efecto'])->toBe([])
        // El saldo actual no cambia con el filtro
        ->and($anio['totales']['saldo'])->toBe('700.00')
        ->and(($this->estado)(['anio' => 2024])['saldo_inicial'])->toBe('0.00');
});

test('a housing unit without movements shows an empty statement', function () {
    $estado = ($this->estado)();

    expect($estado['movimientos'])->toBe([])
        ->and($estado['anios'])->toBe([])
        ->and($estado['totales']['saldo'])->toBe('0.00')
        ->and($estado['cuadra'])->toBeTrue();
});

test('an inactive housing unit keeps its history and is marked as inactive', function () {
    $baja = ($this->vivienda)($this->residencial, 'B-09', activa: false);
    ($this->cargo)('2025-05-01', '450.00', $baja);

    ($this->ver)([], null, $baja)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('estado.vivienda.codigo', 'B-09')
        ->where('estado.vivienda.activa', false)
        ->where('estado.vivienda.responsable', null)
        ->has('estado.movimientos', 1)
        ->where('estado.totales.saldo', '450.00'));
});

test('only the treasurer can see the statement by default', function () {
    foreach ([Rol::PRESIDENTE, Rol::SECRETARIO, 'fiscal', 'vocal', 'cobrador', 'guardia', Rol::RESIDENTE] as $rol) {
        $usuario = ($this->usuario)($rol);

        $this->actingAs($usuario)->get(route('finanzas.estado-cuenta.index'))->assertForbidden();
        ($this->ver)([], $usuario)->assertForbidden();
    }

    $this->actingAs($this->tesorero)->get(route('finanzas.estado-cuenta.index'))->assertOk();
    ($this->ver)()->assertOk();

    // Se reutiliza el permiso de ver cobros; el catálogo no cambia
    expect(PermisoService::MODULOS)->not->toHaveKey('estado_cuenta');
});

test('a statement of another residential complex is forbidden', function () {
    $ajena = ($this->vivienda)($this->otro, 'Z-01');

    ($this->ver)([], null, $ajena)->assertForbidden();
});
