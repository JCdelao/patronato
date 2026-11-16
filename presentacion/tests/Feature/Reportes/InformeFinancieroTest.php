<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Reportes\InformeFinanciero;
use Patronato\Negocio\Services\InformeFinancieroService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    // 9:00 del 15 de noviembre de 2026 en Honduras
    $this->travelTo('2026-11-15 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->tesorero = ($this->usuario)('tesorero');

    $categoria = fn (Residencial $residencial, string $tipo, string $nombre): int => (int) CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', $residencial->id)
        ->where('tipo', $tipo)
        ->where('nombre', $nombre)
        ->value('id');

    // Inserta un movimiento directo en la tabla, como lo dejan las HU-31 y HU-32
    $this->movimiento = function (string $tabla, string $fecha, string $monto, string $categoriaNombre, string $estado = 'vigente', ?Residencial $residencial = null) use ($categoria): void {
        $residencial ??= $this->residencial;

        DB::table($tabla)->insert([
            'residencial_id' => $residencial->id,
            'categoria_id' => $categoria($residencial, $tabla === 'ingresos' ? 'ingreso' : 'egreso', $categoriaNombre),
            'fecha' => $fecha,
            'concepto' => "Movimiento del {$fecha}",
            'monto' => $monto,
            'numero_comprobante' => 'C-1',
            'estado' => $estado,
            'motivo_anulacion' => $estado === 'anulado' ? 'Registro duplicado.' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->ingreso = fn (string $fecha, string $monto, string $categoria = 'Cuotas de mantenimiento', string $estado = 'vigente', ?Residencial $residencial = null) => ($this->movimiento)('ingresos', $fecha, $monto, $categoria, $estado, $residencial);
    $this->egreso = fn (string $fecha, string $monto, string $categoria = 'Vigilancia', string $estado = 'vigente', ?Residencial $residencial = null) => ($this->movimiento)('egresos', $fecha, $monto, $categoria, $estado, $residencial);

    $this->apertura = fn (string $monto, string $fecha, ?Residencial $residencial = null) => DB::table('configuracion_residencial')->updateOrInsert(
        ['residencial_id' => ($residencial ?? $this->residencial)->id],
        ['saldo_apertura' => $monto, 'fecha_apertura' => $fecha, 'updated_at' => now()],
    );

    // El informe tal como lo arma el servicio para el tesorero
    $this->informe = function (string $tipo, int $anio, int $mes = 1): InformeFinanciero {
        $this->actingAs($this->tesorero);

        return app(InformeFinancieroService::class)->generar($this->tesorero, ['tipo' => $tipo, 'anio' => $anio, 'mes' => $mes]);
    };

    $this->centavos = fn (?string $monto): int => (int) round((float) $monto * 100);
});

test('the monthly report shows the initial balance, the income and expenses by category and the final balance', function () {
    // Octubre deja L 700.00 para empezar noviembre
    ($this->ingreso)('2026-10-20', '1000.00');
    ($this->egreso)('2026-10-25', '300.00');

    ($this->ingreso)('2026-11-01', '500.00');
    ($this->ingreso)('2026-11-14', '250.50');
    ($this->ingreso)('2026-11-03', '100.00', 'Donaciones');
    ($this->egreso)('2026-11-05', '400.00');
    ($this->egreso)('2026-11-30', '120.25', 'Servicios públicos');

    // No suman: anulados y movimientos de otro residencial
    ($this->ingreso)('2026-11-02', '999.00', 'Multas', 'anulado');
    ($this->egreso)('2026-11-02', '888.00', 'Vigilancia', 'anulado');
    ($this->ingreso)('2026-11-02', '5000.00', 'Cuotas de mantenimiento', 'vigente', $this->otro);

    $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reportes/financiero')
            ->where('periodo', ['tipo' => 'mensual', 'anio' => 2026, 'mes' => 11])
            ->where('informe.titulo', 'Noviembre de 2026')
            ->where('informe.desde', '2026-11-01')
            ->where('informe.hasta', '2026-11-30')
            ->where('informe.saldo_inicial', '700.00')
            ->where('informe.apertura', null)
            ->where('informe.ingresos', [
                ['categoria' => 'Cuotas de mantenimiento', 'total' => '750.50'],
                ['categoria' => 'Donaciones', 'total' => '100.00'],
            ])
            ->where('informe.egresos', [
                ['categoria' => 'Servicios públicos', 'total' => '120.25'],
                ['categoria' => 'Vigilancia', 'total' => '400.00'],
            ])
            ->where('informe.total_ingresos', '850.50')
            ->where('informe.total_egresos', '520.25')
            ->where('informe.saldo_final', '1030.25')
            ->where('informe.meses', []));
});

test('the totals add up month after month and in the year', function () {
    mt_srand(33);
    $categoriasIngreso = ['Cuotas de mantenimiento', 'Alquiler de áreas comunes', 'Multas', 'Donaciones'];
    $categoriasEgreso = ['Vigilancia', 'Mantenimiento y reparaciones', 'Servicios públicos', 'Limpieza y jardinería'];

    // Movimientos de 2025 y de 2026 hasta hoy, con montos con centavos y algunos anulados
    foreach (['2025-06-10', '2025-12-31'] as $fecha) {
        ($this->ingreso)($fecha, '1234.56');
        ($this->egreso)($fecha, '234.50');
    }

    for ($i = 0; $i < 120; $i++) {
        $fecha = sprintf('2026-%02d-%02d', mt_rand(1, 11), mt_rand(1, 28));
        $monto = sprintf('%d.%02d', mt_rand(1, 3000), mt_rand(0, 99));
        $estado = mt_rand(1, 10) === 1 ? 'anulado' : 'vigente';

        mt_rand(0, 1) === 0
            ? ($this->ingreso)($fecha, $monto, $categoriasIngreso[mt_rand(0, 3)], $estado)
            : ($this->egreso)($fecha, $monto, $categoriasEgreso[mt_rand(0, 3)], $estado);
    }

    ($this->ingreso)('2026-05-05', '7777.77', 'Cuotas de mantenimiento', 'vigente', $this->otro);
    ($this->apertura)('15000.00', '2026-03-10');

    $anual = ($this->informe)('anual', 2026);
    $c = $this->centavos;

    expect($anual->meses)->toHaveCount(11)
        ->and($anual->meses[0]['saldo_inicial'])->toBe($anual->saldoInicial)
        ->and($anual->meses[count($anual->meses) - 1]['saldo_final'])->toBe($anual->saldoFinal)
        ->and($anual->apertura)->toBe(['fecha' => '2026-03-10', 'monto' => '15000.00']);

    $sumaIngresos = 0;
    $sumaEgresos = 0;

    foreach ($anual->meses as $i => $fila) {
        expect($c($fila['saldo_final']))->toBe($c($fila['saldo_inicial']) + $c($fila['apertura']) + $c($fila['ingresos']) - $c($fila['egresos']));

        if ($i > 0) {
            expect($fila['saldo_inicial'])->toBe($anual->meses[$i - 1]['saldo_final']);
        }

        // El mensual del mismo mes da exactamente lo mismo que su fila del anual
        $mensual = ($this->informe)('mensual', 2026, $i + 1);

        expect($mensual->saldoInicial)->toBe($fila['saldo_inicial'])
            ->and($mensual->totalIngresos)->toBe($fila['ingresos'])
            ->and($mensual->totalEgresos)->toBe($fila['egresos'])
            ->and($mensual->saldoFinal)->toBe($fila['saldo_final'])
            ->and($mensual->apertura === null)->toBe($fila['apertura'] === null)
            ->and(array_sum(array_map(fn (array $f): int => $c($f['total']), $mensual->ingresos)))->toBe($c($mensual->totalIngresos))
            ->and(array_sum(array_map(fn (array $f): int => $c($f['total']), $mensual->egresos)))->toBe($c($mensual->totalEgresos));

        $sumaIngresos += $c($fila['ingresos']);
        $sumaEgresos += $c($fila['egresos']);
    }

    expect($sumaIngresos)->toBe($c($anual->totalIngresos))
        ->and($sumaEgresos)->toBe($c($anual->totalEgresos))
        ->and(array_sum(array_map(fn (array $f): int => $c($f['total']), $anual->ingresos)))->toBe($c($anual->totalIngresos));

    // Cálculo independiente con la base: apertura + todo lo vigente del residencial hasta hoy
    $vigente = fn (string $tabla): int => $c((string) DB::table($tabla)->where('residencial_id', $this->residencial->id)->where('estado', 'vigente')->sum('monto'));
    $inicial2025 = $c('1234.56') * 2 - $c('234.50') * 2;

    expect($c($anual->saldoInicial))->toBe($inicial2025)
        ->and($c($anual->saldoFinal))->toBe($c('15000.00') + $vigente('ingresos') - $vigente('egresos'));
});

test('the opening balance enters on its date and then becomes part of the initial balance', function () {
    ($this->ingreso)('2026-09-10', '100.00');
    ($this->apertura)('5000.00', '2026-10-05');
    ($this->egreso)('2026-10-20', '300.00');

    $septiembre = ($this->informe)('mensual', 2026, 9);
    $octubre = ($this->informe)('mensual', 2026, 10);
    $noviembre = ($this->informe)('mensual', 2026, 11);

    expect($septiembre->apertura)->toBeNull()
        ->and($septiembre->saldoFinal)->toBe('100.00')
        ->and($octubre->saldoInicial)->toBe('100.00')
        ->and($octubre->apertura)->toBe(['fecha' => '2026-10-05', 'monto' => '5000.00'])
        ->and($octubre->saldoFinal)->toBe('4800.00')
        ->and($noviembre->apertura)->toBeNull()
        ->and($noviembre->saldoInicial)->toBe('4800.00');
});

test('the annual report of the current year stops at the current month and a past year has twelve months', function () {
    ($this->ingreso)('2025-02-14', '300.00', 'Donaciones');

    $actual = ($this->informe)('anual', 2026);
    $pasado = ($this->informe)('anual', 2025);

    expect(array_column($actual->meses, 'mes'))->toBe(array_map(fn (int $m): string => sprintf('2026-%02d', $m), range(1, 11)))
        ->and($actual->titulo)->toBe('Año 2026')
        ->and($actual->desde)->toBe('2026-01-01')
        ->and($actual->hasta)->toBe('2026-12-31')
        ->and($actual->saldoInicial)->toBe('300.00')
        ->and($pasado->meses)->toHaveCount(12)
        ->and($pasado->meses[1])->toBe([
            'mes' => '2025-02',
            'saldo_inicial' => '0.00',
            'apertura' => null,
            'ingresos' => '300.00',
            'egresos' => '0.00',
            'saldo_final' => '300.00',
        ])
        ->and($pasado->saldoFinal)->toBe('300.00');
});

test('a future period or an unknown report type is rejected', function (array $consulta, string $campo) {
    $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.index', $consulta))
        ->assertSessionHasErrors($campo);
})->with([
    'next month' => [['tipo' => 'mensual', 'anio' => 2026, 'mes' => 12], 'mes'],
    'next year' => [['tipo' => 'anual', 'anio' => 2027], 'anio'],
    'invalid month' => [['tipo' => 'mensual', 'anio' => 2026, 'mes' => 13], 'mes'],
    'unknown type' => [['tipo' => 'trimestral', 'anio' => 2026], 'tipo'],
]);

test('a month of a past year can be requested even if that month has not come this year', function () {
    $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.index', ['tipo' => 'mensual', 'anio' => 2025, 'mes' => 12]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('informe.titulo', 'Diciembre de 2025'));
});

test('the treasurer registers and corrects the opening balance and each change is audited', function () {
    $this->actingAs($this->tesorero)
        ->from(route('reportes.financiero.index'))
        ->post(route('reportes.financiero.apertura'), ['monto' => '12500.5', 'fecha' => '2026-01-01'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('reportes.financiero.index'));

    $this->actingAs($this->tesorero)
        ->post(route('reportes.financiero.apertura'), ['monto' => '12000', 'fecha' => '2026-02-01'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('apertura', ['monto' => '12000.00', 'fecha' => '2026-02-01']));

    $registros = Auditoria::query()->where('accion', 'apertura_registrada')->orderBy('id')->get();

    expect($registros)->toHaveCount(2)
        ->and($registros[0]->modulo)->toBe('tesoreria')
        ->and($registros[0]->valor_anterior)->toBe(['monto' => null, 'fecha' => null])
        ->and($registros[0]->valor_nuevo)->toBe(['monto' => '12500.50', 'fecha' => '2026-01-01'])
        ->and($registros[1]->valor_anterior)->toBe(['monto' => '12500.50', 'fecha' => '2026-01-01'])
        ->and($registros[1]->valor_nuevo)->toBe(['monto' => '12000.00', 'fecha' => '2026-02-01']);
});

test('the opening balance must have a non negative amount and a date that is not in the future', function (array $datos, string $campo) {
    $this->actingAs($this->tesorero)
        ->post(route('reportes.financiero.apertura'), $datos)
        ->assertSessionHasErrors($campo);

    expect(DB::table('configuracion_residencial')->where('residencial_id', $this->residencial->id)->value('saldo_apertura'))->toBeNull();
})->with([
    'missing amount' => [['fecha' => '2026-01-01'], 'monto'],
    'negative amount' => [['monto' => '-1', 'fecha' => '2026-01-01'], 'monto'],
    'missing date' => [['monto' => '100'], 'fecha'],
    'future date' => [['monto' => '100', 'fecha' => '2026-11-16'], 'fecha'],
]);

test('the president and the auditor see and export the report but cannot change the opening balance', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('reportes.financiero.index'))->assertOk();
    $this->actingAs($usuario)->get(route('reportes.financiero.exportar', ['formato' => 'excel']))->assertOk();
    $this->actingAs($usuario)
        ->post(route('reportes.financiero.apertura'), ['monto' => '100', 'fecha' => '2026-01-01'])
        ->assertForbidden();
})->with(['presidente', 'fiscal']);

test('other roles cannot see the report', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('reportes.financiero.index'))->assertForbidden();
    $this->actingAs($usuario)->get(route('reportes.financiero.exportar', ['formato' => 'pdf']))->assertForbidden();
    $this->actingAs($usuario)->post(route('reportes.financiero.apertura'), ['monto' => '100', 'fecha' => '2026-01-01'])->assertForbidden();
})->with(['secretario', 'vocal', 'cobrador']);

