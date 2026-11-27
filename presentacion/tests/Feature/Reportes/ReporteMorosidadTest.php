<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Reportes\ReporteMorosidad;
use Patronato\Negocio\Services\CobroService;
use Patronato\Negocio\Services\MoraService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    // 15:00 en UTC son las 09:00 en Honduras
    Carbon::setTestNow('2026-11-20 15:00:00');

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
    ]);

    $this->tesorero = ($this->usuario)('tesorero');
    $this->cobrador = ($this->usuario)('cobrador');

    // Diez días de gracia; la vivienda entra en mora con más de un mes vencido
    DB::table('configuracion_residencial')->updateOrInsert(['residencial_id' => $this->residencial->id], [
        'dias_gracia' => 10,
        'meses_tolerancia' => 1,
        'permite_abono_parcial' => true,
        'updated_at' => now(),
    ]);

    $this->vivienda = fn (string $codigo, bool $activa = true, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    $this->conceptos = [];

    $this->cargo = function (Vivienda $vivienda, string $periodo, string $monto = '500.00'): int {
        $this->conceptos[$vivienda->residencial_id] ??= DB::table('conceptos_cobro')->insertGetId([
            'residencial_id' => $vivienda->residencial_id,
            'nombre' => 'Cuota mensual',
            'tipo' => 'recurrente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('cargos')->insertGetId([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'concepto_id' => $this->conceptos[$vivienda->residencial_id],
            'periodo' => $periodo,
            'monto' => $monto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // A-01 debe septiembre, octubre y noviembre, y tiene una transferencia por verificar
    $this->a01 = ($this->vivienda)('A-01');
    ($this->cargo)($this->a01, '2026-09-01');
    ($this->cargo)($this->a01, '2026-10-01');
    ($this->cargo)($this->a01, '2026-11-01');

    $responsable = Residente::query()->create([
        'residencial_id' => $this->residencial->id,
        'nombres' => 'Rosa',
        'apellidos' => 'Vecina',
        'activo' => true,
    ]);

    ViviendaResidente::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->a01->id,
        'residente_id' => $responsable->id,
        'tipo' => 'propietario',
        'es_responsable_pago' => true,
        'fecha_inicio' => '2025-01-01',
    ]);

    DB::table('pagos')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->a01->id,
        'forma_pago' => Pago::TRANSFERENCIA,
        'estado' => Pago::PENDIENTE_VERIFICACION,
        'monto' => '300.00',
        'fecha_pago' => '2026-11-18',
        'banco' => 'BAC',
        'numero_referencia' => 'REF-1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A-02 debe solo junio: la deuda más antigua y el menor monto
    $this->a02 = ($this->vivienda)('A-02');
    ($this->cargo)($this->a02, '2026-06-01', '300.00');

    // A-03 abonó L 200 a octubre y debe noviembre
    $this->a03 = ($this->vivienda)('A-03');
    $octubre = ($this->cargo)($this->a03, '2026-10-01');
    ($this->cargo)($this->a03, '2026-11-01');
    app(CobroService::class)->registrarEfectivo($this->cobrador, $this->a03->id, '200', [$octubre]);

    // A-04 está al día: no entra al reporte
    $this->a04 = ($this->vivienda)('A-04');
    $pagado = ($this->cargo)($this->a04, '2026-11-01');
    app(CobroService::class)->registrarEfectivo($this->cobrador, $this->a04->id, '500', [$pagado]);

    // A-05 está inactiva y debe agosto
    $this->a05 = ($this->vivienda)('A-05', false);
    ($this->cargo)($this->a05, '2026-08-01', '400.00');

    // A-06 solo debe un ajuste, sin meses pendientes
    $this->a06 = ($this->vivienda)('A-06');
    DB::table('ajustes')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->a06->id,
        'tipo' => 'correccion',
        'monto' => '150.00',
        'fecha' => '2026-11-05',
        'motivo' => 'Cobro pendiente de reparación',
        'autorizado_por' => $this->tesorero->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Otra vivienda con deuda en otro residencial
    $ajena = ($this->vivienda)('A-01', true, $this->otro);
    ($this->cargo)($ajena, '2026-01-01', '9999.00');

    $this->ver = fn (array $consulta = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->get(route('reportes.morosidad.index', $consulta));

    $this->exportar = fn (string $formato, array $consulta = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->get(route('reportes.morosidad.exportar', ['formato' => $formato, ...$consulta]));

    // Captura lo que recibe la plantilla sin depender de leer el PDF
    $this->espia = function (): object {
        $espia = new class implements GeneradorPdf
        {
            public ?string $vista = null;

            public string $html = '';

            public ?ReporteMorosidad $reporte = null;

            public function generar(string $vista, array $datos): string
            {
                $this->vista = $vista;
                $this->html = view($vista, $datos)->render();
                $this->reporte = $datos['reporte'];

                return '%PDF-simulado';
            }
        };

        $this->app->instance(GeneradorPdf::class, $espia);

        return $espia;
    };

    $this->codigos = fn (array $viviendas): array => array_column($viviendas, 'codigo');
});

afterEach(fn () => Carbon::setTestNow());

// Listado

test('the report lists the housing units with a pending balance, their months owed and amount', function () {
    ($this->ver)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reportes/morosidad')
            ->where('reporte.fecha_corte', '2026-11-20')
            ->has('reporte.viviendas', 5)
            ->where('reporte.viviendas.0', [
                'vivienda_id' => $this->a01->id,
                'codigo' => 'A-01',
                'activa' => true,
                'responsable' => 'Rosa Vecina',
                'saldo' => '1500.00',
                'por_verificar' => '300.00',
                'meses_adeudados' => 3,
                'deuda_desde' => '2026-09-01',
                'meses_vencidos' => 3,
                'vencido' => '1500.00',
                'estado' => 'en_mora',
            ])
            ->where('reporte.total', '3150.00')
            ->where('reporte.total_vencido', '3000.00')
            ->where('reporte.en_mora', 2));
});

test('a partially paid month still counts as owed with what is missing', function () {
    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reporte.viviendas.1.codigo', 'A-03')
        ->where('reporte.viviendas.1.saldo', '800.00')
        ->where('reporte.viviendas.1.meses_adeudados', 2)
        ->where('reporte.viviendas.1.deuda_desde', '2026-10-01')
        ->where('reporte.viviendas.1.vencido', '800.00')
        ->where('reporte.viviendas.1.estado', 'en_mora'));
});

