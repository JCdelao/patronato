<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\MoraService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    Carbon::setTestNow('2026-11-15 12:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->crear = fn (string $codigo, ?Residencial $residencial = null) => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => Rol::query()->where('codigo', $codigo)->value('id'),
    ]);

    $this->presidente = ($this->crear)(Rol::PRESIDENTE);
    $this->actingAs($this->presidente);

    $this->vivienda = fn (string $codigo, ?Residencial $residencial = null, bool $activa = true) => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)('A-01');

    // Cargo de la cuota de un mes
    $this->cargo = function (Vivienda $vivienda, string $periodo, string $monto = '500.00'): int {
        $concepto = DB::table('conceptos_cobro')->where('residencial_id', $vivienda->residencial_id)->where('nombre', 'Cuota')->value('id')
            ?? DB::table('conceptos_cobro')->insertGetId([
                'residencial_id' => $vivienda->residencial_id, 'nombre' => 'Cuota', 'tipo' => 'recurrente',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        return DB::table('cargos')->insertGetId([
            'residencial_id' => $vivienda->residencial_id, 'vivienda_id' => $vivienda->id, 'concepto_id' => $concepto,
            'periodo' => $periodo, 'monto' => $monto, 'origen' => 'sistema', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->pagar = function (int $cargoId, string $monto): Pago {
        $pago = Pago::query()->forceCreate([
            'residencial_id' => $this->residencial->id, 'vivienda_id' => $this->casa->id, 'forma_pago' => 'efectivo',
            'estado' => 'confirmado', 'monto' => $monto, 'fecha_pago' => '2026-11-15',
        ]);

        PagoAplicacion::query()->create(['residencial_id' => $this->residencial->id, 'pago_id' => $pago->id, 'cargo_id' => $cargoId, 'monto' => $monto]);

        return $pago;
    };

    $this->regla = fn (int $dias, int $meses) => $this->put(route('finanzas.mora.update'), ['dias_gracia' => $dias, 'meses_tolerancia' => $meses]);

    $this->estado = fn (?Vivienda $vivienda = null) => app(MoraService::class)->estadoDe($this->residencial->id, ($vivienda ?? $this->casa)->id);
});

afterEach(fn () => Carbon::setTestNow());

test('the rule starts at zero days and zero months', function () {
    $this->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mora', ['dias_gracia' => 0, 'meses_tolerancia' => 0])
            ->where('puedeEditarMora', true)
            ->where('puedeEditar', false));

    // Sin días de gracia, el mes en curso sin pagar ya es mora
    ($this->cargo)($this->casa, '2026-11-01');

    expect(($this->estado)())->toBe(['estado' => 'en_mora', 'meses_vencidos' => 1, 'vencido' => '500.00']);
});

test('the president and the treasurer define the rule and it is audited', function () {
    ($this->regla)(10, 1)->assertSessionHasNoErrors();

    $registro = Auditoria::query()->where('accion', 'regla_mora_cambiada')->sole();

    expect($registro->user_id)->toBe($this->presidente->id)
        ->and($registro->valor_anterior)->toBe(['dias_gracia' => 0, 'meses_tolerancia' => 0])
        ->and($registro->valor_nuevo)->toBe(['dias_gracia' => 10, 'meses_tolerancia' => 1]);

    $this->actingAs(($this->crear)('tesorero'));
    ($this->regla)(5, 2)->assertSessionHasNoErrors();

    // Guardar los mismos valores no deja otro registro
    ($this->regla)(5, 2);

    expect(app(MoraService::class)->regla($this->residencial->id))->toBe(['dias_gracia' => 5, 'meses_tolerancia' => 2])
        ->and(Auditoria::query()->where('accion', 'regla_mora_cambiada')->count())->toBe(2);

    ($this->regla)(29, 0)->assertSessionHasErrors('dias_gracia');
    ($this->regla)(-1, 0)->assertSessionHasErrors('dias_gracia');
    ($this->regla)(0, 13)->assertSessionHasErrors('meses_tolerancia');

    foreach ([Rol::SECRETARIO, 'fiscal', 'vocal', 'cobrador'] as $rol) {
        $this->actingAs(($this->crear)($rol));
        ($this->regla)(1, 1)->assertForbidden();
    }
});

test('a house is in arrears when it owes more overdue months than the tolerance', function () {
    ($this->regla)(10, 1);

    ($this->cargo)($this->casa, '2026-09-01');
    ($this->cargo)($this->casa, '2026-10-01');
    ($this->cargo)($this->casa, '2026-11-01');

    // El 15 de noviembre ya vencieron septiembre, octubre y noviembre (el día 11)
    expect(($this->estado)())->toBe(['estado' => 'en_mora', 'meses_vencidos' => 3, 'vencido' => '1500.00']);

    // El 5 de noviembre, noviembre todavía está en sus días de gracia
    Carbon::setTestNow('2026-11-05 12:00:00');

    expect(($this->estado)())->toBe(['estado' => 'en_mora', 'meses_vencidos' => 2, 'vencido' => '1000.00']);

    // Debe solo un mes vencido: está dentro de la tolerancia
    ($this->regla)(10, 2);

    expect(($this->estado)()['estado'])->toBe('al_dia');
});

test('the state is recalculated when a payment is confirmed or annulled', function () {
    $septiembre = ($this->cargo)($this->casa, '2026-09-01');
    $octubre = ($this->cargo)($this->casa, '2026-10-01');
    ($this->regla)(10, 0);

    Carbon::setTestNow('2026-11-05 12:00:00');

    expect(($this->estado)()['estado'])->toBe('en_mora');

    // Un abono no saca el mes de la mora; pagar todo lo vencido sí
    ($this->pagar)($septiembre, '200');

    expect(($this->estado)())->toBe(['estado' => 'en_mora', 'meses_vencidos' => 2, 'vencido' => '800.00']);

    ($this->pagar)($septiembre, '300');
    $pago = ($this->pagar)($octubre, '500');

    expect(($this->estado)())->toBe(['estado' => 'al_dia', 'meses_vencidos' => 0, 'vencido' => '0.00']);

    // Al anular el pago, octubre vuelve a deberse
    $pago->forceFill(['estado' => 'anulado'])->save();

    expect(($this->estado)())->toBe(['estado' => 'en_mora', 'meses_vencidos' => 1, 'vencido' => '500.00']);
});

test('the state appears in the list of houses and in the cash payment', function () {
    ($this->cargo)($this->casa, '2026-11-01');
    ($this->vivienda)('A-02');
    ($this->vivienda)('A-03', activa: false);

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.codigo', 'A-01')
            ->where('viviendas.data.0.mora', ['estado' => 'en_mora', 'meses_vencidos' => 1, 'vencido' => '500.00'])
            ->where('viviendas.data.1.mora.estado', 'al_dia'));

    $this->get(route('padron.viviendas.index', ['estado' => 'inactivas']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas.data.0.mora', null));

    $this->actingAs(($this->crear)('cobrador'));

    $this->get(route('finanzas.cobros.create', ['buscar' => 'A-0']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.0.mora.estado', 'en_mora')
            ->where('viviendas.1.mora.estado', 'al_dia'));

    $this->get(route('finanzas.cobros.create', ['vivienda' => $this->casa->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('vivienda.mora.meses_vencidos', 1));
});

test('each residencial uses its own rule', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro']);
    $ajena = ($this->vivienda)('X-01', $otro);
    ($this->cargo)($ajena, '2026-11-01');

    // La regla de Las Palmas no cambia la del otro residencial
    ($this->regla)(20, 0);

    $this->actingAs(($this->crear)('tesorero', $otro));

    expect(app(MoraService::class)->regla($otro->id))->toBe(['dias_gracia' => 0, 'meses_tolerancia' => 0])
        ->and(app(MoraService::class)->estadoDe($otro->id, $ajena->id)['estado'])->toBe('en_mora');
});
