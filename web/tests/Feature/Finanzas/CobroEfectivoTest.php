<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Modules\Finanzas\Contratos\CargosNoIntegrados;
use Patronato\Modules\Finanzas\Contratos\CargosPendientes;
use Patronato\Modules\Finanzas\Models\Caja;
use Patronato\Modules\Finanzas\Models\Pago;
use Patronato\Modules\Finanzas\Models\PagoAplicacion;
use Patronato\Modules\Finanzas\Services\CobroService;
use Patronato\Modules\Finanzas\Services\SinCargosPendientes;
use Patronato\Modules\Identidad\Models\Auditoria;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Identidad\Services\PermisoService;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Plataforma\Models\Residencial;

/**
 * Meses pendientes simulados mientras no exista la generación de cargos
 */
class CargosSimulados implements CargosPendientes
{
    /** @var array<int, array<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>> */
    public array $porVivienda = [];

    public ?Throwable $fallarEnLlamada = null;

    public int $llamadas = 0;

    public function pendientesDeVivienda(int $viviendaId): Collection
    {
        $this->llamadas++;

        if ($this->fallarEnLlamada !== null && $this->llamadas > 1) {
            throw $this->fallarEnLlamada;
        }

        return collect($this->porVivienda[$viviendaId] ?? []);
    }
}

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->cobrador = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('cobrador'),
    ]);

    $this->vivienda = fn (Residencial $residencial, string $codigo): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => $codigo,
        'tipo' => 'casa',
        'calle_bloque' => 'Bloque A',
        'numero' => '1',
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)($this->residencial, 'A-01');

    $this->abrirCaja = fn (User $cobrador, ?int $residencialId = null): Caja => Caja::query()->create([
        'residencial_id' => $residencialId ?? $cobrador->residencial_id,
        'cobrador_id' => $cobrador->id,
        'estado' => 'abierta',
        'abierta_en' => now(),
    ]);

    $this->caja = ($this->abrirCaja)($this->cobrador);

    $this->cargos = new CargosSimulados;
    $this->app->instance(CargosPendientes::class, $this->cargos);

    // Cargo real en la tabla, para respetar las llaves foráneas de pago_aplicaciones
    $this->cargo = function (Vivienda $vivienda, string $periodo, string $monto = '500.00'): int {
        $concepto = DB::table('conceptos_cobro')->where('residencial_id', $vivienda->residencial_id)->value('id')
            ?? DB::table('conceptos_cobro')->insertGetId([
                'residencial_id' => $vivienda->residencial_id,
                'nombre' => 'Cuota mensual',
                'tipo' => 'recurrente',
                'es_cuota_unificada' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $id = DB::table('cargos')->insertGetId([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'concepto_id' => $concepto,
            'periodo' => $periodo,
            'monto' => $monto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargos->porVivienda[$vivienda->id][] = [
            'cargo_id' => $id,
            'periodo' => $periodo,
            'concepto' => 'Cuota mensual',
            'monto' => $monto,
            'pendiente' => $monto,
        ];

        return $id;
    };

    $this->permitirAbonos = fn (Residencial $residencial, bool $permite = true) => DB::table('configuracion_residencial')->insert([
        'residencial_id' => $residencial->id,
        'permite_abono_parcial' => $permite,
        'updated_at' => now(),
    ]);

    $this->enero = ($this->cargo)($this->casa, '2026-01-01');
});

test('only users with the register payments permission can use it', function () {
    $datos = ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]];

    $this->get(route('finanzas.cobros.create'))->assertRedirect(route('login'));

    $guardia = User::factory()->create(['residencial_id' => $this->residencial->id, 'rol_id' => ($this->rol)('guardia')]);
    $this->actingAs($guardia)->get(route('finanzas.cobros.create'))->assertForbidden();
    $this->actingAs($guardia)->post(route('finanzas.cobros.store'), $datos)->assertForbidden();

    $plataforma = User::factory()->create(['rol_id' => ($this->rol)(Rol::ADMIN_PLATAFORMA)]);
    $this->actingAs($plataforma)->post(route('finanzas.cobros.store'), $datos)->assertForbidden();

    $this->actingAs($this->cobrador)->get(route('finanzas.cobros.create'))->assertOk();

    expect(Pago::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('existing residentials need the permission enabled for collectors', function () {
    // Simula un residencial creado antes de que el cobrador tuviera este permiso
    $sinPredeterminados = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $sinPredeterminados->id)->where('modulo', 'cobros')->delete();

    $cobrador = User::factory()->create(['residencial_id' => $sinPredeterminados->id, 'rol_id' => ($this->rol)('cobrador')]);

    $this->actingAs($cobrador)->get(route('finanzas.cobros.create'))->assertForbidden();

    // El administrador técnico lo activa desde la pantalla de permisos
    $admin = User::factory()->create(['residencial_id' => $sinPredeterminados->id, 'rol_id' => ($this->rol)(Rol::ADMIN_TECNICO)]);
    app(PermisoService::class)->actualizar($admin, Rol::query()->where('codigo', 'cobrador')->sole(), ['cobros.registrar']);

    $this->actingAs($cobrador)->get(route('finanzas.cobros.create'))->assertOk();
});

test('the screen shows the open box, the search and the pending months', function () {
    $this->actingAs($this->cobrador)
        ->get(route('finanzas.cobros.create', ['buscar' => 'a-0']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/cobro-efectivo')
            ->where('cajaAbierta', true)
            ->where('viviendas.0.codigo', 'A-01')
            ->where('vivienda', null));

    $this->get(route('finanzas.cobros.create', ['vivienda' => $this->casa->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vivienda.codigo', 'A-01')
            ->where('pendientes.0.cargo_id', $this->enero)
            ->where('avisoIntegracion', null));
});

test('the system records the date, time and collector of the payment', function () {
    $this->travelTo('2026-11-08 10:15:00');

    $this->actingAs($this->cobrador)->post(route('finanzas.cobros.store'), [
        'vivienda_id' => $this->casa->id,
        'monto' => '500',
        'cargos' => [$this->enero],
        'fecha_pago' => '2020-01-01',
        'registrado_por' => 999,
        'estado' => 'anulado',
    ])->assertSessionHasNoErrors()->assertRedirect(route('finanzas.cobros.create'));

    $pago = Pago::query()->sole();

    expect($pago->fecha_pago->toDateString())->toBe('2026-11-08')
        ->and($pago->created_at->format('Y-m-d H:i'))->toBe('2026-11-08 10:15')
        ->and($pago->registrado_por)->toBe($this->cobrador->id)
        ->and($pago->estado)->toBe('confirmado');
});

test('receipts are consecutive and unique per residential', function () {
    $febrero = ($this->cargo)($this->casa, '2026-02-01');

    // Un recibo migrado no altera la numeración del sistema
    DB::table('pagos')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'forma_pago' => 'efectivo',
        'estado' => 'confirmado',
        'origen' => 'migracion',
        'monto' => '100.00',
        'fecha_pago' => '2023-05-01',
        'numero_recibo' => '900',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasNoErrors();

    $this->cargos->porVivienda[$this->casa->id] = [$this->cargos->porVivienda[$this->casa->id][1]];

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$febrero]])
        ->assertSessionHasNoErrors();

    $otroCobrador = User::factory()->create(['residencial_id' => $this->otro->id, 'rol_id' => ($this->rol)('cobrador')]);
    ($this->abrirCaja)($otroCobrador);
    $otraCasa = ($this->vivienda)($this->otro, 'B-01');
    $otroCargo = ($this->cargo)($otraCasa, '2026-01-01');

    $this->actingAs($otroCobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $otraCasa->id, 'monto' => '500', 'cargos' => [$otroCargo]])
        ->assertSessionHasNoErrors();

    $recibos = fn (Residencial $residencial) => Pago::query()->withoutGlobalScopes()
        ->where('residencial_id', $residencial->id)
        ->where('origen', 'sistema')
        ->orderBy('id')
        ->pluck('numero_recibo')
        ->all();

    expect($recibos($this->residencial))->toBe(['1', '2'])
        ->and($recibos($this->otro))->toBe(['1']);
});

test('a cash payment is confirmed, applied to the chosen months and kept in the open box', function () {
    $febrero = ($this->cargo)($this->casa, '2026-02-01');

    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '1000', 'cargos' => [$febrero, $this->enero]])
        ->assertSessionHasNoErrors();

    $pago = Pago::query()->sole();

    expect($pago->forma_pago)->toBe('efectivo')
        ->and($pago->estado)->toBe('confirmado')
        ->and($pago->monto)->toBe('1000.00')
        ->and($pago->caja_id)->toBe($this->caja->id)
        ->and(PagoAplicacion::query()->orderBy('id')->pluck('monto', 'cargo_id')->all())
        ->toBe([$this->enero => '500.00', $febrero => '500.00']);

    $saldo = DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->first();

    expect((float) $saldo->total_cargos)->toBe(1000.0)
        ->and((float) $saldo->saldo)->toBe(0.0);
});

