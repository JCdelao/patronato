<?php

/*
 * Pruebas del cobro en efectivo junto con la generación de cargos y el cierre de caja
 *
 * Usan los servicios reales: el tesorero define la cuota, se generan los cargos del mes,
 * el cobrador cobra y otro usuario cierra la caja.
 *
 * Comprobación manual pendiente en PostgreSQL (no es una prueba automatizada)
 *
 * SQLite no permite probar dos cobros al mismo tiempo, por eso se comprueba a mano:
 * 1. Preparar un residencial con una vivienda con dos meses pendientes y dos cobradores.
 * 2. Registrar al mismo tiempo un cobro de cada cobrador, desde dos sesiones.
 * 3. Resultado esperado: los dos pagos se guardan con recibos distintos y
 *    consecutivos, y no aparece ningún error del índice único de recibos.
 */

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\GeneracionCargosService;
use Patronato\Persistencia\Excepciones\CobroDeCajaCerrada;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    Carbon::setTestNow('2026-11-15 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $usuario = fn (string $codigo) => User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', $codigo)->value('id'),
    ]);

    $this->tesorero = $usuario('tesorero');
    $this->cobrador = $usuario('cobrador');

    // Cuota de L. 500 para las casas desde noviembre y cargos del mes generados
    $casa = TipoVivienda::query()->where('nombre', 'Casa')->sole();

    $this->actingAs($this->tesorero)
        ->put(route('finanzas.tipos-vivienda.update', $casa), ['nombre' => 'Casa', 'monto' => '500', 'vigente_desde' => '2026-11'])
        ->assertSessionHasNoErrors();

    $this->casa = Vivienda::query()->create([
        'residencial_id' => $this->residencial->id, 'codigo' => 'A-01', 'tipo_vivienda_id' => $casa->id, 'activa' => true,
    ]);

    app(GeneracionCargosService::class)->generarAhora($this->tesorero);

    $this->noviembre = Cargo::query()->where('vivienda_id', $this->casa->id)->sole();

    $this->permitirAbonos = fn () => DB::table('configuracion_residencial')->updateOrInsert(
        ['residencial_id' => $this->residencial->id],
        ['permite_abono_parcial' => true, 'updated_at' => now()],
    );

    $this->pendientes = fn () => $this->actingAs($this->cobrador)
        ->get(route('finanzas.cobros.create', ['vivienda' => $this->casa->id]));

    $this->cobrar = fn (string $monto) => $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => $monto, 'cargos' => [$this->noviembre->id]]);
});

afterEach(fn () => Carbon::setTestNow());

test('pending months come from the real charges of the housing unit', function () {
    ($this->pendientes)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pendientes', 1)
            ->where('pendientes.0.cargo_id', $this->noviembre->id)
            ->where('pendientes.0.periodo', '2026-11-01')
            ->where('pendientes.0.monto', '500.00')
            ->where('pendientes.0.pendiente', '500.00'));
});

test('after a partial payment the month shows only the remaining amount', function () {
    ($this->permitirAbonos)();

    ($this->cobrar)('200')->assertSessionHasNoErrors();

    ($this->pendientes)()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pendientes', 1)
            ->where('pendientes.0.pendiente', '300.00'));
});

test('a fully paid month no longer appears as pending', function () {
    ($this->cobrar)('500')->assertSessionHasNoErrors();

    ($this->pendientes)()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pendientes', []));

    // Ya no se puede volver a cobrar
    ($this->cobrar)('500')->assertSessionHasErrors('cargos');

    expect(Pago::query()->count())->toBe(1);
});

test('a payment goes into the box of the day of the collector', function () {
    ($this->cobrar)('500')->assertSessionHasNoErrors();

    $caja = Caja::query()->sole();

    expect($caja->cobrador_id)->toBe($this->cobrador->id)
        ->and($caja->fecha->toDateString())->toBe('2026-11-15')
        ->and(Pago::query()->sole()->caja_id)->toBe($caja->id);
});

test('a payment after the box is closed opens a new box', function () {
    ($this->permitirAbonos)();

    ($this->cobrar)('200')->assertSessionHasNoErrors();

    $cerrada = Caja::query()->sole();

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.cajas.cerrar', $cerrada), ['monto_entregado' => '200'])
        ->assertSessionHasNoErrors();

    ($this->cobrar)('300')->assertSessionHasNoErrors();

    $nueva = Caja::query()->where('estado', Caja::ABIERTA)->sole();

    expect($cerrada->fresh()->estado)->toBe(Caja::CERRADA)
        ->and($cerrada->fresh()->total_registrado)->toBe('200.00')
        ->and($nueva->id)->not->toBe($cerrada->id)
        ->and(Pago::query()->where('caja_id', $cerrada->id)->count())->toBe(1)
        ->and(Pago::query()->where('caja_id', $nueva->id)->count())->toBe(1);
});

test('payments of a closed box keep their box and cannot be edited', function () {
    ($this->cobrar)('500')->assertSessionHasNoErrors();

    $caja = Caja::query()->sole();
    $pago = Pago::query()->sole();

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.cajas.cerrar', $caja), ['monto_entregado' => '500'])
        ->assertSessionHasNoErrors();

    expect(fn () => $pago->fresh()->update(['monto' => '1.00']))->toThrow(CobroDeCajaCerrada::class)
        ->and(fn () => $pago->fresh()->delete())->toThrow(CobroDeCajaCerrada::class)
        ->and($pago->fresh()->caja_id)->toBe($caja->id)
        ->and($pago->fresh()->monto)->toBe('500.00');
});
