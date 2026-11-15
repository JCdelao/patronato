<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Reader\XLSX\Reader;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Reportes\ReporteCobros;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

/**
 * Lee todas las hojas de un .xlsx como arreglos de valores
 *
 * @return array<string, list<list<mixed>>>
 */
function leerExcel(string $contenido): array
{
    $archivo = tempnam(sys_get_temp_dir(), 'prueba');
    file_put_contents($archivo, $contenido);

    $lector = new Reader;
    $lector->open($archivo);
    $hojas = [];

    foreach ($lector->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $hojas[$hoja->getName()][] = $fila->toArray();
        }
    }

    $lector->close();
    unlink($archivo);

    return $hojas;
}

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
        ...$datos,
    ]);

    $this->fiscal = ($this->usuario)('fiscal');
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

    $this->caja = fn (User $cobrador, string $estado = Caja::ABIERTA): Caja => Caja::query()->create([
        'residencial_id' => $cobrador->residencial_id,
        'cobrador_id' => $cobrador->id,
        'estado' => $estado,
        'abierta_en' => now(),
    ]);

    $recibo = 0;

    $this->cobro = function (Caja $caja, string $fecha, string $monto, string $estado = Pago::CONFIRMADO, ?Vivienda $vivienda = null) use (&$recibo): Pago {
        $recibo++;

        return Pago::query()->create([
            'residencial_id' => $caja->residencial_id,
            'vivienda_id' => ($vivienda ?? $this->casa)->id,
            'forma_pago' => Pago::EFECTIVO,
            'estado' => $estado,
            'origen' => 'sistema',
            'monto' => $monto,
            'fecha_pago' => $fecha,
            'numero_recibo' => (string) $recibo,
            'caja_id' => $caja->id,
            'registrado_por' => $caja->cobrador_id,
        ]);
    };

    // Marta: una caja ya cerrada en octubre y otra abierta en noviembre
    $cerrada = ($this->caja)($this->marta);
    ($this->cobro)($cerrada, '2026-10-30', '300.00');
    $cerrada->forceFill(['estado' => Caja::CERRADA, 'cerrada_en' => now()])->save();

    $abierta = ($this->caja)($this->marta);
    ($this->cobro)($abierta, '2026-11-02', '500.00');
    ($this->cobro)($abierta, '2026-11-02', '250.50');
    ($this->cobro)($abierta, '2026-11-05', '100.00');
    ($this->cobro)($abierta, '2026-11-05', '999.00', Pago::ANULADO);

    // Luis: una caja abierta en noviembre
    ($this->cobro)(($this->caja)($this->luis), '2026-11-03', '400.00');

    $this->filtros = ['desde' => '2026-10-01', 'hasta' => '2026-11-30'];

    $this->travelTo('2026-11-14 15:00:00');
});

test('the report groups payments by collector and day with totals', function () {
    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', $this->filtros))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reportes/cobros')
            ->where('filtros.agrupar', 'dia')
            ->where('reporte.resumen', [
                ['cobrador_id' => $this->luis->id, 'cobrador' => 'Luis', 'periodo' => '2026-11-03', 'cobros' => 1, 'total' => '400.00'],
                ['cobrador_id' => $this->marta->id, 'cobrador' => 'Marta', 'periodo' => '2026-10-30', 'cobros' => 1, 'total' => '300.00'],
                ['cobrador_id' => $this->marta->id, 'cobrador' => 'Marta', 'periodo' => '2026-11-02', 'cobros' => 2, 'total' => '750.50'],
                ['cobrador_id' => $this->marta->id, 'cobrador' => 'Marta', 'periodo' => '2026-11-05', 'cobros' => 1, 'total' => '100.00'],
            ])
            ->where('reporte.subtotales', [
                ['cobrador_id' => $this->luis->id, 'cobrador' => 'Luis', 'cobros' => 1, 'total' => '400.00'],
                ['cobrador_id' => $this->marta->id, 'cobrador' => 'Marta', 'cobros' => 4, 'total' => '1150.50'],
            ])
            ->where('reporte.total_cobros', 5)
            ->where('reporte.total', '1550.50')
            ->where('reporte.anulados', 1)
            ->where('reporte.monto_anulado', '999.00'));
});

