<?php

use Database\Seeders\DemostracionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\User;

// Datos de demostración para las pruebas manuales; no forman parte de ninguna HU

beforeEach(function () {
    // El comprobante de la transferencia no se escribe en el disco real
    Storage::fake('local');

    $this->seed(DemostracionSeeder::class);

    $this->cuenta = fn (string $correo): User => User::query()->where('email', $correo)->firstOrFail();
});

test('the demo data creates both residentials with an account for each role', function () {
    expect(Residencial::query()->pluck('nombre')->all())
        ->toEqualCanonicalizing([DemostracionSeeder::RESIDENCIAL, DemostracionSeeder::OTRO_RESIDENCIAL]);

    $correos = User::query()->pluck('email');

    expect($correos)->toContain(DemostracionSeeder::ADMIN_PLATAFORMA, 'tesorero@laspalmas.demo', 'fiscal@laspalmas.demo', 'cobrador@laspalmas.demo', 'cobrador@lospinos.demo')
        ->and(User::query()->where('debe_cambiar_password', true)->count())->toBe(0)
        ->and(Hash::check(DemostracionSeeder::PASSWORD, ($this->cuenta)('fiscal@laspalmas.demo')->password))->toBeTrue()
        ->and(DB::table('rol_permisos')->distinct()->count('residencial_id'))->toBe(2);
});

test('running the demo data twice does not duplicate anything', function () {
    $usuarios = User::query()->count();
    $pagos = DB::table('pagos')->count();

    $this->seed(DemostracionSeeder::class);

    expect(User::query()->count())->toBe($usuarios)
        ->and(DB::table('pagos')->count())->toBe($pagos);
});

test('the payments cover their months and the closed box has a justified shortfall', function () {
    $residencial = Residencial::query()->where('nombre', DemostracionSeeder::RESIDENCIAL)->firstOrFail();

    $cerrada = Caja::query()->withoutGlobalScopes()->where('estado', Caja::CERRADA)->sole();

    expect($cerrada->residencial_id)->toBe($residencial->id)
        ->and($cerrada->total_registrado)->toBe('2600.00')
        ->and($cerrada->monto_entregado)->toBe('2550.00')
        ->and($cerrada->justificacion)->not->toBeNull();

    // Cada pago confirmado cubre exactamente sus meses
    $sinCuadrar = DB::table('pagos')
        ->where('estado', 'confirmado')
        ->get()
        ->filter(fn ($pago) => (float) DB::table('pago_aplicaciones')->where('pago_id', $pago->id)->sum('monto') !== (float) $pago->monto);

    expect($sinCuadrar)->toBeEmpty()
        // La transferencia pendiente todavía no tiene recibo
        ->and(DB::table('pagos')->where('residencial_id', $residencial->id)->whereNotNull('numero_recibo')->orderBy('id')->pluck('numero_recibo')->all())
        ->toBe(['1', '2', '3', '4', '5', '6', '7', '8', '9']);
});

test('the fiscal sees the demo payments in the collector report', function () {
    $this->actingAs(($this->cuenta)('fiscal@laspalmas.demo'))
        ->get(route('reportes.cobros.index', ['desde' => '2026-10-01', 'hasta' => '2026-11-30', 'agrupar' => 'mes']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reportes/cobros')
            ->where('reporte.total_cobros', 8)
            ->where('reporte.total', '7800.00')
            ->where('reporte.anulados', 1)
            ->count('cobradores', 2));
});

test('the treasurer can close the open boxes of the demo collectors', function () {
    $this->actingAs(($this->cuenta)('tesorero@laspalmas.demo'))
        ->get(route('finanzas.cajas.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('finanzas/cajas'));

    expect(Caja::query()->withoutGlobalScopes()->where('estado', Caja::ABIERTA)->count())->toBe(3);
});

test('a voided demo payment is approved by someone other than who requested it', function () {
    // En PostgreSQL lo exige pagos_aprobador_distinto_check; SQLite no tiene esa restricción
    $anulados = DB::table('pagos')->where('estado', 'anulado')->get();

    expect($anulados)->toHaveCount(1)
        ->and($anulados->every(fn ($pago) => $pago->anulado_por !== null
            && $pago->anulacion_aprobada_por !== null
            && $pago->anulado_por !== $pago->anulacion_aprobada_por))->toBeTrue();
});

test('the demo voiding follows the HU-23 rules and leaves one request to approve', function () {
    $tesorero = ($this->cuenta)('tesorero@laspalmas.demo');
    $presidente = ($this->cuenta)('presidente@laspalmas.demo');

    $anulado = DB::table('pagos')->where('estado', 'anulado')->sole();

    expect($anulado->anulado_por)->toBe($tesorero->id)
        ->and($anulado->anulacion_aprobada_por)->toBe($presidente->id)
        ->and(DB::table('solicitudes_anulacion')->where('pago_id', $anulado->id)->value('estado'))->toBe('aprobada')
        ->and(DB::table('pago_aplicaciones')->where('pago_id', $anulado->id)->count())->toBe(2);

    $this->actingAs($presidente)
        ->get(route('finanzas.anulaciones.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('solicitudes', 1)
            ->where('solicitudes.0.solicitada_por', 'Teresa Flores')
            ->where('solicitudes.0.puede_resolver', true)
            ->where('auth.anulacionesPendientes', 1));
});

test('the demo leaves a transfer pending verification with its receipt', function () {
    $tesorero = ($this->cuenta)('tesorero@laspalmas.demo');

    $transferencia = DB::table('pagos')->where('forma_pago', 'transferencia')->where('origen', 'sistema')->sole();
    $saldo = DB::table('v_saldos_vivienda')->where('vivienda_id', $transferencia->vivienda_id)->value('saldo');

    expect($transferencia->estado)->toBe('pendiente_verificacion')
        ->and($transferencia->numero_recibo)->toBeNull()
        ->and($transferencia->registrado_por)->toBe($tesorero->id)
        ->and(DB::table('pago_aplicaciones')->where('pago_id', $transferencia->id)->exists())->toBeFalse()
        // B-03 no tiene pagos: el saldo sigue siendo el de sus tres meses
        ->and(number_format((float) $saldo, 2, '.', ''))->toBe('1950.00');

    Storage::disk('local')->assertExists($transferencia->comprobante_path);

    $this->actingAs($tesorero)
        ->get(route('finanzas.transferencias.comprobante', $transferencia->id))
        ->assertOk();
});

test('the demo resident sees the transfer reported from the portal', function () {
    $vecino = ($this->cuenta)('residente@laspalmas.demo');

    $reporte = DB::table('pagos')->where('origen', 'portal')->sole();
    $saldo = DB::table('v_saldos_vivienda')->where('vivienda_id', $reporte->vivienda_id)->value('saldo');

    expect($reporte->estado)->toBe('reportado_por_vecino')
        ->and($reporte->registrado_por)->toBe($vecino->id)
        ->and($reporte->cuenta_receptora)->toBeNull()
        ->and(DB::table('residentes')->where('user_id', $vecino->id)->value('nombres'))->toBe('Luis')
        // A-04 pagó septiembre: siguen pendientes octubre y noviembre
        ->and(number_format((float) $saldo, 2, '.', ''))->toBe('1300.00');

    Storage::disk('local')->assertExists($reporte->comprobante_path);

    $this->actingAs($vecino)
        ->get(route('portal.transferencias.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/transferencias')
            ->has('reportes', 1)
            ->where('reportes.0.estado', 'reportado_por_vecino')
            ->where('reportes.0.vivienda', 'A-04'));
});