test('each residential sees only its own movements and its own opening balance', function () {
    ($this->ingreso)('2026-11-02', '400.00');
    ($this->ingreso)('2026-11-02', '9000.00', 'Cuotas de mantenimiento', 'vigente', $this->otro);
    ($this->apertura)('1000.00', '2026-01-01', $this->otro);

    $ajeno = ($this->usuario)('tesorero', $this->otro);

    expect(($this->informe)('mensual', 2026, 11)->saldoFinal)->toBe('400.00');

    $this->actingAs($ajeno)
        ->get(route('reportes.financiero.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('informe.saldo_inicial', '1000.00')
            ->where('informe.total_ingresos', '9000.00')
            ->where('informe.saldo_final', '10000.00'));
});

test('the report is exported to pdf with the same period as the screen', function () {
    ($this->ingreso)('2026-10-02', '1500.00', 'Alquiler de áreas comunes');
    ($this->egreso)('2026-10-03', '200.00');

    // Captura lo que recibe la plantilla sin depender de leer el PDF
    $generador = new class implements GeneradorPdf
    {
        /** @var array{vista: string, html: string, informe: InformeFinanciero}|null */
        public ?array $recibido = null;

        public function generar(string $vista, array $datos): string
        {
            $this->recibido = ['vista' => $vista, 'html' => view($vista, $datos)->render(), 'informe' => $datos['informe']];

            return '%PDF-simulado';
        }
    };

    $this->app->instance(GeneradorPdf::class, $generador);

    $this->actingAs(($this->usuario)('fiscal'))
        ->get(route('reportes.financiero.exportar', ['formato' => 'pdf', 'tipo' => 'mensual', 'anio' => 2026, 'mes' => 10]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="informe-financiero-2026-10.pdf"');

    expect($generador->recibido['vista'])->toBe('pdf.informe-financiero')
        ->and($generador->recibido['informe']->titulo)->toBe('Octubre de 2026')
        ->and($generador->recibido['informe']->saldoFinal)->toBe('1300.00')
        ->and($generador->recibido['html'])->toContain('Las Palmas')
        ->and($generador->recibido['html'])->toContain('Alquiler de áreas comunes')
        ->and($generador->recibido['html'])->toContain('L 1,300.00');
});

test('the real pdf is generated for the annual report', function () {
    ($this->ingreso)('2026-03-02', '800.00');
    ($this->apertura)('2000.00', '2026-01-01');

    $respuesta = $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.exportar', ['formato' => 'pdf', 'tipo' => 'anual', 'anio' => 2026]));

    $respuesta->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="informe-financiero-2026.pdf"');

    expect(substr((string) $respuesta->getContent(), 0, 5))->toBe('%PDF-');
});

test('the annual report is exported to excel with a summary sheet and a month by month sheet', function () {
    ($this->apertura)('2000.00', '2026-01-01');
    ($this->ingreso)('2026-01-10', '500.25');
    ($this->egreso)('2026-02-10', '100.00', 'Servicios públicos');

    $respuesta = $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.exportar', ['formato' => 'excel', 'tipo' => 'anual', 'anio' => 2026]));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="informe-financiero-2026.xlsx"');

    $hojas = leerExcel((string) $respuesta->getContent());

    // toEqual: Excel guarda 400 y 400.0 igual, y las celdas vacías se leen como ''
    expect(array_keys($hojas))->toBe(['Resumen', 'Por mes'])
        ->and($hojas['Resumen'])->toEqual([
            ['Concepto', 'Categoría', 'Monto (L)'],
            ['Año 2026', '', ''],
            ['Saldo inicial', '', 0],
            ['Saldo de apertura', '2026-01-01', 2000],
            ['Ingreso', 'Cuotas de mantenimiento', 500.25],
            ['Total de ingresos', '', 500.25],
            ['Egreso', 'Servicios públicos', 100],
            ['Total de egresos', '', 100],
            ['Saldo final', '', 2400.25],
        ])
        ->and($hojas['Por mes'][0])->toBe(['Mes', 'Saldo inicial (L)', 'Apertura (L)', 'Ingresos (L)', 'Egresos (L)', 'Saldo final (L)'])
        ->and($hojas['Por mes'][1])->toEqual(['2026-01', 0, 2000, 500.25, 0, 2500.25])
        ->and($hojas['Por mes'][2])->toEqual(['2026-02', 2500.25, '', 0, 100, 2400.25])
        ->and($hojas['Por mes'])->toHaveCount(12);
});

test('the monthly excel has only the summary sheet', function () {
    $hojas = leerExcel((string) $this->actingAs($this->tesorero)
        ->get(route('reportes.financiero.exportar', ['formato' => 'excel']))
        ->getContent());

    expect(array_keys($hojas))->toBe(['Resumen'])
        ->and($hojas['Resumen'][1][0])->toBe('Noviembre de 2026');
});