test('the report groups payments by month', function () {
    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', [...$this->filtros, 'agrupar' => 'mes']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.agrupar', 'mes')
            ->where('reporte.resumen', [
                ['cobrador_id' => $this->luis->id, 'cobrador' => 'Luis', 'periodo' => '2026-11', 'cobros' => 1, 'total' => '400.00'],
                ['cobrador_id' => $this->marta->id, 'cobrador' => 'Marta', 'periodo' => '2026-10', 'cobros' => 1, 'total' => '300.00'],
                ['cobrador_id' => $this->marta->id, 'cobrador' => 'Marta', 'periodo' => '2026-11', 'cobros' => 3, 'total' => '850.50'],
            ]));
});

test('the report filters by collector, date range and box closing state', function (array $filtros, int $cobros, string $total) {
    $filtros = array_map(fn ($valor) => $valor instanceof Closure ? $valor() : $valor, $filtros);

    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', [...$this->filtros, ...$filtros]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.total_cobros', $cobros)
            ->where('reporte.total', $total));
})->with([
    'one collector' => [['cobrador' => fn () => test()->luis->id], 1, '400.00'],
    'date range' => [['desde' => '2026-11-02', 'hasta' => '2026-11-03'], 3, '1150.50'],
    'single day' => [['desde' => '2026-11-05', 'hasta' => '2026-11-05'], 1, '100.00'],
    'open boxes' => [['estado' => 'abierta'], 4, '1250.50'],
    'closed boxes' => [['estado' => 'cerrada'], 1, '300.00'],
    'collector and closed boxes' => [['cobrador' => fn () => test()->luis->id, 'estado' => 'cerrada'], 0, '0.00'],
]);

test('without dates the report shows the current month up to today', function () {
    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filtros.desde', '2026-11-01')
            ->where('filtros.hasta', '2026-11-14')
            ->where('filtros.estado', 'todas')
            ->where('reporte.total', '1250.50')
            ->where('cobradores', [
                ['id' => $this->luis->id, 'nombre' => 'Luis'],
                ['id' => $this->marta->id, 'nombre' => 'Marta'],
            ]));
});

test('invalid filters are rejected', function (array $filtros, string $campo) {
    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', $filtros))
        ->assertSessionHasErrors($campo);
})->with([
    'end before start' => [['desde' => '2026-11-10', 'hasta' => '2026-11-01'], 'hasta'],
    'bad date' => [['desde' => '10/11/2026'], 'desde'],
    'unknown state' => [['estado' => 'pendiente'], 'estado'],
    'unknown grouping' => [['agrupar' => 'semana'], 'agrupar'],
    'collector of another residential' => [['cobrador' => 999], 'cobrador'],
]);

test('the report is exported to pdf with the same filters', function () {
    // Captura lo que recibe la plantilla sin depender de leer el PDF
    $generador = new class implements GeneradorPdf
    {
        /** @var array{vista: string, html: string, reporte: ReporteCobros}|null */
        public ?array $recibido = null;

        public function generar(string $vista, array $datos): string
        {
            $this->recibido = ['vista' => $vista, 'html' => view($vista, $datos)->render(), 'reporte' => $datos['reporte']];

            return '%PDF-simulado';
        }
    };

    $this->app->instance(GeneradorPdf::class, $generador);

    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.exportar', ['formato' => 'pdf', ...$this->filtros, 'cobrador' => $this->marta->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="cobros-por-cobrador-2026-10-01_2026-11-30.pdf"');

    $recibido = $generador->recibido;

    expect($recibido['vista'])->toBe('pdf.reporte-cobros')
        ->and($recibido['reporte'])->toBeInstanceOf(ReporteCobros::class)
        ->and($recibido['reporte']->total)->toBe('1150.50')
        ->and($recibido['reporte']->detalle)->toHaveCount(5)
        ->and($recibido['html'])->toContain('Cobrador: Marta')
        ->toContain('Subtotal Marta')
        ->toContain('L 1,150.50')
        ->toContain('Anulados (no suman)')
        ->toContain('Detalle de cobros')
        ->not->toContain('Luis');
});

test('the real pdf is generated', function () {
    $respuesta = $this->actingAs($this->fiscal)->get(route('reportes.cobros.exportar', ['formato' => 'pdf', ...$this->filtros]));

    $respuesta->assertOk();

    expect(substr((string) $respuesta->getContent(), 0, 5))->toBe('%PDF-');
});

