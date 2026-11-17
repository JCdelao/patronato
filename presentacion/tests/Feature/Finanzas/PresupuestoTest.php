<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Presupuesto;
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

    $this->categoria = fn (string $nombre, ?Residencial $residencial = null, string $tipo = 'egreso'): int => (int) CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('tipo', $tipo)
        ->where('nombre', $nombre)
        ->value('id');

    $this->presupuesto = fn (string $categoria, string $monto, int $anio = 2026, ?Residencial $residencial = null) => DB::table('presupuestos')->insert([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'categoria_id' => ($this->categoria)($categoria, $residencial),
        'anio' => $anio,
        'monto' => $monto,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->egreso = fn (string $categoria, string $monto, string $fecha = '2026-06-10', string $estado = 'vigente', ?Residencial $residencial = null) => DB::table('egresos')->insert([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'categoria_id' => ($this->categoria)($categoria, $residencial),
        'fecha' => $fecha,
        'concepto' => 'Gasto de prueba',
        'monto' => $monto,
        'numero_comprobante' => 'C-1',
        'estado' => $estado,
        'motivo_anulacion' => $estado === 'anulado' ? 'Duplicado.' : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Fila de la comparación por nombre de categoría
    $this->fila = fn (array $comparacion, string $categoria): ?array => collect($comparacion['categorias'])->firstWhere('categoria', $categoria);

    $this->comparacion = function (?User $usuario = null, array $consulta = []): array {
        $comparacion = null;

        $this->actingAs($usuario ?? $this->tesorero)
            ->get(route('finanzas.presupuesto.index', $consulta))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$comparacion) {
                $page->component('finanzas/presupuesto');
                $comparacion = $page->toArray()['props']['comparacion'];
            });

        return $comparacion;
    };

    $this->guardar = fn (array $montos, int $anio = 2026, ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->put(route('finanzas.presupuesto.guardar'), ['anio' => $anio, 'montos' => $montos]);
});

test('each expense category compares the budget with what was spent and highlights those at 90 % or more', function () {
    ($this->presupuesto)('Vigilancia', '10000.00');
    ($this->egreso)('Vigilancia', '8999.99');

    ($this->presupuesto)('Mantenimiento y reparaciones', '5000.00');
    ($this->egreso)('Mantenimiento y reparaciones', '4000.00', '2026-03-01');
    ($this->egreso)('Mantenimiento y reparaciones', '500.00', '2026-11-14');

    ($this->presupuesto)('Servicios públicos', '1000.00');
    ($this->egreso)('Servicios públicos', '1200.00');

    ($this->egreso)('Limpieza y jardinería', '300.00');
    ($this->presupuesto)('Papelería y administración', '2000.00');
    ($this->presupuesto)('Otros gastos', '0');

    // No cuentan: anulados, otro año y otro residencial
    ($this->egreso)('Vigilancia', '5000.00', '2026-07-01', 'anulado');
    ($this->egreso)('Vigilancia', '7000.00', '2025-12-31');
    ($this->egreso)('Vigilancia', '9000.00', '2026-06-10', 'vigente', $this->otro);

    $comparacion = ($this->comparacion)();
    $fila = fn (string $categoria) => ($this->fila)($comparacion, $categoria);

    expect($comparacion['anio'])->toBe(2026)
        // 89.9999 % se muestra redondeado, pero todavía no llega al 90 %
        ->and($fila('Vigilancia'))->toMatchArray(['presupuestado' => '10000.00', 'ejecutado' => '8999.99', 'porcentaje' => 90, 'estado' => 'normal'])
        ->and($fila('Mantenimiento y reparaciones'))->toMatchArray(['presupuestado' => '5000.00', 'ejecutado' => '4500.00', 'porcentaje' => 90, 'estado' => 'alerta'])
        ->and($fila('Servicios públicos'))->toMatchArray(['ejecutado' => '1200.00', 'porcentaje' => 120, 'estado' => 'excedido'])
        ->and($fila('Limpieza y jardinería'))->toMatchArray(['presupuestado' => null, 'ejecutado' => '300.00', 'porcentaje' => null, 'estado' => 'sin_presupuesto'])
        ->and($fila('Papelería y administración'))->toMatchArray(['ejecutado' => '0.00', 'porcentaje' => 0, 'estado' => 'normal'])
        ->and($fila('Otros gastos'))->toMatchArray(['presupuestado' => '0.00', 'porcentaje' => null, 'estado' => 'normal'])
        ->and($comparacion['total_presupuestado'])->toBe('18000.00')
        ->and($comparacion['total_ejecutado'])->toBe('14999.99')
        ->and($comparacion['porcentaje_total'])->toBe(83.3);
});

test('spending without a budget or over a zero budget is reported', function () {
    ($this->presupuesto)('Otros gastos', '0');
    ($this->egreso)('Otros gastos', '50.00');

    expect(($this->fila)(($this->comparacion)(), 'Otros gastos'))->toMatchArray(['presupuestado' => '0.00', 'porcentaje' => null, 'estado' => 'excedido']);
});

test('inactive categories appear only when they have a budget or spending that year', function () {
    $vieja = CategoriaFinanciera::query()->create(['residencial_id' => $this->residencial->id, 'tipo' => 'egreso', 'nombre' => 'Fiestas', 'activa' => false]);
    CategoriaFinanciera::query()->create(['residencial_id' => $this->residencial->id, 'tipo' => 'egreso', 'nombre' => 'Sin uso', 'activa' => false]);
    ($this->egreso)('Fiestas', '700.00');

    $comparacion = ($this->comparacion)();

    expect(($this->fila)($comparacion, 'Fiestas'))->toMatchArray(['categoria_id' => $vieja->id, 'activa' => false, 'ejecutado' => '700.00'])
        ->and(($this->fila)($comparacion, 'Sin uso'))->toBeNull()
        ->and(collect($comparacion['categorias'])->pluck('categoria')->contains('Cuotas de mantenimiento'))->toBeFalse();
});

test('the current year is shown by default and the next year can be planned', function () {
    expect(($this->comparacion)()['anio'])->toBe(2026)
        ->and(($this->comparacion)(null, ['anio' => 2027])['anio'])->toBe(2027);

    $this->actingAs($this->tesorero)->get(route('finanzas.presupuesto.index', ['anio' => 2028]))->assertSessionHasErrors('anio');
    $this->actingAs($this->tesorero)->get(route('finanzas.presupuesto.index', ['anio' => 1999]))->assertSessionHasErrors('anio');
});

test('the treasurer registers, changes and removes budgets and each change is audited', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    $limpieza = ($this->categoria)('Limpieza y jardinería');
    $papeleria = ($this->categoria)('Papelería y administración');

    ($this->guardar)([
        ['categoria_id' => $vigilancia, 'monto' => '12000'],
        ['categoria_id' => $limpieza, 'monto' => '3000.5'],
        ['categoria_id' => $papeleria, 'monto' => ''],
    ])->assertSessionHasNoErrors()->assertRedirect(route('finanzas.presupuesto.index', ['anio' => 2026]));

    ($this->guardar)([
        ['categoria_id' => $vigilancia, 'monto' => '12500.00'],
        ['categoria_id' => $limpieza, 'monto' => ''],
        ['categoria_id' => $papeleria, 'monto' => ''],
    ])->assertSessionHasNoErrors();

    expect(Presupuesto::query()->pluck('monto', 'categoria_id')->all())->toBe([$vigilancia => '12500.00']);

    $acciones = Auditoria::query()->where('modulo', 'tesoreria')->where('entidad', 'presupuestos')->orderBy('id')->get();

    expect($acciones->pluck('accion')->all())->toBe(['presupuesto_registrado', 'presupuesto_registrado', 'presupuesto_modificado', 'presupuesto_eliminado'])
        ->and($acciones[0]->valor_nuevo)->toBe(['anio' => 2026, 'categoria_id' => $vigilancia, 'monto' => '12000.00'])
        ->and($acciones[1]->valor_nuevo['monto'])->toBe('3000.50')
        ->and($acciones[2]->valor_anterior['monto'])->toBe('12000.00')
        ->and($acciones[2]->valor_nuevo['monto'])->toBe('12500.00')
        ->and($acciones[3]->valor_anterior['monto'])->toBe('3000.50')
        ->and($acciones[3]->valor_nuevo)->toBeNull();
});