test('a partial payment is accepted when the residential allows it', function () {
    ($this->permitirAbonos)($this->residencial);
    $febrero = ($this->cargo)($this->casa, '2026-02-01');

    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '700', 'cargos' => [$febrero, $this->enero]])
        ->assertSessionHasNoErrors();

    // Del mes más antiguo al más reciente; febrero queda en parte
    expect(PagoAplicacion::query()->orderBy('id')->pluck('monto', 'cargo_id')->all())
        ->toBe([$this->enero => '500.00', $febrero => '200.00']);

    $this->cargos->porVivienda[$this->casa->id] = [[...$this->cargos->porVivienda[$this->casa->id][1], 'pendiente' => '300.00']];

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '300', 'cargos' => [$febrero]])
        ->assertSessionHasNoErrors();

    expect((float) PagoAplicacion::query()->where('cargo_id', $febrero)->sum('monto'))->toBe(500.0);
});

test('a partial payment is rejected when the residential does not allow it', function () {
    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '200', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('monto');

    ($this->permitirAbonos)($this->residencial, false);

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '200', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('monto');

    expect(Pago::query()->count())->toBe(0);

    // Un intento rechazado no consume número de recibo
    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasNoErrors();

    expect(Pago::query()->sole()->numero_recibo)->toBe('1');
});

