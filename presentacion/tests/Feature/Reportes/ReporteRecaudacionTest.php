<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Eventos\PagoAnulado;
use Patronato\Negocio\Eventos\PagoConfirmado;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    // 15:00 en UTC son las 09:00 del 20 de noviembre en Honduras
    Carbon::setTestNow('2026-11-20 15:00:00');

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
        ...$datos,
    ]);

    $this->tesorero = ($this->usuario)('tesorero');
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE);
    $this->marta = ($this->usuario)('cobrador', null, ['name' => 'Marta']);
    $this->luis = ($this->usuario)('cobrador', null, ['name' => 'Luis']);

    $this->vivienda = fn (Residencial $residencial, string $codigo): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)($this->residencial, 'A-01');

    $this->cajas = [];

    $this->caja = function (User $cobrador): Caja {
        return $this->cajas[$cobrador->id] ??= Caja::query()->create([
            'residencial_id' => $cobrador->residencial_id,
            'cobrador_id' => $cobrador->id,
            'estado' => Caja::ABIERTA,
            'abierta_en' => now(),
        ]);
    };

    $recibo = 0;

    // Crea el pago con los datos que su forma y estado tendrían en el sistema
    $this->pago = function (string $forma, string $estado, string $fecha, string $monto, array $datos = []) use (&$recibo): Pago {
        $vivienda = $datos['vivienda'] ?? $this->casa;
        $cobrador = $datos['cobrador'] ?? $this->marta;
        $origen = $datos['origen'] ?? Pago::SISTEMA;
        $efectivo = $forma === Pago::EFECTIVO;
        $conRecibo = in_array($estado, [Pago::CONFIRMADO, Pago::ANULADO], true);
        $recibo++;

        return Pago::query()->create([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'forma_pago' => $forma,
            'estado' => $estado,
            'origen' => $origen,
            'monto' => $monto,
            'fecha_pago' => $fecha,
            'numero_recibo' => $conRecibo ? (string) $recibo : null,
            'caja_id' => $efectivo && $origen === Pago::SISTEMA ? ($this->caja)($cobrador)->id : null,
            'registrado_por' => array_key_exists('registrado_por', $datos)
                ? $datos['registrado_por']
                : ($origen === 'migracion' ? null : ($efectivo ? $cobrador->id : $this->tesorero->id)),
            'banco' => $efectivo || $origen === 'migracion' ? null : 'Banco Atlántida',
            'numero_referencia' => $efectivo || $origen === 'migracion' ? null : "REF-{$recibo}",
            'verificado_por' => ! $efectivo && in_array($estado, [Pago::CONFIRMADO, Pago::RECHAZADO], true) ? $this->presidente->id : null,
            'verificado_en' => ! $efectivo && in_array($estado, [Pago::CONFIRMADO, Pago::RECHAZADO], true) ? now() : null,
            'motivo_rechazo' => $estado === Pago::RECHAZADO ? 'El depósito no aparece en el banco' : null,
            'anulado_por' => $estado === Pago::ANULADO ? $this->tesorero->id : null,
            'anulacion_aprobada_por' => $estado === Pago::ANULADO ? $this->presidente->id : null,
            'anulado_en' => $estado === Pago::ANULADO ? now() : null,
            'motivo_anulacion' => $estado === Pago::ANULADO ? 'Cobro registrado en la vivienda equivocada' : null,
        ]);
    };

    // Noviembre hasta hoy
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-01', '120.00', ['origen' => 'migracion']);
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-02', '500.00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-02', '250.50');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::CONFIRMADO, '2026-11-03', '600.00');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::PENDIENTE_VERIFICACION, '2026-11-04', '300.00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-05', '400.00', ['cobrador' => $this->luis]);
    ($this->pago)(Pago::EFECTIVO, Pago::ANULADO, '2026-11-05', '999.00');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::REPORTADO_POR_VECINO, '2026-11-06', '150.00');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::RECHAZADO, '2026-11-06', '80.00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-20', '75.00', ['cobrador' => $this->luis]);

    // Fuera del mes en curso
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-10-31', '700.00');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::CONFIRMADO, '2026-10-15', '200.00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2025-12-10', '50.00');

    // Sin pagos ni pendientes
    $this->sinEstados = fn (): array => [
        'pendientes' => ['pagos' => 0, 'monto' => '0.00', 'estados' => [
            ['estado' => Pago::PENDIENTE_VERIFICACION, 'pagos' => 0, 'monto' => '0.00'],
            ['estado' => Pago::REPORTADO_POR_VECINO, 'pagos' => 0, 'monto' => '0.00'],
        ]],
        'sin_efecto' => [
            ['estado' => Pago::ANULADO, 'pagos' => 0, 'monto' => '0.00'],
            ['estado' => Pago::RECHAZADO, 'pagos' => 0, 'monto' => '0.00'],
        ],
    ];

    $this->reporte = fn (array $consulta = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->get(route('reportes.recaudacion.index', $consulta));
});

