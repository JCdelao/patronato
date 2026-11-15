<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\SolicitudAnulacion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

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

    $this->cobrador = ($this->usuario)('cobrador', null, ['name' => 'Marta Cobradora']);
    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);
    $this->presidente = ($this->usuario)('presidente', null, ['name' => 'Paula Presidenta']);
    $this->fiscal = ($this->usuario)('fiscal', null, ['name' => 'Félix Fiscal']);

    $this->casa = Vivienda::query()->create([
        'residencial_id' => $this->residencial->id,
        'codigo' => 'A-01',
        'tipo' => 'casa',
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Dos meses de L 500.00; el pago cubre el primero
    $this->cargos = array_map(fn (string $periodo): int => (int) DB::table('cargos')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'concepto_id' => $concepto,
        'periodo' => $periodo,
        'monto' => '500.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]), ['2026-10-01', '2026-11-01']);

    $this->caja = Caja::query()->create([
        'residencial_id' => $this->residencial->id,
        'cobrador_id' => $this->cobrador->id,
        'estado' => Caja::ABIERTA,
        'abierta_en' => now(),
    ]);

    $recibo = 0;

    $this->cobro = function (array $datos = []) use (&$recibo): Pago {
        $recibo++;

        $pago = Pago::query()->create([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $this->casa->id,
            'forma_pago' => Pago::EFECTIVO,
            'estado' => Pago::CONFIRMADO,
            'origen' => 'sistema',
            'monto' => '500.00',
            'fecha_pago' => '2026-11-14',
            'numero_recibo' => (string) $recibo,
            'caja_id' => $this->caja->id,
            'registrado_por' => $this->cobrador->id,
            ...$datos,
        ]);

        DB::table('pago_aplicaciones')->insert([
            'residencial_id' => $this->residencial->id,
            'pago_id' => $pago->id,
            'cargo_id' => $this->cargos[0],
            'monto' => '500.00',
            'created_at' => now(),
        ]);

        return $pago;
    };

    $this->pago = ($this->cobro)();

    $this->motivo = 'Se registró en la vivienda equivocada.';

    $this->solicitar = fn (User $usuario, Pago $pago, string $motivo = 'Se registró en la vivienda equivocada.') => $this->actingAs($usuario)
        ->post(route('finanzas.pagos.anulacion', $pago), ['motivo' => $motivo]);

    $this->solicitud = fn (): SolicitudAnulacion => SolicitudAnulacion::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

    $this->saldo = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', '');
});

test('the treasurer requests the voiding and the payment stays confirmed until approved', function () {
    ($this->solicitar)($this->tesorero, $this->pago)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.pagos.show', $this->pago));

    $solicitud = ($this->solicitud)();

    expect($solicitud->estado)->toBe(SolicitudAnulacion::PENDIENTE)
        ->and($solicitud->solicitada_por)->toBe($this->tesorero->id)
        ->and($solicitud->motivo)->toBe($this->motivo)
        ->and($this->pago->fresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->saldo)())->toBe('500.00');

    $auditoria = Auditoria::query()->where('accion', 'anulacion_solicitada')->sole();

    expect($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->entidad_id)->toBe($this->pago->id)
        ->and($auditoria->motivo)->toBe($this->motivo);
});

test('a second directive approves and the payment is voided with who, when and why', function (string $aprobador) {
    ($this->solicitar)($this->tesorero, $this->pago);
    $solicitud = ($this->solicitud)();

    $this->travelTo('2026-11-14 20:00:00');

    $this->actingAs($this->{$aprobador})
        ->post(route('finanzas.anulaciones.aprobar', $solicitud))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.anulaciones.index'));

    $pago = $this->pago->fresh();

    expect($pago->estado)->toBe(Pago::ANULADO)
        ->and($pago->anulado_por)->toBe($this->tesorero->id)
        ->and($pago->anulacion_aprobada_por)->toBe($this->{$aprobador}->id)
        ->and($pago->anulado_en->toDateTimeString())->toBe('2026-11-14 20:00:00')
        ->and($pago->motivo_anulacion)->toBe($this->motivo)
        ->and($pago->numero_recibo)->toBe('1');

    $solicitud->refresh();

    expect($solicitud->estado)->toBe(SolicitudAnulacion::APROBADA)
        ->and($solicitud->resuelta_por)->toBe($this->{$aprobador}->id)
        ->and($solicitud->resuelta_en)->not->toBeNull();

    $auditoria = Auditoria::query()->where('accion', 'pago_anulado')->sole();

    expect($auditoria->user_id)->toBe($this->{$aprobador}->id)
        ->and($auditoria->valor_anterior)->toBe(['estado' => Pago::CONFIRMADO])
        ->and($auditoria->valor_nuevo['estado'])->toBe(Pago::ANULADO)
        ->and($auditoria->valor_nuevo['solicitada_por'])->toBe($this->tesorero->id);
})->with(['presidente', 'fiscal']);