test('the amount and the months must match what is pending', function () {
    $otraCasa = ($this->vivienda)($this->residencial, 'A-02');
    $ajeno = ($this->cargo)($otraCasa, '2026-01-01');

    $this->actingAs($this->cobrador);

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '600', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('monto');

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => []])
        ->assertSessionHasErrors('cargos');

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$ajeno]])
        ->assertSessionHasErrors('cargos');

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '0', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('monto');

    expect(Pago::query()->count())->toBe(0);
});

test('a collector without an open box cannot register payments', function () {
    $this->caja->update(['estado' => 'cerrada']);

    $this->actingAs($this->cobrador)
        ->get(route('finanzas.cobros.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cajaAbierta', false));

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('caja');

    $this->caja->delete();

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('caja');

    expect(Pago::query()->count())->toBe(0);
});

test('housing units and boxes of another residential cannot be used', function () {
    $otraCasa = ($this->vivienda)($this->otro, 'B-01');
    $otroCargo = ($this->cargo)($otraCasa, '2026-01-01');

    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $otraCasa->id, 'monto' => '500', 'cargos' => [$otroCargo]])
        ->assertSessionHasErrors('vivienda_id');

    // Una caja abierta a su nombre en otro residencial no le sirve
    $this->caja->delete();
    ($this->abrirCaja)($this->cobrador, $this->otro->id);

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors('caja');

    expect(Pago::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('nothing is saved when applying the payment fails', function () {
    $this->cargos->fallarEnLlamada = new RuntimeException('Falla simulada');

    $this->actingAs($this->cobrador);

    expect(fn () => app(CobroService::class)->registrarEfectivo($this->cobrador, $this->casa->id, '500', [$this->enero]))
        ->toThrow(RuntimeException::class, 'Falla simulada');

    expect(Pago::query()->count())->toBe(0)
        ->and(PagoAplicacion::query()->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'pago_registrado')->count())->toBe(0);

    $this->cargos->fallarEnLlamada = null;

    expect(app(CobroService::class)->registrarEfectivo($this->cobrador, $this->casa->id, '500', [$this->enero])->numero_recibo)->toBe('1');
});

test('without charge generation the screen warns and payments are rejected', function () {
    $this->app->instance(CargosPendientes::class, new SinCargosPendientes);

    $this->actingAs($this->cobrador)
        ->get(route('finanzas.cobros.create', ['vivienda' => $this->casa->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pendientes', null)
            ->where('avisoIntegracion', CargosNoIntegrados::MENSAJE));

    $this->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasErrors(['cargos' => CargosNoIntegrados::MENSAJE]);

    expect(Pago::query()->count())->toBe(0);
});

test('each payment is recorded in the audit log', function () {
    $this->actingAs($this->cobrador)
        ->post(route('finanzas.cobros.store'), ['vivienda_id' => $this->casa->id, 'monto' => '500', 'cargos' => [$this->enero]])
        ->assertSessionHasNoErrors();

    $registro = Auditoria::query()->where('accion', 'pago_registrado')->sole();
    $pago = Pago::query()->sole();

    expect($registro->modulo)->toBe('cobros')
        ->and($registro->user_id)->toBe($this->cobrador->id)
        ->and($registro->entidad)->toBe('pagos')
        ->and($registro->entidad_id)->toBe($pago->id)
        ->and($registro->valor_nuevo['numero_recibo'])->toBe('1')
        ->and($registro->valor_nuevo['caja_id'])->toBe($this->caja->id);
});