test('housing units without debt and those of other residenciales are left out; inactive ones are included', function () {
    ($this->ver)()->assertInertia(function (AssertableInertia $page) {
        $codigos = ($this->codigos)($page->toArray()['props']['reporte']['viviendas']);

        expect($codigos)->not->toContain('A-04')
            ->and($codigos)->toContain('A-05')
            ->and(array_count_values($codigos)['A-01'])->toBe(1);

        $page->where('reporte.viviendas.2.activa', false);
    });
});

test('a balance from an adjustment without pending months shows no months owed', function () {
    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reporte.viviendas.4.codigo', 'A-06')
        ->where('reporte.viviendas.4.saldo', '150.00')
        ->where('reporte.viviendas.4.meses_adeudados', 0)
        ->where('reporte.viviendas.4.deuda_desde', null)
        ->where('reporte.viviendas.4.estado', 'al_dia'));
});

test('a credit adjustment reduces the official balance but is not spread over the overdue months', function () {
    $casa = ($this->vivienda)('C-01');
    ($this->cargo)($casa, '2026-10-01');

    DB::table('ajustes')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $casa->id,
        'tipo' => 'correccion',
        'monto' => '-200.00',
        'fecha' => '2026-11-05',
        'motivo' => 'Descuento autorizado',
        'autorizado_por' => $this->tesorero->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ($this->ver)()->assertInertia(function (AssertableInertia $page) use ($casa) {
        $filas = array_values(array_filter($page->toArray()['props']['reporte']['viviendas'], fn (array $fila): bool => $fila['vivienda_id'] === $casa->id));

        expect($filas)->toHaveCount(1)
            ->and($filas[0])->toMatchArray([
                'saldo' => '300.00',
                'vencido' => '500.00',
                'meses_adeudados' => 1,
                'meses_vencidos' => 1,
                'deuda_desde' => '2026-10-01',
            ])
            ->and($filas[0]['saldo'])->toBe(number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $casa->id)->value('saldo'), 2, '.', ''))
            ->and($filas[0]['vencido'])->toBe(app(MoraService::class)->estadoDe($this->residencial->id, $casa->id)['vencido']);
    });
});