test('the voiding reverts its effect on the balance without deleting any evidence', function () {
    expect(($this->saldo)())->toBe('500.00');

    ($this->solicitar)($this->tesorero, $this->pago);
    $this->actingAs($this->presidente)->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()));

    // Los dos meses vuelven a deberse; el pago y su aplicación se conservan
    expect(($this->saldo)())->toBe('1000.00')
        ->and(Pago::query()->withoutGlobalScopes()->whereKey($this->pago->id)->exists())->toBeTrue()
        ->and(DB::table('pago_aplicaciones')->where('pago_id', $this->pago->id)->count())->toBe(1);
});

test('the voided payment stays visible with the user, the date and the reason', function () {
    ($this->solicitar)($this->tesorero, $this->pago);
    $this->actingAs($this->presidente)->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()));

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.pagos.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/pagos')
            ->has('pagos.data', 1)
            ->where('pagos.data.0.estado', Pago::ANULADO)
            ->where('pagos.data.0.numero_recibo', '1'));

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.pagos.show', $this->pago))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/pago')
            ->where('pago.estado', Pago::ANULADO)
            ->where('pago.anulacion.solicitada_por', 'Tomás Tesorero')
            ->where('pago.anulacion.aprobada_por', 'Paula Presidenta')
            ->where('pago.anulacion.motivo', $this->motivo)
            ->whereNot('pago.anulacion.anulado_en', null)
            ->where('pago.saldo_vivienda', '1000.00')
            ->where('pago.puede_solicitar', false)
            ->has('pago.solicitudes', 1)
            ->where('pago.solicitudes.0.estado', SolicitudAnulacion::APROBADA));

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.comprobantes.show', $this->pago))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('comprobante.anulado', true)
            ->where('comprobante.motivo_anulacion', $this->motivo));
});

test('the receipt PDF of a voided payment is marked as voided', function () {
    $generador = new class implements GeneradorPdf
    {
        public string $html = '';

        public function generar(string $vista, array $datos): string
        {
            $this->html = view($vista, $datos)->render();

            return '%PDF-prueba';
        }
    };

    $this->app->instance(GeneradorPdf::class, $generador);

    $this->actingAs($this->tesorero)->get(route('finanzas.comprobantes.pdf', $this->pago))->assertOk();

    expect($generador->html)->not->toContain('ANULADO');

    ($this->solicitar)($this->tesorero, $this->pago);
    $this->actingAs($this->presidente)->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()));

    $this->actingAs($this->tesorero)->get(route('finanzas.comprobantes.pdf', $this->pago))->assertOk();

    expect($generador->html)->toContain('ANULADO')
        ->toContain($this->motivo);
});

test('the voided payment moves to the voided line of the collector report', function () {
    $consulta = ['desde' => '2026-11-01', 'hasta' => '2026-11-30'];

    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', $consulta))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reporte.total', '500.00')->where('reporte.anulados', 0));

    ($this->solicitar)($this->tesorero, $this->pago);
    $this->actingAs($this->presidente)->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()));

    $this->actingAs($this->fiscal)
        ->get(route('reportes.cobros.index', $consulta))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.total', '0.00')
            ->where('reporte.anulados', 1)
            ->where('reporte.monto_anulado', '500.00'));
});

test('nobody approves their own request', function () {
    // El tesorero también puede aprobar, pero no lo que él pidió
    DB::table('rol_permisos')->insert([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('tesorero'),
        'modulo' => 'cobros',
        'accion' => 'aprobar',
    ]);

    ($this->solicitar)($this->tesorero, $this->pago);

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()))
        ->assertSessionHasErrors(['solicitud' => 'No puedes resolver tu propia solicitud. Otro directivo debe hacerlo.']);

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.anulaciones.rechazar', ($this->solicitud)()), ['motivo' => 'Ya no es necesario anularlo.'])
        ->assertSessionHasErrors('solicitud');

    expect($this->pago->fresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->solicitud)()->estado)->toBe(SolicitudAnulacion::PENDIENTE);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.anulaciones.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('solicitudes.0.puede_resolver', false)
            ->where('solicitudes.0.motivo_no_resolver', 'No puedes resolver tu propia solicitud. Otro directivo debe hacerlo.'));
});