test('the report is exported to excel with a summary sheet and a detail sheet', function () {
    $respuesta = $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.exportar', ['formato' => 'excel', ...$this->filtros, 'agrupar' => 'mes']));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="cobros-por-cobrador-2026-10-01_2026-11-30.xlsx"');

    $hojas = leerExcel((string) $respuesta->getContent());

    // toEqual: Excel guarda 400 y 400.0 igual, y las celdas vacías se leen como ''
    expect(array_keys($hojas))->toBe(['Resumen', 'Detalle'])
        ->and($hojas['Resumen'])->toEqual([
            ['Cobrador', 'Mes', 'Cobros', 'Total (L)'],
            ['Luis', '2026-11', 1, 400],
            ['Marta', '2026-10', 1, 300],
            ['Marta', '2026-11', 3, 850.5],
            ['Subtotal Luis', '', 1, 400],
            ['Subtotal Marta', '', 4, 1150.5],
            ['Total', '', 5, 1550.5],
            ['Anulados (no suman)', '', 1, 999],
        ])
        ->and($hojas['Detalle'][0])->toBe(['Fecha', 'Recibo', 'Cobrador', 'Vivienda', 'Monto (L)', 'Estado del cobro', 'Estado de la caja'])
        ->and($hojas['Detalle'])->toHaveCount(7)
        ->and($hojas['Detalle'][1])->toEqual(['2026-11-03', '6', 'Luis', 'A-01', 400, 'confirmado', 'abierta']);
});

test('only users with the view reports permission can see and export the report', function () {
    $rutas = [
        fn () => $this->get(route('reportes.cobros.index')),
        fn () => $this->get(route('reportes.cobros.exportar', ['formato' => 'pdf'])),
        fn () => $this->get(route('reportes.cobros.exportar', ['formato' => 'excel'])),
    ];

    foreach ($rutas as $ruta) {
        $ruta()->assertRedirect(route('login'));
    }

    foreach (['cobrador', 'vocal', Rol::SECRETARIO] as $rol) {
        $this->actingAs(($this->usuario)($rol));

        foreach ($rutas as $ruta) {
            $ruta()->assertForbidden();
        }
    }

    foreach (['fiscal', 'tesorero', Rol::PRESIDENTE] as $rol) {
        $this->actingAs(($this->usuario)($rol))->get(route('reportes.cobros.index'))->assertOk();
    }
});

test('unknown export formats do not exist', function () {
    $this->actingAs($this->fiscal)->get('/reportes/cobros/exportar/csv')->assertNotFound();
});

test('existing residentials give the report permission to the fiscal, treasurer and president', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'reportes')->delete();

    $fiscal = ($this->usuario)('fiscal', $antiguo);
    $this->actingAs($fiscal)->get(route('reportes.cobros.index'))->assertForbidden();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_14_200001_asignar_reportes_a_fiscal_tesorero_y_presidente.php');
    $migracion->up();
    $migracion->up();

    $roles = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('rol_permisos.residencial_id', $antiguo->id)
        ->where('rol_permisos.modulo', 'reportes')
        ->orderBy('roles.codigo')
        ->pluck('roles.codigo')
        ->all();

    expect($roles)->toBe(['fiscal', 'presidente', 'tesorero']);

    $this->actingAs($fiscal)->get(route('reportes.cobros.index'))->assertOk();
});

test('the report only includes payments of the residential of the user', function () {
    $ajeno = ($this->usuario)('cobrador', $this->otro, ['name' => 'Ajeno']);
    ($this->cobro)(($this->caja)($ajeno), '2026-11-04', '7000.00', Pago::CONFIRMADO, ($this->vivienda)($this->otro, 'B-01'));

    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', $this->filtros))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.total', '1550.50')
            ->has('cobradores', 2));

    // El fiscal del otro residencial solo ve lo suyo
    $this->actingAs(($this->usuario)('fiscal', $this->otro))
        ->get(route('reportes.cobros.index', $this->filtros))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.total', '7000.00')
            ->where('cobradores', [['id' => $ajeno->id, 'nombre' => 'Ajeno']]));

    // Y no puede filtrar por un cobrador ajeno
    $this->get(route('reportes.cobros.index', ['cobrador' => $this->marta->id]))->assertSessionHasErrors('cobrador');
});