test('payments pending verification are shown apart and do not reduce the balance', function () {
    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reporte.viviendas.0.saldo', '1500.00')
        ->where('reporte.viviendas.0.por_verificar', '300.00')
        ->where('reporte.viviendas.1.por_verificar', '0.00'));
});

test('the balance matches the official balance of each housing unit', function () {
    ($this->ver)()->assertInertia(function (AssertableInertia $page) {
        foreach ($page->toArray()['props']['reporte']['viviendas'] as $fila) {
            $oficial = number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $fila['vivienda_id'])->value('saldo'), 2, '.', '');

            expect($fila['saldo'])->toBe($oficial);
        }
    });
});

test('a residencial without debts shows an empty report', function () {
    $sinDeudas = Residencial::query()->create(['nombre' => 'Sin deudas']);

    ($this->ver)([], ($this->usuario)('tesorero', $sinDeudas))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reporte.viviendas', 0)
            ->where('reporte.total', '0.00')
            ->where('reporte.en_mora', 0));
});

// Criterio 1: ordenar por monto o por antigüedad

test('by default the report is sorted by amount, highest first', function () {
    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reporte.orden', 'monto')
        ->where('reporte.viviendas', fn ($viviendas) => ($this->codigos)($viviendas->all()) === ['A-01', 'A-03', 'A-05', 'A-02', 'A-06']));
});

test('the report can be sorted by the age of the debt, oldest first', function () {
    ($this->ver)(['orden' => 'antiguedad'])->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reporte.orden', 'antiguedad')
        ->where('reporte.viviendas', fn ($viviendas) => ($this->codigos)($viviendas->all()) === ['A-02', 'A-05', 'A-01', 'A-03', 'A-06']));
});

test('debts of the same age are sorted by amount and then by code', function () {
    $b01 = ($this->vivienda)('B-01');
    ($this->cargo)($b01, '2026-06-01', '900.00');
    $b02 = ($this->vivienda)('B-02');
    ($this->cargo)($b02, '2026-06-01', '300.00');

    ($this->ver)(['orden' => 'antiguedad'])->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reporte.viviendas', fn ($viviendas) => array_slice(($this->codigos)($viviendas->all()), 0, 3) === ['B-01', 'A-02', 'B-02']));
});

test('an unknown order is rejected', function () {
    ($this->ver)(['orden' => 'nombre'])->assertSessionHasErrors(['orden' => 'Ordena por monto o por antigüedad.']);
    ($this->exportar)('excel', ['orden' => 'nombre'])->assertSessionHasErrors('orden');
});

// Criterio 2: exportar a PDF y Excel