test('without filters the report shows the current month up to today grouped by day', function () {
    ($this->reporte)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reportes/recaudacion')
            ->where('filtros', ['cobrador' => null, 'desde' => '2026-11-01', 'hasta' => '2026-11-20', 'agrupar' => 'dia'])
            ->where('cobradores', [
                ['id' => $this->luis->id, 'nombre' => 'Luis'],
                ['id' => $this->marta->id, 'nombre' => 'Marta'],
            ])
            ->where('reporte.periodos', [
                ['periodo' => '2026-11-01', 'pagos' => 1, 'efectivo' => '120.00', 'transferencia' => '0.00', 'total' => '120.00'],
                ['periodo' => '2026-11-02', 'pagos' => 2, 'efectivo' => '750.50', 'transferencia' => '0.00', 'total' => '750.50'],
                ['periodo' => '2026-11-03', 'pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '600.00', 'total' => '600.00'],
                ['periodo' => '2026-11-05', 'pagos' => 1, 'efectivo' => '400.00', 'transferencia' => '0.00', 'total' => '400.00'],
                ['periodo' => '2026-11-20', 'pagos' => 1, 'efectivo' => '75.00', 'transferencia' => '0.00', 'total' => '75.00'],
            ])
            ->where('reporte.totales', ['pagos' => 6, 'efectivo' => '1345.50', 'transferencia' => '600.00', 'total' => '1945.50']));
});

test('pending transfers are shown apart and do not add to the collection', function () {
    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.pendientes', ['pagos' => 2, 'monto' => '450.00', 'estados' => [
                ['estado' => Pago::PENDIENTE_VERIFICACION, 'pagos' => 1, 'monto' => '300.00'],
                ['estado' => Pago::REPORTADO_POR_VECINO, 'pagos' => 1, 'monto' => '150.00'],
            ]])
            ->where('reporte.totales.total', '1945.50'));
});

test('voided and rejected payments are reported apart without changing the confirmed or pending totals', function () {
    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.sin_efecto', [
                ['estado' => Pago::ANULADO, 'pagos' => 1, 'monto' => '999.00'],
                ['estado' => Pago::RECHAZADO, 'pagos' => 1, 'monto' => '80.00'],
            ])
            ->where('reporte.totales.total', '1945.50')
            ->where('reporte.pendientes.monto', '450.00'));
});

