<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Negocio\Services\InformeFinancieroService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

// Cambio a la E08: agregar, quitar y reactivar categorías de egreso desde el presupuesto

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    // 9:00 del 20 de noviembre de 2026 en Honduras
    $this->travelTo('2026-11-20 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->tesorero = ($this->usuario)('tesorero');

    $this->categoria = fn (string $nombre, ?Residencial $residencial = null, string $tipo = CategoriaFinanciera::EGRESO): CategoriaFinanciera => CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('tipo', $tipo)
        ->where('nombre', $nombre)
        ->firstOrFail();

    $this->egreso = fn (CategoriaFinanciera $categoria, string $monto, string $fecha = '2026-06-10') => DB::table('egresos')->insert([
        'residencial_id' => $categoria->residencial_id,
        'categoria_id' => $categoria->id,
        'fecha' => $fecha,
        'concepto' => 'Gasto de prueba',
        'monto' => $monto,
        'numero_comprobante' => 'C-1',
        'estado' => 'vigente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->presupuesto = fn (CategoriaFinanciera $categoria, string $monto, int $anio = 2026) => DB::table('presupuestos')->insert([
        'residencial_id' => $categoria->residencial_id,
        'categoria_id' => $categoria->id,
        'anio' => $anio,
        'monto' => $monto,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->comparacion = function (array $consulta = [], ?User $usuario = null): array {
        $comparacion = null;

        $this->actingAs($usuario ?? $this->tesorero)
            ->get(route('finanzas.presupuesto.index', $consulta))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$comparacion) {
                $comparacion = $page->toArray()['props']['comparacion'];
            });

        return $comparacion;
    };

    $this->fila = fn (array $comparacion, string $nombre): ?array => collect($comparacion['categorias'])->firstWhere('categoria', $nombre);

    // Categorías que ofrece el formulario de un egreso nuevo
    $this->delFormularioDeEgreso = function (): array {
        $nombres = [];

        $this->actingAs($this->tesorero)
            ->get(route('finanzas.egresos.create'))
            ->assertInertia(function (AssertableInertia $page) use (&$nombres) {
                $nombres = array_column($page->toArray()['props']['categorias'], 'nombre');
            });

        return $nombres;
    };

    $this->quitar = fn (CategoriaFinanciera $categoria, ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->from(route('finanzas.presupuesto.index'))
        ->post(route('finanzas.categorias-egreso.quitar', $categoria));

    $this->reactivar = fn (CategoriaFinanciera $categoria, ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->from(route('finanzas.presupuesto.index'))
        ->post(route('finanzas.categorias-egreso.reactivar', $categoria));
});

test('a category added from the budget appears in the budget and in the expense form', function () {
    $this->actingAs($this->tesorero)
        ->from(route('finanzas.presupuesto.index'))
        ->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'Fiestas patronales'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.presupuesto.index'));

    expect(($this->fila)(($this->comparacion)(), 'Fiestas patronales'))->toMatchArray(['activa' => true, 'en_uso' => false, 'presupuestado' => null])
        ->and(($this->delFormularioDeEgreso)())->toContain('Fiestas patronales')
        ->and(Auditoria::query()->where('accion', 'categoria_creada')->count())->toBe(1);
});

test('the budget tells whether each category is already in use', function () {
    ($this->egreso)(($this->categoria)('Vigilancia'), '100.00', '2024-03-01');
    ($this->presupuesto)(($this->categoria)('Limpieza y jardinería'), '500.00', 2025);

    $comparacion = ($this->comparacion)();

    expect(($this->fila)($comparacion, 'Vigilancia')['en_uso'])->toBeTrue()
        ->and(($this->fila)($comparacion, 'Limpieza y jardinería')['en_uso'])->toBeTrue()
        ->and(($this->fila)($comparacion, 'Otros gastos')['en_uso'])->toBeFalse();
});

test('removing a category that was never used deletes it', function () {
    $categoria = ($this->categoria)('Otros gastos');

    ($this->quitar)($categoria)->assertSessionHasNoErrors()->assertRedirect(route('finanzas.presupuesto.index'));

    expect(CategoriaFinanciera::query()->find($categoria->id))->toBeNull()
        ->and(($this->fila)(($this->comparacion)(), 'Otros gastos'))->toBeNull()
        ->and(($this->delFormularioDeEgreso)())->not->toContain('Otros gastos');

    $auditoria = Auditoria::query()->where('accion', 'categoria_eliminada')->sole();

    expect($auditoria->modulo)->toBe('tesoreria')
        ->and($auditoria->entidad_id)->toBe($categoria->id)
        ->and($auditoria->valor_anterior)->toBe(['tipo' => 'egreso', 'nombre' => 'Otros gastos']);
});

test('removing a category with history deactivates it and keeps its expenses', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    ($this->egreso)($vigilancia, '800.00');

    ($this->quitar)($vigilancia)->assertSessionHasNoErrors();

    expect($vigilancia->fresh()->activa)->toBeFalse()
        ->and(DB::table('egresos')->where('categoria_id', $vigilancia->id)->count())->toBe(1)
        ->and(($this->delFormularioDeEgreso)())->not->toContain('Vigilancia');

    $auditoria = Auditoria::query()->where('accion', 'categoria_desactivada')->sole();

    expect($auditoria->valor_anterior)->toBe(['activa' => true])
        ->and($auditoria->valor_nuevo)->toBe(['activa' => false]);
});