test('the report is exported to PDF in the chosen order', function () {
    $espia = ($this->espia)();

    ($this->exportar)('pdf', ['orden' => 'antiguedad'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="morosidad-2026-11-20.pdf"');

    expect($espia->vista)->toBe('pdf.reporte-morosidad')
        ->and(($this->codigos)($espia->reporte->viviendas))->toBe(['A-02', 'A-05', 'A-01', 'A-03', 'A-06'])
        ->and($espia->html)->toContain('Las Palmas')
        ->toContain('Ordenado por antigüedad de la deuda')
        ->toContain('Rosa Vecina')
        ->toContain('Septiembre 2026')
        ->toContain('L 3,150.00')
        ->toContain('En mora')
        ->toContain('(inactiva)')
        ->not->toContain('9,999.00');
});

test('the real PDF is generated', function () {
    $respuesta = ($this->exportar)('pdf');

    $respuesta->assertOk();

    expect(substr((string) $respuesta->getContent(), 0, 5))->toBe('%PDF-');
});

test('the report is exported to Excel with one row per housing unit and the totals', function () {
    $respuesta = ($this->exportar)('excel');

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="morosidad-2026-11-20.xlsx"');

    $hojas = leerExcel((string) $respuesta->getContent());

    // toEqual: Excel guarda 400 y 400.0 igual, y las celdas vacías se leen como ''
    expect(array_keys($hojas))->toBe(['Morosidad'])
        ->and($hojas['Morosidad'][0])->toBe(['Vivienda', 'Responsable de pago', 'Meses adeudados', 'Deuda desde', 'Meses vencidos', 'Vencido (L)', 'Saldo (L)', 'Por verificar (L)', 'Estado', 'Vivienda activa'])
        ->and($hojas['Morosidad'])->toHaveCount(7)
        ->and($hojas['Morosidad'][1])->toEqual(['A-01', 'Rosa Vecina', 3, '2026-09-01', 3, 1500, 1500, 300, 'En mora', 'Activa'])
        ->and($hojas['Morosidad'][3])->toEqual(['A-05', '', 1, '2026-08-01', 1, 400, 400, 0, 'Al día', 'Inactiva'])
        ->and($hojas['Morosidad'][6])->toEqual(['Total', '', '', '', '', 3000, 3150, '', '', '']);
});

test('the Excel export follows the chosen order', function () {
    $hojas = leerExcel((string) ($this->exportar)('excel', ['orden' => 'antiguedad'])->getContent());

    expect(array_column(array_slice($hojas['Morosidad'], 1, 5), 0))->toBe(['A-02', 'A-05', 'A-01', 'A-03', 'A-06']);
});

test('each export is audited with the user, format and order', function () {
    ($this->espia)();

    ($this->exportar)('pdf', ['orden' => 'antiguedad'])->assertOk();
    ($this->exportar)('excel')->assertOk();

    $registros = Auditoria::query()->withoutGlobalScopes()->where('accion', 'reporte_morosidad_exportado')->orderBy('id')->get();

    expect($registros)->toHaveCount(2)
        ->and($registros[0]->user_id)->toBe($this->tesorero->id)
        ->and($registros[0]->modulo)->toBe('reportes')
        ->and($registros[0]->valor_nuevo)->toMatchArray(['formato' => 'pdf', 'orden' => 'antiguedad', 'viviendas' => 5, 'total' => '3150.00'])
        ->and($registros[1]->valor_nuevo)->toMatchArray(['formato' => 'excel', 'orden' => 'monto']);
});

test('viewing the report is not audited and does not change financial data', function () {
    $antes = [DB::table('cargos')->count(), DB::table('pagos')->count(), DB::table('pago_aplicaciones')->count(), Auditoria::query()->withoutGlobalScopes()->count()];

    ($this->ver)()->assertOk();
    ($this->ver)(['orden' => 'antiguedad'])->assertOk();

    expect([DB::table('cargos')->count(), DB::table('pagos')->count(), DB::table('pago_aplicaciones')->count(), Auditoria::query()->withoutGlobalScopes()->count()])->toBe($antes);
});

test('unknown export formats do not exist', function () {
    $this->actingAs($this->tesorero)->get('/reportes/morosidad/exportar/csv')->assertNotFound();
});

// Permisos

test('only users with the view reports permission can see and export the report', function () {
    $rutas = [
        fn () => $this->get(route('reportes.morosidad.index')),
        fn () => $this->get(route('reportes.morosidad.exportar', ['formato' => 'pdf'])),
        fn () => $this->get(route('reportes.morosidad.exportar', ['formato' => 'excel'])),
    ];

    foreach ($rutas as $ruta) {
        $ruta()->assertRedirect(route('login'));
    }

    foreach (['cobrador', 'vocal', 'guardia', Rol::SECRETARIO, Rol::RESIDENTE] as $rol) {
        $this->actingAs(($this->usuario)($rol));

        foreach ($rutas as $ruta) {
            $ruta()->assertForbidden();
        }
    }

    foreach (['fiscal', 'tesorero', Rol::PRESIDENTE] as $rol) {
        $this->actingAs(($this->usuario)($rol))->get(route('reportes.morosidad.index'))->assertOk();
    }
});

test('a user of another residencial only sees their own debts', function () {
    ($this->ver)([], ($this->usuario)('tesorero', $this->otro))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reporte.viviendas', 1)
            ->where('reporte.viviendas.0.saldo', '9999.00'));
});