test('each payment state lands in its own block', function (string $forma, string $estado, string $origen, array $totales, array $pendientes, array $sinEfecto) {
    // Un día sin otros pagos
    ($this->pago)($forma, $estado, '2026-11-10', '100.00', ['origen' => $origen]);

    $vacio = ($this->sinEstados)();

    ($this->reporte)(['desde' => '2026-11-10', 'hasta' => '2026-11-10'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales', $totales)
            ->where('reporte.pendientes', $pendientes === [] ? $vacio['pendientes'] : $pendientes)
            ->where('reporte.sin_efecto', $sinEfecto === [] ? $vacio['sin_efecto'] : $sinEfecto));
})->with([
    'confirmed cash' => [Pago::EFECTIVO, Pago::CONFIRMADO, Pago::SISTEMA,
        ['pagos' => 1, 'efectivo' => '100.00', 'transferencia' => '0.00', 'total' => '100.00'], [], []],
    'confirmed transfer' => [Pago::TRANSFERENCIA, Pago::CONFIRMADO, Pago::SISTEMA,
        ['pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '100.00', 'total' => '100.00'], [], []],
    'confirmed transfer reported from the portal' => [Pago::TRANSFERENCIA, Pago::CONFIRMADO, Pago::PORTAL,
        ['pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '100.00', 'total' => '100.00'], [], []],
    'migrated confirmed cash' => [Pago::EFECTIVO, Pago::CONFIRMADO, 'migracion',
        ['pagos' => 1, 'efectivo' => '100.00', 'transferencia' => '0.00', 'total' => '100.00'], [], []],
    'migrated confirmed transfer' => [Pago::TRANSFERENCIA, Pago::CONFIRMADO, 'migracion',
        ['pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '100.00', 'total' => '100.00'], [], []],
    'transfer pending verification' => [Pago::TRANSFERENCIA, Pago::PENDIENTE_VERIFICACION, Pago::SISTEMA,
        ['pagos' => 0, 'efectivo' => '0.00', 'transferencia' => '0.00', 'total' => '0.00'],
        ['pagos' => 1, 'monto' => '100.00', 'estados' => [
            ['estado' => Pago::PENDIENTE_VERIFICACION, 'pagos' => 1, 'monto' => '100.00'],
            ['estado' => Pago::REPORTADO_POR_VECINO, 'pagos' => 0, 'monto' => '0.00'],
        ]], []],
    'transfer reported by the neighbor' => [Pago::TRANSFERENCIA, Pago::REPORTADO_POR_VECINO, Pago::PORTAL,
        ['pagos' => 0, 'efectivo' => '0.00', 'transferencia' => '0.00', 'total' => '0.00'],
        ['pagos' => 1, 'monto' => '100.00', 'estados' => [
            ['estado' => Pago::PENDIENTE_VERIFICACION, 'pagos' => 0, 'monto' => '0.00'],
            ['estado' => Pago::REPORTADO_POR_VECINO, 'pagos' => 1, 'monto' => '100.00'],
        ]], []],
    'rejected transfer' => [Pago::TRANSFERENCIA, Pago::RECHAZADO, Pago::SISTEMA,
        ['pagos' => 0, 'efectivo' => '0.00', 'transferencia' => '0.00', 'total' => '0.00'], [],
        [['estado' => Pago::ANULADO, 'pagos' => 0, 'monto' => '0.00'], ['estado' => Pago::RECHAZADO, 'pagos' => 1, 'monto' => '100.00']]],
    'voided cash' => [Pago::EFECTIVO, Pago::ANULADO, Pago::SISTEMA,
        ['pagos' => 0, 'efectivo' => '0.00', 'transferencia' => '0.00', 'total' => '0.00'], [],
        [['estado' => Pago::ANULADO, 'pagos' => 1, 'monto' => '100.00'], ['estado' => Pago::RECHAZADO, 'pagos' => 0, 'monto' => '0.00']]],
    'voided transfer' => [Pago::TRANSFERENCIA, Pago::ANULADO, Pago::SISTEMA,
        ['pagos' => 0, 'efectivo' => '0.00', 'transferencia' => '0.00', 'total' => '0.00'], [],
        [['estado' => Pago::ANULADO, 'pagos' => 1, 'monto' => '100.00'], ['estado' => Pago::RECHAZADO, 'pagos' => 0, 'monto' => '0.00']]],
]);

test('the report groups the collection by month', function () {
    ($this->reporte)(['desde' => '2026-10-01', 'hasta' => '2026-11-20', 'agrupar' => 'mes'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.agrupar', 'mes')
            ->where('reporte.periodos', [
                ['periodo' => '2026-10', 'pagos' => 2, 'efectivo' => '700.00', 'transferencia' => '200.00', 'total' => '900.00'],
                ['periodo' => '2026-11', 'pagos' => 6, 'efectivo' => '1345.50', 'transferencia' => '600.00', 'total' => '1945.50'],
            ])
            ->where('reporte.totales', ['pagos' => 8, 'efectivo' => '2045.50', 'transferencia' => '800.00', 'total' => '2845.50']));
});

test('the report groups the collection by year', function () {
    ($this->reporte)(['desde' => '2025-01-01', 'hasta' => '2026-11-20', 'agrupar' => 'anio'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.agrupar', 'anio')
            ->where('reporte.periodos', [
                ['periodo' => '2025', 'pagos' => 1, 'efectivo' => '50.00', 'transferencia' => '0.00', 'total' => '50.00'],
                ['periodo' => '2026', 'pagos' => 8, 'efectivo' => '2045.50', 'transferencia' => '800.00', 'total' => '2845.50'],
            ])
            ->where('reporte.totales', ['pagos' => 9, 'efectivo' => '2095.50', 'transferencia' => '800.00', 'total' => '2895.50']));
});

test('the date range includes both ends', function () {
    ($this->reporte)(['desde' => '2026-11-02', 'hasta' => '2026-11-05'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.periodos', [
                ['periodo' => '2026-11-02', 'pagos' => 2, 'efectivo' => '750.50', 'transferencia' => '0.00', 'total' => '750.50'],
                ['periodo' => '2026-11-03', 'pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '600.00', 'total' => '600.00'],
                ['periodo' => '2026-11-05', 'pagos' => 1, 'efectivo' => '400.00', 'transferencia' => '0.00', 'total' => '400.00'],
            ])
            ->where('reporte.totales.total', '1750.50')
            // Los pendientes y lo sin efecto respetan el mismo rango
            ->where('reporte.pendientes.estados', [
                ['estado' => Pago::PENDIENTE_VERIFICACION, 'pagos' => 1, 'monto' => '300.00'],
                ['estado' => Pago::REPORTADO_POR_VECINO, 'pagos' => 0, 'monto' => '0.00'],
            ])
            ->where('reporte.sin_efecto', [
                ['estado' => Pago::ANULADO, 'pagos' => 1, 'monto' => '999.00'],
                ['estado' => Pago::RECHAZADO, 'pagos' => 0, 'monto' => '0.00'],
            ]));

    // Un solo día
    ($this->reporte)(['desde' => '2026-11-06', 'hasta' => '2026-11-06'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.periodos', [])
            ->where('reporte.totales.total', '0.00')
            ->where('reporte.pendientes.monto', '150.00')
            ->where('reporte.sin_efecto.1', ['estado' => Pago::RECHAZADO, 'pagos' => 1, 'monto' => '80.00']));
});

test('filtering by collector shows only their cash and leaves transfers out', function () {
    $vacio = ($this->sinEstados)();

    ($this->reporte)(['cobrador' => $this->marta->id])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.cobrador', $this->marta->id)
            ->where('reporte.periodos', [
                ['periodo' => '2026-11-02', 'pagos' => 2, 'efectivo' => '750.50', 'transferencia' => '0.00', 'total' => '750.50'],
            ])
            ->where('reporte.totales', ['pagos' => 2, 'efectivo' => '750.50', 'transferencia' => '0.00', 'total' => '750.50'])
            // Los pendientes son transferencias, así que no entran
            ->where('reporte.pendientes', $vacio['pendientes'])
            ->where('reporte.sin_efecto', [
                ['estado' => Pago::ANULADO, 'pagos' => 1, 'monto' => '999.00'],
                ['estado' => Pago::RECHAZADO, 'pagos' => 0, 'monto' => '0.00'],
            ]));

    ($this->reporte)(['cobrador' => $this->luis->id, 'agrupar' => 'mes'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.periodos', [
                ['periodo' => '2026-11', 'pagos' => 2, 'efectivo' => '475.00', 'transferencia' => '0.00', 'total' => '475.00'],
            ])
            ->where('reporte.totales.transferencia', '0.00'));
});

test('the collector and date filters combine', function () {
    ($this->reporte)(['cobrador' => $this->luis->id, 'desde' => '2026-11-06', 'hasta' => '2026-11-20'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.periodos', [
                ['periodo' => '2026-11-20', 'pagos' => 1, 'efectivo' => '75.00', 'transferencia' => '0.00', 'total' => '75.00'],
            ])
            ->where('reporte.totales.total', '75.00'));
});

test('a payment applied to several months adds only once', function () {
    $concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pago = ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-10', '900.00');

    foreach (['2026-09-01', '2026-10-01', '2026-11-01'] as $indice => $periodo) {
        $cargo = DB::table('cargos')->insertGetId([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $this->casa->id,
            'concepto_id' => $concepto,
            'periodo' => $periodo,
            'monto' => '300.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pago_aplicaciones')->insert([
            'residencial_id' => $this->residencial->id,
            'pago_id' => $pago->id,
            'cargo_id' => $cargo,
            'monto' => '300.00',
            // El último mes se cubrió después con el saldo a favor del mismo pago
            'origen' => $indice === 2 ? 'saldo_a_favor' : 'pago',
            'created_at' => now(),
        ]);
    }

    ($this->reporte)(['desde' => '2026-11-10', 'hasta' => '2026-11-10'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.periodos', [
                ['periodo' => '2026-11-10', 'pagos' => 1, 'efectivo' => '900.00', 'transferencia' => '0.00', 'total' => '900.00'],
            ])
            ->where('reporte.totales.total', '900.00'));
});

test('invalid filters are rejected', function (array $filtros, string $campo, string $mensaje) {
    ($this->reporte)($filtros)->assertSessionHasErrors([$campo => $mensaje]);
})->with([
    'invalid start date' => [['desde' => '01/11/2026'], 'desde', 'La fecha inicial no es válida.'],
    'invalid end date' => [['hasta' => '2026-13-01'], 'hasta', 'La fecha final no es válida.'],
    'end before start' => [['desde' => '2026-11-10', 'hasta' => '2026-11-09'], 'hasta', 'La fecha final no puede ser anterior a la inicial.'],
    'unknown grouping' => [['agrupar' => 'semana'], 'agrupar', 'Elige agrupar por día, mes o año.'],
    'collector is not a number' => [['cobrador' => 'marta'], 'cobrador', 'Elige un cobrador del residencial.'],
    'collector does not exist' => [['cobrador' => 999999], 'cobrador', 'Elige un cobrador del residencial.'],
]);

test('the report only includes payments of the residential of the user', function () {
    $ajeno = ($this->usuario)('cobrador', $this->otro, ['name' => 'Ajeno']);
    $casaAjena = ($this->vivienda)($this->otro, 'B-01');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-03', '9999.00', ['vivienda' => $casaAjena, 'cobrador' => $ajeno]);
    ($this->pago)(Pago::TRANSFERENCIA, Pago::PENDIENTE_VERIFICACION, '2026-11-03', '8888.00', ['vivienda' => $casaAjena]);

    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales.total', '1945.50')
            ->where('reporte.pendientes.monto', '450.00')
            ->has('cobradores', 2));

    // El tesorero del otro residencial solo ve lo suyo
    ($this->reporte)([], ($this->usuario)('tesorero', $this->otro))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales', ['pagos' => 1, 'efectivo' => '9999.00', 'transferencia' => '0.00', 'total' => '9999.00'])
            ->where('reporte.pendientes.monto', '8888.00')
            ->where('cobradores', [['id' => $ajeno->id, 'nombre' => 'Ajeno']]));

    // Y no puede filtrar por un cobrador de otro residencial
    ($this->reporte)(['cobrador' => $this->marta->id], ($this->usuario)('tesorero', $this->otro))
        ->assertSessionHasErrors(['cobrador' => 'Elige un cobrador del residencial.']);
});

test('only users with the view reports permission can see the report', function () {
    $this->get(route('reportes.recaudacion.index'))->assertRedirect(route('login'));

    foreach (['cobrador', 'vocal', Rol::SECRETARIO] as $rol) {
        ($this->reporte)([], ($this->usuario)($rol))->assertForbidden();
    }

    foreach (['tesorero', 'fiscal', Rol::PRESIDENTE] as $rol) {
        ($this->reporte)([], ($this->usuario)($rol))->assertOk();
    }
});

test('the confirmed collection matches the fee income of the financial report except for migrated payments', function () {
    $this->actingAs($this->tesorero);

    // Como en el sistema: confirmar genera el ingreso de cuotas y anular lo anula.
    // Los pagos migrados no pasan por la confirmación, así que no generan ingreso.
    foreach (Pago::query()->where('origen', '<>', 'migracion')->orderBy('id')->get() as $pago) {
        if ($pago->estado === Pago::CONFIRMADO || $pago->estado === Pago::ANULADO) {
            event(new PagoConfirmado($pago, $this->tesorero));
        }

        if ($pago->estado === Pago::ANULADO) {
            event(new PagoAnulado($pago, $this->presidente, (string) $pago->motivo_anulacion));
        }
    }

    // El único pago migrado confirmado es el del 1 de noviembre, por L 120.00
    $migrados = Pago::query()->where('origen', 'migracion')->where('estado', Pago::CONFIRMADO)->pluck('monto')->all();
    expect($migrados)->toBe(['120.00']);

    // Noviembre: L 1945.50 recaudados, L 120.00 de ellos migrados
    ($this->reporte)(['desde' => '2026-11-01', 'hasta' => '2026-11-30'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reporte.totales.total', '1945.50'));

    $this->get(route('reportes.financiero.index', ['tipo' => 'mensual', 'anio' => 2026, 'mes' => 11]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('informe.ingresos', [['categoria' => 'Cuotas de mantenimiento', 'total' => '1825.50']]));

    // El año 2026: L 2845.50 recaudados, con el mismo pago migrado
    ($this->reporte)(['desde' => '2026-01-01', 'hasta' => '2026-12-31', 'agrupar' => 'anio'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reporte.totales.total', '2845.50'));

    $this->get(route('reportes.financiero.index', ['tipo' => 'anual', 'anio' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('informe.ingresos', [['categoria' => 'Cuotas de mantenimiento', 'total' => '2725.50']]));
});

test('a migrated confirmed payment without income counts in the collection but not in the financial report', function () {
    $this->actingAs($this->tesorero);

    // Un mes sin otros pagos
    $migrado = ($this->pago)(Pago::TRANSFERENCIA, Pago::CONFIRMADO, '2026-09-10', '350.00', ['origen' => 'migracion']);

    expect(DB::table('ingresos')->where('pago_id', $migrado->id)->exists())->toBeFalse();

    ($this->reporte)(['desde' => '2026-09-01', 'hasta' => '2026-09-30'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales', ['pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '350.00', 'total' => '350.00']));

    $this->get(route('reportes.financiero.index', ['tipo' => 'mensual', 'anio' => 2026, 'mes' => 9]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('informe.ingresos', [])
            ->where('informe.total_ingresos', '0.00'));
});

test('the default range uses the date of Honduras, not the date in UTC', function () {
    // 03:00 del 1 de diciembre en UTC son las 21:00 del 30 de noviembre en Honduras
    Carbon::setTestNow('2026-12-01 03:00:00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-30', '30.00');

    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.desde', '2026-11-01')
            ->where('filtros.hasta', '2026-11-30')
            ->where('reporte.totales.total', '1975.50'));
});

test('on the first day of the month the default range is only that day', function () {
    Carbon::setTestNow('2026-12-01 15:00:00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-12-01', '45.00');

    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.desde', '2026-12-01')
            ->where('filtros.hasta', '2026-12-01')
            ->where('reporte.periodos', [
                ['periodo' => '2026-12-01', 'pagos' => 1, 'efectivo' => '45.00', 'transferencia' => '0.00', 'total' => '45.00'],
            ]));
});

test('on the first day of the year the default range starts in the new year', function () {
    Carbon::setTestNow('2027-01-01 15:00:00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-12-31', '60.00');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::CONFIRMADO, '2027-01-01', '90.00');

    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.desde', '2027-01-01')
            ->where('filtros.hasta', '2027-01-01')
            ->where('reporte.totales', ['pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '90.00', 'total' => '90.00']));
});

test('the year change splits the groups by day, month and year', function (string $agrupar, array $periodos) {
    Carbon::setTestNow('2027-01-05 15:00:00');
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-12-31', '60.00');
    ($this->pago)(Pago::TRANSFERENCIA, Pago::CONFIRMADO, '2027-01-01', '90.00');

    ($this->reporte)(['desde' => '2026-12-31', 'hasta' => '2027-01-01', 'agrupar' => $agrupar])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.periodos', [
                ['periodo' => $periodos[0], 'pagos' => 1, 'efectivo' => '60.00', 'transferencia' => '0.00', 'total' => '60.00'],
                ['periodo' => $periodos[1], 'pagos' => 1, 'efectivo' => '0.00', 'transferencia' => '90.00', 'total' => '90.00'],
            ])
            ->where('reporte.totales.total', '150.00'));
})->with([
    'by day' => ['dia', ['2026-12-31', '2027-01-01']],
    'by month' => ['mes', ['2026-12', '2027-01']],
    'by year' => ['anio', ['2026', '2027']],
]);

test('transfers registered by the selected collector are left out', function () {
    // Marta también registra transferencias
    ($this->pago)(Pago::TRANSFERENCIA, Pago::CONFIRMADO, '2026-11-10', '300.00', ['registrado_por' => $this->marta->id]);
    ($this->pago)(Pago::TRANSFERENCIA, Pago::PENDIENTE_VERIFICACION, '2026-11-11', '110.00', ['registrado_por' => $this->marta->id]);
    ($this->pago)(Pago::TRANSFERENCIA, Pago::RECHAZADO, '2026-11-12', '40.00', ['registrado_por' => $this->marta->id]);

    // Sin cobrador, sus transferencias sí entran
    ($this->reporte)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales.transferencia', '900.00')
            ->where('reporte.pendientes.monto', '560.00'));

    ($this->reporte)(['cobrador' => $this->marta->id])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales', ['pagos' => 2, 'efectivo' => '750.50', 'transferencia' => '0.00', 'total' => '750.50'])
            ->where('reporte.pendientes', ($this->sinEstados)()['pendientes'])
            ->where('reporte.sin_efecto', [
                ['estado' => Pago::ANULADO, 'pagos' => 1, 'monto' => '999.00'],
                ['estado' => Pago::RECHAZADO, 'pagos' => 0, 'monto' => '0.00'],
            ]));
});

test('selecting the treasurer as collector does not attribute their transfers to them', function () {
    // El tesorero registró todas las transferencias del escenario
    ($this->reporte)(['cobrador' => $this->tesorero->id])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.cobrador', $this->tesorero->id)
            ->where('reporte.periodos', [])
            ->where('reporte.totales', ['pagos' => 0, 'efectivo' => '0.00', 'transferencia' => '0.00', 'total' => '0.00'])
            ->where('reporte.pendientes', ($this->sinEstados)()['pendientes'])
            ->where('reporte.sin_efecto', ($this->sinEstados)()['sin_efecto']));
});

test('the technical admin with full access sees only the report of their residential', function () {
    ($this->pago)(Pago::EFECTIVO, Pago::CONFIRMADO, '2026-11-03', '9999.00', ['vivienda' => ($this->vivienda)($this->otro, 'B-01'), 'cobrador' => ($this->usuario)('cobrador', $this->otro)]);

    ($this->reporte)([], ($this->usuario)(Rol::ADMIN_TECNICO))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.totales.total', '1945.50')
            ->has('cobradores', 2));
});

test('an account without a residential cannot see the report', function () {
    $sinResidencial = User::factory()->create(['residencial_id' => null, 'rol_id' => ($this->rol)('tesorero')]);

    ($this->reporte)([], $sinResidencial)->assertForbidden();
});