test('saving the same amounts again changes nothing', function () {
    ($this->presupuesto)('Vigilancia', '12000.00');

    ($this->guardar)([['categoria_id' => ($this->categoria)('Vigilancia'), 'monto' => '12000']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'No había cambios que guardar.');

    expect(Auditoria::query()->where('entidad', 'presupuestos')->count())->toBe(0);
});

test('a budget is per year', function () {
    $vigilancia = ($this->categoria)('Vigilancia');

    ($this->guardar)([['categoria_id' => $vigilancia, 'monto' => '12000']], 2026);
    ($this->guardar)([['categoria_id' => $vigilancia, 'monto' => '15000']], 2027);

    expect(($this->fila)(($this->comparacion)(null, ['anio' => 2026]), 'Vigilancia')['presupuestado'])->toBe('12000.00')
        ->and(($this->fila)(($this->comparacion)(null, ['anio' => 2027]), 'Vigilancia')['presupuestado'])->toBe('15000.00');
});

test('invalid budgets are rejected', function (string $caso, string $error) {
    $vigilancia = ($this->categoria)('Vigilancia');

    $montos = match ($caso) {
        'negative amount' => [['categoria_id' => $vigilancia, 'monto' => '-1']],
        'three decimals' => [['categoria_id' => $vigilancia, 'monto' => '10.555']],
        'another residential' => [['categoria_id' => ($this->categoria)('Vigilancia', $this->otro), 'monto' => '100']],
        'income category' => [['categoria_id' => ($this->categoria)('Donaciones', null, 'ingreso'), 'monto' => '100']],
        'repeated category' => [['categoria_id' => $vigilancia, 'monto' => '100'], ['categoria_id' => $vigilancia, 'monto' => '200']],
        'no amounts' => [],
    };

    ($this->guardar)($montos)->assertSessionHasErrors($error);

    expect(Presupuesto::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    ['negative amount', 'montos.0.monto'],
    ['three decimals', 'montos.0.monto'],
    ['another residential', 'montos.0.categoria_id'],
    ['income category', 'montos.0.categoria_id'],
    ['repeated category', 'montos.0.categoria_id'],
    ['no amounts', 'montos'],
]);

test('a budget cannot be saved for a year after the next one', function () {
    ($this->guardar)([['categoria_id' => ($this->categoria)('Vigilancia'), 'monto' => '100']], 2028)->assertSessionHasErrors('anio');
});

test('the president and the auditor see the comparison but cannot change the budget', function (string $rol) {
    $usuario = ($this->usuario)($rol);
    ($this->presupuesto)('Vigilancia', '1000.00');

    expect(($this->fila)(($this->comparacion)($usuario), 'Vigilancia')['presupuestado'])->toBe('1000.00');

    ($this->guardar)([['categoria_id' => ($this->categoria)('Vigilancia'), 'monto' => '5']], 2026, $usuario)->assertForbidden();

    expect(Presupuesto::query()->sole()->monto)->toBe('1000.00');
})->with(['presidente', 'fiscal']);

test('other roles cannot see or change the budget', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('finanzas.presupuesto.index'))->assertForbidden();
    ($this->guardar)([['categoria_id' => ($this->categoria)('Vigilancia'), 'monto' => '5']], 2026, $usuario)->assertForbidden();
})->with(['secretario', 'vocal', 'cobrador']);

test('each residential sees only its own budget and spending', function () {
    ($this->presupuesto)('Vigilancia', '1000.00');
    ($this->egreso)('Vigilancia', '400.00');
    ($this->presupuesto)('Vigilancia', '9000.00', 2026, $this->otro);
    ($this->egreso)('Vigilancia', '8500.00', '2026-05-01', 'vigente', $this->otro);

    $ajeno = ($this->usuario)('tesorero', $this->otro);

    expect(($this->fila)(($this->comparacion)(), 'Vigilancia'))->toMatchArray(['presupuestado' => '1000.00', 'ejecutado' => '400.00', 'estado' => 'normal'])
        ->and(($this->fila)(($this->comparacion)($ajeno), 'Vigilancia'))->toMatchArray(['presupuestado' => '9000.00', 'ejecutado' => '8500.00', 'estado' => 'alerta']);
});