test('the director who registered the payment cannot approve its voiding', function () {
    $pago = ($this->cobro)(['registrado_por' => $this->presidente->id]);

    ($this->solicitar)($this->tesorero, $pago);

    $this->actingAs($this->presidente)
        ->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()))
        ->assertSessionHasErrors(['solicitud' => 'No puedes resolver la anulación de un cobro que registraste.']);

    expect($pago->fresh()->estado)->toBe(Pago::CONFIRMADO);

    // El fiscal sí puede
    $this->actingAs($this->fiscal)
        ->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()))
        ->assertSessionHasNoErrors();

    expect($pago->fresh()->estado)->toBe(Pago::ANULADO);
});

test('a rejected request keeps the payment confirmed and can be requested again', function () {
    ($this->solicitar)($this->tesorero, $this->pago);
    $solicitud = ($this->solicitud)();

    $this->actingAs($this->fiscal)
        ->post(route('finanzas.anulaciones.rechazar', $solicitud), ['motivo' => ''])
        ->assertSessionHasErrors(['motivo' => 'Escribe el motivo del rechazo.']);

    $this->actingAs($this->fiscal)
        ->post(route('finanzas.anulaciones.rechazar', $solicitud), ['motivo' => 'El recibo está bien registrado.'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.anulaciones.index'));

    $solicitud->refresh();

    expect($solicitud->estado)->toBe(SolicitudAnulacion::RECHAZADA)
        ->and($solicitud->resuelta_por)->toBe($this->fiscal->id)
        ->and($solicitud->motivo_rechazo)->toBe('El recibo está bien registrado.')
        ->and($this->pago->fresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->saldo)())->toBe('500.00')
        ->and(Auditoria::query()->where('accion', 'anulacion_rechazada')->where('user_id', $this->fiscal->id)->exists())->toBeTrue();

    // Una solicitud resuelta ya no se aprueba
    $this->actingAs($this->presidente)
        ->post(route('finanzas.anulaciones.aprobar', $solicitud))
        ->assertSessionHasErrors(['solicitud' => 'Esta solicitud ya fue resuelta.']);

    ($this->solicitar)($this->tesorero, $this->pago)->assertSessionHasNoErrors();

    expect(SolicitudAnulacion::query()->withoutGlobalScopes()->where('pago_id', $this->pago->id)->count())->toBe(2);
});

test('a payment has at most one pending request', function () {
    ($this->solicitar)($this->tesorero, $this->pago)->assertSessionHasNoErrors();

    ($this->solicitar)($this->tesorero, $this->pago)
        ->assertSessionHasErrors(['motivo' => 'Este pago ya tiene una solicitud de anulación pendiente.']);

    // La base también lo impide
    expect(fn () => DB::table('solicitudes_anulacion')->insert([
        'residencial_id' => $this->residencial->id,
        'pago_id' => $this->pago->id,
        'solicitada_por' => $this->tesorero->id,
        'motivo' => 'Duplicada a propósito.',
        'estado' => SolicitudAnulacion::PENDIENTE,
    ]))->toThrow(QueryException::class);
});

test('a voided payment cannot be voided again', function () {
    ($this->solicitar)($this->tesorero, $this->pago);
    $this->actingAs($this->presidente)->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()));

    ($this->solicitar)($this->tesorero, $this->pago)
        ->assertSessionHasErrors(['motivo' => 'Solo se puede anular un pago confirmado.']);
});