test('a deactivated category cannot be used for a new expense', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    ($this->egreso)($vigilancia, '800.00');
    ($this->quitar)($vigilancia);

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.egresos.store'), [
            'fecha' => '2026-11-19',
            'concepto' => 'Pago de vigilancia',
            'categoria_id' => $vigilancia->id,
            'monto' => '100',
            'numero_comprobante' => 'F-1',
        ])
        ->assertSessionHasErrors('categoria_id');
});

test('a deactivated category keeps showing in a year where it has spending or a budget, so the totals still add up', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    $limpieza = ($this->categoria)('Limpieza y jardinería');
    ($this->egreso)($vigilancia, '800.00');
    ($this->presupuesto)($vigilancia, '1000.00');
    ($this->egreso)($limpieza, '250.50');
    ($this->presupuesto)(($this->categoria)('Otros gastos'), '300.00', 2025);

    ($this->quitar)($vigilancia);
    ($this->quitar)(($this->categoria)('Otros gastos'));

    $comparacion = ($this->comparacion)();

    expect(($this->fila)($comparacion, 'Vigilancia'))->toMatchArray(['activa' => false, 'presupuestado' => '1000.00', 'ejecutado' => '800.00'])
        // Sin gasto ni presupuesto en 2026, la desactivada no aparece en la tabla de este año
        ->and(($this->fila)($comparacion, 'Otros gastos'))->toBeNull()
        ->and(($this->fila)(($this->comparacion)(['anio' => 2025]), 'Otros gastos'))->toMatchArray(['activa' => false, 'presupuestado' => '300.00'])
        ->and(array_column($comparacion['inactivas'], 'nombre'))->toBe(['Otros gastos', 'Vigilancia'])
        ->and($comparacion['total_ejecutado'])->toBe('1050.50');

    // El total ejecutado del presupuesto coincide con el total de egresos del informe financiero
    $this->actingAs($this->tesorero);
    $informe = app(InformeFinancieroService::class)->generar($this->tesorero, ['tipo' => 'anual', 'anio' => 2026, 'mes' => 1]);

    expect($informe->totalEgresos)->toBe($comparacion['total_ejecutado']);
});

test('a deactivated category is reactivated from the budget', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    ($this->egreso)($vigilancia, '800.00');
    ($this->quitar)($vigilancia);

    ($this->reactivar)($vigilancia)->assertSessionHasNoErrors()->assertRedirect(route('finanzas.presupuesto.index'));

    expect($vigilancia->fresh()->activa)->toBeTrue()
        ->and(($this->delFormularioDeEgreso)())->toContain('Vigilancia')
        ->and(($this->comparacion)()['inactivas'])->toBe([]);

    $auditoria = Auditoria::query()->where('accion', 'categoria_reactivada')->sole();

    expect($auditoria->valor_nuevo)->toBe(['activa' => true]);
});

test('a deactivated category keeps its name, so it is reactivated instead of created again', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    ($this->egreso)($vigilancia, '800.00');
    ($this->quitar)($vigilancia);

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'vigilancia'])
        ->assertSessionHasErrors(['nombre' => 'Ya existe una categoría con ese nombre.']);
});

test('only an active category can be removed and only an inactive one reactivated', function () {
    $vigilancia = ($this->categoria)('Vigilancia');
    ($this->egreso)($vigilancia, '800.00');

    ($this->reactivar)($vigilancia)->assertSessionHasErrors(['categoria' => 'La categoría ya está activa; actualiza la página.']);

    ($this->quitar)($vigilancia);
    ($this->quitar)($vigilancia)->assertSessionHasErrors(['categoria' => 'La categoría ya no está activa; actualiza la página.']);

    expect(Auditoria::query()->where('accion', 'categoria_desactivada')->count())->toBe(1);
});

test('income categories cannot be removed or reactivated from here', function () {
    $cuotas = ($this->categoria)(CategoriaFinancieraService::CUOTAS, null, CategoriaFinanciera::INGRESO);

    ($this->quitar)($cuotas)->assertNotFound();
    ($this->reactivar)($cuotas)->assertNotFound();

    expect($cuotas->fresh()->activa)->toBeTrue();
});

test('only the treasurer adds, removes and reactivates categories', function (string $rol) {
    $usuario = ($this->usuario)($rol);
    $categoria = ($this->categoria)('Otros gastos');

    $this->actingAs($usuario)->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'Nueva'])->assertForbidden();
    ($this->quitar)($categoria, $usuario)->assertForbidden();
    ($this->reactivar)($categoria, $usuario)->assertForbidden();

    expect($categoria->fresh()->activa)->toBeTrue();
})->with(['presidente', 'fiscal', 'secretario']);

test('the categories of another residential are denied and audited', function () {
    $ajeno = ($this->usuario)('tesorero', $this->otro);
    $categoria = ($this->categoria)('Otros gastos');

    ($this->quitar)($categoria, $ajeno)->assertForbidden();
    ($this->reactivar)($categoria, $ajeno)->assertForbidden();

    expect($categoria->fresh())->not->toBeNull()
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(2);
});