test('the reason is required and must explain the voiding', function (string $motivo, string $mensaje) {
    ($this->solicitar)($this->tesorero, $this->pago, $motivo)->assertSessionHasErrors(['motivo' => $mensaje]);

    expect(SolicitudAnulacion::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'empty' => ['', 'Escribe el motivo de la anulación.'],
    'too short' => ['Error', 'Explica el motivo con al menos 10 caracteres.'],
    'only spaces around a short word' => ['   Error    ', 'Explica el motivo con al menos 10 caracteres.'],
    'too long' => [str_repeat('a', 1001), 'El motivo admite hasta 1000 caracteres.'],
]);

test('a payment of a closed box can be voided and the closing keeps its total', function () {
    $this->caja->update([
        'estado' => Caja::CERRADA,
        'cerrada_en' => now(),
        'cerrada_por' => $this->tesorero->id,
        'total_registrado' => '500.00',
        'monto_entregado' => '500.00',
    ]);

    ($this->solicitar)($this->tesorero, $this->pago)->assertSessionHasNoErrors();

    $this->actingAs($this->presidente)
        ->post(route('finanzas.anulaciones.aprobar', ($this->solicitud)()))
        ->assertSessionHasNoErrors();

    expect($this->pago->fresh()->estado)->toBe(Pago::ANULADO)
        ->and($this->caja->fresh()->total_registrado)->toBe('500.00');
});

test('approvers see the pending requests and their count in the menu', function () {
    ($this->solicitar)($this->tesorero, $this->pago);

    $this->actingAs($this->presidente)
        ->get(route('finanzas.anulaciones.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/anulaciones')
            ->where('auth.anulacionesPendientes', 1)
            ->has('solicitudes', 1)
            ->where('solicitudes.0.motivo', $this->motivo)
            ->where('solicitudes.0.solicitada_por', 'Tomás Tesorero')
            ->where('solicitudes.0.pago.numero_recibo', '1')
            ->where('solicitudes.0.pago.monto', '500.00')
            ->where('solicitudes.0.puede_resolver', true));

    // Quien no aprueba no ve el número
    $this->actingAs($this->tesorero)
        ->get(route('finanzas.pagos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.anulacionesPendientes', 0));
});

test('only the allowed roles request, approve or list payments', function () {
    $this->post(route('finanzas.pagos.anulacion', $this->pago), ['motivo' => $this->motivo])->assertRedirect(route('login'));

    foreach (['cobrador', 'vocal', 'secretario', 'presidente', 'fiscal'] as $rol) {
        ($this->solicitar)(($this->usuario)($rol), $this->pago)->assertForbidden();
    }

    ($this->solicitar)($this->tesorero, $this->pago);
    $solicitud = ($this->solicitud)();

    foreach (['cobrador', 'vocal', 'secretario', 'tesorero'] as $rol) {
        $this->actingAs(($this->usuario)($rol))->post(route('finanzas.anulaciones.aprobar', $solicitud))->assertForbidden();
        $this->actingAs(($this->usuario)($rol))->get(route('finanzas.anulaciones.index'))->assertForbidden();
    }

    foreach (['cobrador', 'vocal', 'secretario'] as $rol) {
        $this->actingAs(($this->usuario)($rol))->get(route('finanzas.pagos.index'))->assertForbidden();
    }

    expect($this->pago->fresh()->estado)->toBe(Pago::CONFIRMADO);
});

test('existing residentials get the voiding permissions', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'cobros')->whereIn('accion', ['anular', 'aprobar'])->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_15_100002_asignar_anulacion_de_pagos.php');
    $migracion->up();
    $migracion->up();

    $permisos = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('residencial_id', $antiguo->id)
        ->where('modulo', 'cobros')
        ->whereIn('accion', ['anular', 'aprobar'])
        ->orderBy('roles.codigo')
        ->get(['roles.codigo', 'accion'])
        ->map(fn ($fila) => "{$fila->codigo}.{$fila->accion}")
        ->all();

    expect($permisos)->toBe(['fiscal.aprobar', 'presidente.aprobar', 'tesorero.anular']);
});

test('payments and requests of another residential are denied', function () {
    ($this->solicitar)($this->tesorero, $this->pago);
    $solicitud = ($this->solicitud)();

    $tesoreroAjeno = ($this->usuario)('tesorero', $this->otro);
    $presidenteAjeno = ($this->usuario)('presidente', $this->otro);

    $this->actingAs($tesoreroAjeno)
        ->get(route('finanzas.pagos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pagos.data', 0));

    $this->actingAs($tesoreroAjeno)->get(route('finanzas.pagos.show', $this->pago))->assertForbidden();
    ($this->solicitar)($tesoreroAjeno, $this->pago)->assertForbidden();

    $this->actingAs($presidenteAjeno)
        ->get(route('finanzas.anulaciones.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('solicitudes', 0)->where('auth.anulacionesPendientes', 0));

    $this->actingAs($presidenteAjeno)->post(route('finanzas.anulaciones.aprobar', $solicitud))->assertForbidden();

    expect($this->pago->fresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->count())->toBeGreaterThanOrEqual(3);
});

test('the payment list searches by receipt number or house code', function () {
    $otraCasa = Vivienda::query()->create([
        'residencial_id' => $this->residencial->id,
        'codigo' => 'B-07',
        'tipo' => 'casa',
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    ($this->cobro)(['vivienda_id' => $otraCasa->id]);

    $buscar = fn (string $texto) => $this->actingAs($this->tesorero)->get(route('finanzas.pagos.index', ['buscar' => $texto]));

    $buscar('b-07')->assertInertia(fn (AssertableInertia $page) => $page->has('pagos.data', 1)->where('pagos.data.0.vivienda', 'B-07'));
    $buscar('1')->assertInertia(fn (AssertableInertia $page) => $page->has('pagos.data', 1)->where('pagos.data.0.numero_recibo', '1'));
    $buscar('')->assertInertia(fn (AssertableInertia $page) => $page->has('pagos.data', 2));
});
