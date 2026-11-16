<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Notificaciones\TransferenciaReportada;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

// HU-25 · Reportar una transferencia desde el portal

beforeEach(function () {
    Storage::fake(TransferenciaService::DISCO);
    Notification::fake();

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
        ...$datos,
    ]);

    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);

    $this->vivienda = fn (Residencial $residencial, string $codigo, bool $activa = true): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)($this->residencial, 'A-01');

    // Cuenta de residente vinculada a un residente que vive en la vivienda
    $this->vecino = function (?Vivienda $vivienda = null, array $vinculo = [], array $residente = [], ?Residencial $residencial = null): User {
        $residencial ??= $this->residencial;
        $cuenta = ($this->usuario)(Rol::RESIDENTE, $residencial, ['name' => 'Rosa Vecina']);

        $registro = Residente::query()->create([
            'residencial_id' => $residencial->id,
            'user_id' => $cuenta->id,
            'nombres' => 'Rosa',
            'apellidos' => 'Vecina',
            'activo' => true,
            ...$residente,
        ]);

        ViviendaResidente::query()->create([
            'residencial_id' => $residencial->id,
            'vivienda_id' => ($vivienda ?? $this->casa)->id,
            'residente_id' => $registro->id,
            'tipo' => 'inquilino',
            'es_responsable_pago' => false,
            'fecha_inicio' => '2026-01-01',
            ...$vinculo,
        ]);

        return $cuenta;
    };

    $this->rosa = ($this->vecino)();

    // Un mes de L 500.00 pendiente
    $concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cargos')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'concepto_id' => $concepto,
        'periodo' => '2026-11-01',
        'monto' => '500.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->pdf = fn (string $nombre = 'comprobante.pdf'): UploadedFile => UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n%comprobante de prueba\n");

    $this->datos = fn (array $cambios = []): array => [
        'vivienda_id' => $this->casa->id,
        'monto' => '500',
        'fecha_pago' => TransferenciaService::hoy(),
        'banco' => 'Banco Atlántida',
        'numero_referencia' => 'ATL-2001',
        'cuenta_receptora' => '',
        'comprobante' => ($this->pdf)(),
        ...$cambios,
    ];

    $this->reportar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->rosa)
        ->post(route('portal.transferencias.store'), ($this->datos)($cambios));

    $this->saldo = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', '');

    $this->porVerificar = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('total_por_verificar'), 2, '.', '');

    $this->transferencias = fn () => Pago::query()->withoutGlobalScopes()->where('forma_pago', Pago::TRANSFERENCIA);
});

// Criterio 1: queda reportado por el vecino y no reduce el saldo

test('a report stays as reported by the neighbor and does not reduce the balance', function () {
    expect(($this->saldo)())->toBe('500.00');

    ($this->reportar)()
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('portal.transferencias.index'));

    $pago = ($this->transferencias)()->sole();

    expect($pago->estado)->toBe(Pago::REPORTADO_POR_VECINO)
        ->and($pago->origen)->toBe(Pago::PORTAL)
        ->and($pago->residencial_id)->toBe($this->residencial->id)
        ->and($pago->vivienda_id)->toBe($this->casa->id)
        ->and($pago->monto)->toBe('500.00')
        ->and($pago->banco)->toBe('Banco Atlántida')
        ->and($pago->numero_referencia)->toBe('ATL-2001')
        ->and($pago->cuenta_receptora)->toBeNull()
        ->and($pago->registrado_por)->toBe($this->rosa->id)
        ->and($pago->numero_recibo)->toBeNull()
        ->and($pago->caja_id)->toBeNull()
        ->and($pago->aplicaciones()->exists())->toBeFalse()
        ->and(($this->saldo)())->toBe('500.00')
        ->and(($this->porVerificar)())->toBe('500.00');

    Storage::disk(TransferenciaService::DISCO)->assertExists($pago->comprobante_path);

    expect($pago->comprobante_path)->toStartWith("transferencias/{$this->residencial->id}/");
});

test('the report is audited in the portal module', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    $registro = Auditoria::query()->withoutGlobalScopes()->where('accion', 'transferencia_reportada')->sole();

    expect($registro->modulo)->toBe('portal')
        ->and($registro->user_id)->toBe($this->rosa->id)
        ->and($registro->entidad_id)->toBe($pago->id)
        ->and($registro->valor_nuevo)->toMatchArray([
            'vivienda_id' => $this->casa->id,
            'monto' => '500.00',
            'banco' => 'Banco Atlántida',
            'numero_referencia' => 'ATL-2001',
            'cuenta_receptora' => null,
            'estado' => Pago::REPORTADO_POR_VECINO,
        ]);
});

test('a report is not a cash collection nor enters a box', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    $hoy = TransferenciaService::hoy();

    expect(Caja::query()->withoutGlobalScopes()->exists())->toBeFalse();

    $this->actingAs($this->tesorero)
        ->get(route('reportes.cobros.index', ['desde' => $hoy, 'hasta' => $hoy]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.total_cobros', 0)
            ->where('reporte.total', '0.00'));

    $this->get(route('finanzas.cajas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('abiertas', 0));
});

test('the treasury sees the report as reported by the neighbor', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.pagos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagos.data.0.estado', Pago::REPORTADO_POR_VECINO)
            ->where('pagos.data.0.numero_referencia', 'ATL-2001'));

    $this->get(route('finanzas.pagos.show', $pago))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/pago')
            ->where('pago.estado', Pago::REPORTADO_POR_VECINO)
            ->where('pago.origen', Pago::PORTAL)
            ->where('pago.cobrador', 'Rosa Vecina')
            ->where('pago.saldo_vivienda', '500.00')
            ->where('pago.transferencia.cuenta_receptora', null)
            ->where('pago.transferencia.tiene_comprobante', true)
            ->where('pago.puede_solicitar', false));

    // El tesorero abre el comprobante por la ruta de la HU-24
    $this->get(route('finanzas.transferencias.comprobante', $pago))->assertOk();
});

// Criterio 2: el residente ve el estado de su reporte

test('the resident sees the status of their reports', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    $this->get(route('portal.transferencias.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/transferencias')
            ->has('reportes', 1)
            ->where('reportes.0.vivienda', 'A-01')
            ->where('reportes.0.monto', '500.00')
            ->where('reportes.0.banco', 'Banco Atlántida')
            ->where('reportes.0.numero_referencia', 'ATL-2001')
            ->where('reportes.0.estado', Pago::REPORTADO_POR_VECINO)
            ->where('reportes.0.tiene_comprobante', true));

    // Cuando tesorería cambie el estado (HU-26), el residente lo ve
    ($this->transferencias)()->update(['estado' => Pago::RECHAZADO, 'motivo_rechazo' => 'No ingresó a la cuenta.']);

    $this->get(route('portal.transferencias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reportes.0.estado', Pago::RECHAZADO));
});

test('the resident only sees the reports they made', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    // Otro residente de la misma vivienda
    $companero = ($this->vecino)(null, [], ['nombres' => 'Ramón']);
    ($this->reportar)(['numero_referencia' => 'ATL-2002'], $companero)->assertSessionHasNoErrors();

    // El tesorero registra otra de la misma vivienda (HU-24)
    $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), ($this->datos)([
        'numero_referencia' => 'ATL-2003',
        'cuenta_receptora' => '730-123456-7',
    ]))->assertSessionHasNoErrors();

    // Un residente de otro residencial
    $ajeno = ($this->vecino)(($this->vivienda)($this->otro, 'Z-01'), [], [], $this->otro);
    ($this->reportar)(['vivienda_id' => Vivienda::query()->withoutGlobalScopes()->where('codigo', 'Z-01')->value('id'), 'numero_referencia' => 'ATL-2004'], $ajeno)
        ->assertSessionHasNoErrors();

    expect(($this->transferencias)()->count())->toBe(4);

    $this->actingAs($this->rosa)
        ->get(route('portal.transferencias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reportes', 1)
            ->where('reportes.0.numero_referencia', 'ATL-2001'));
});

test('the resident opens only the receipts of their own reports', function () {
    ($this->reportar)(['comprobante' => ($this->pdf)('mi-comprobante.pdf')])->assertSessionHasNoErrors();
    $propio = ($this->transferencias)()->sole();

    $respuesta = $this->get(route('portal.transferencias.comprobante', $propio))->assertOk();

    expect($respuesta->streamedContent())->toContain('%PDF-1.4')
        ->and($respuesta->headers->get('content-type'))->toContain('application/pdf');

    // El comprobante de otro residente de la misma vivienda
    $companero = ($this->vecino)();
    ($this->reportar)(['numero_referencia' => 'ATL-2002'], $companero)->assertSessionHasNoErrors();
    $ajeno = ($this->transferencias)()->where('numero_referencia', 'ATL-2002')->sole();

    $this->actingAs($this->rosa)->get(route('portal.transferencias.comprobante', $ajeno))->assertForbidden();

    // Uno que registró el tesorero para su vivienda
    $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), ($this->datos)([
        'numero_referencia' => 'ATL-2003',
        'cuenta_receptora' => '730-123456-7',
    ]))->assertSessionHasNoErrors();
    $delTesorero = ($this->transferencias)()->where('numero_referencia', 'ATL-2003')->sole();

    $this->actingAs($this->rosa)->get(route('portal.transferencias.comprobante', $delTesorero))->assertForbidden();

    // El residente no usa la ruta de tesorería
    $this->get(route('finanzas.transferencias.comprobante', $propio))->assertForbidden();

    // Uno de otro residencial: se deniega y queda en la bitácora
    $otroVecino = ($this->vecino)(($this->vivienda)($this->otro, 'Z-01'), [], [], $this->otro);
    $this->actingAs($otroVecino)->get(route('portal.transferencias.comprobante', $propio))->assertForbidden();

    expect(Auditoria::query()->withoutGlobalScopes()->where('accion', 'acceso_denegado')->where('entidad_id', $propio->id)->exists())->toBeTrue();

    // El archivo no tiene una dirección pública
    expect(Storage::disk(TransferenciaService::DISCO)->path($propio->comprobante_path))->not->toContain('public');
});

// Criterio 3: tesorería recibe una notificación por cada reporte

test('the treasury is notified by mail of each new report', function () {
    $otroTesorero = ($this->usuario)('tesorero', null, ['name' => 'Tania Tesorera']);

    ($this->reportar)()->assertSessionHasNoErrors();
    ($this->reportar)(['numero_referencia' => 'ATL-2002'])->assertSessionHasNoErrors();

    [$primero, $segundo] = ($this->transferencias)()->orderBy('id')->get()->all();

    foreach ([$this->tesorero, $otroTesorero] as $destinatario) {
        Notification::assertSentToTimes($destinatario, TransferenciaReportada::class, 2);

        foreach ([$primero, $segundo] as $pago) {
            Notification::assertSentTo($destinatario, TransferenciaReportada::class, fn (TransferenciaReportada $aviso, array $canales) => $aviso->pagoId === $pago->id
                && $canales === ['mail']
                && $aviso->vivienda === 'A-01'
                && $aviso->residente === 'Rosa Vecina'
                && $aviso->monto === '500.00');
        }
    }

    Notification::assertCount(4);
});

test('the mail links to the transfer detail', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    Notification::assertSentTo($this->tesorero, TransferenciaReportada::class, function (TransferenciaReportada $aviso) use ($pago) {
        $correo = $aviso->toMail($this->tesorero);

        return $correo->subject === 'Transferencia reportada por la vivienda A-01'
            && $correo->actionUrl === route('finanzas.transferencias.reportes.show', $pago)
            && in_array('Banco: Banco Atlántida · Referencia: ATL-2001', $correo->introLines, true);
    });
});

// Vista del enlace del correo: se abre con transferencias.registrar, sin cobros.ver

test('a recipient without the payments permission opens the linked report', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);
    $this->actingAs($admin)->put(route('usuarios.permisos.update', ($this->rol)('cobrador')), ['permisos' => ['transferencias.registrar']])->assertSessionHasNoErrors();
    $cobrador = ($this->usuario)('cobrador', null, ['name' => 'Ciro Cobrador']);

    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    // Recibe el aviso y el enlace le abre el reporte
    Notification::assertSentTo($cobrador, TransferenciaReportada::class, fn (TransferenciaReportada $aviso) => $aviso->toMail($cobrador)->actionUrl === route('finanzas.transferencias.reportes.show', $pago));

    $this->actingAs($cobrador)
        ->get(route('finanzas.transferencias.reportes.show', $pago))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/transferencia-reportada')
            ->where('reporte', [
                'id' => $pago->id,
                'vivienda' => 'A-01',
                'monto' => '500.00',
                'fecha_pago' => TransferenciaService::hoy(),
                'banco' => 'Banco Atlántida',
                'numero_referencia' => 'ATL-2001',
                'cuenta_receptora' => null,
                'estado' => Pago::REPORTADO_POR_VECINO,
                'reportado_por' => 'Rosa Vecina',
                'reportado_en' => $pago->created_at->toIso8601String(),
                'tiene_comprobante' => true,
            ])
            // Sin saldo de la vivienda ni botón para ir a cobros
            ->missing('reporte.saldo_vivienda')
            ->where('puedeVerCobros', false));

    $respuesta = $this->get(route('finanzas.transferencias.reportes.comprobante', $pago))->assertOk();
    expect($respuesta->streamedContent())->toContain('%PDF-1.4');

    // No gana cobros.ver: el detalle de cobros y su comprobante siguen cerrados
    $this->get(route('finanzas.pagos.show', $pago))->assertForbidden();
    $this->get(route('finanzas.transferencias.comprobante', $pago))->assertForbidden();
    $this->get(route('finanzas.pagos.index'))->assertForbidden();

    expect(app(PermisoService::class)->deUsuario($cobrador))->toBe(['transferencias.registrar']);
});

test('the treasurer opens the linked report with the option to go to payments', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.transferencias.reportes.show', $pago))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('reporte.saldo_vivienda')
            ->where('puedeVerCobros', true));

    $this->get(route('finanzas.pagos.show', $pago))->assertOk();
});

test('a transfer registered by the treasurer cannot be opened from the report view', function () {
    // HU-24: registrada por tesorería, no reportada desde el portal
    $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), ($this->datos)([
        'numero_referencia' => 'ATL-5001',
        'cuenta_receptora' => '730-123456-7',
    ]))->assertSessionHasNoErrors();

    $registrada = ($this->transferencias)()->sole();

    expect($registrada->origen)->toBe(Pago::SISTEMA);

    $this->get(route('finanzas.transferencias.reportes.show', $registrada))->assertNotFound();
    $this->get(route('finanzas.transferencias.reportes.comprobante', $registrada))->assertNotFound();

    // Sigue disponible por las rutas de la HU-24
    $this->get(route('finanzas.pagos.show', $registrada))->assertOk();
    $this->get(route('finanzas.transferencias.comprobante', $registrada))->assertOk();
});

test('a cash payment cannot be opened from the report view', function () {
    $efectivo = Pago::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'forma_pago' => Pago::EFECTIVO,
        'estado' => Pago::CONFIRMADO,
        'origen' => Pago::SISTEMA,
        'monto' => '500.00',
        'fecha_pago' => '2026-11-14',
        'numero_recibo' => '1',
        'registrado_por' => $this->tesorero->id,
    ]);

    $this->actingAs($this->tesorero)->get(route('finanzas.transferencias.reportes.show', $efectivo))->assertNotFound();
    $this->get(route('finanzas.transferencias.reportes.comprobante', $efectivo))->assertNotFound();
});

test('the report view requires the transfer permission and the same residential', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    // Sin transferencias.registrar, incluido el vecino que lo reportó
    foreach ([$this->rosa, ($this->usuario)('fiscal'), ($this->usuario)(Rol::PRESIDENTE), ($this->usuario)('cobrador')] as $usuario) {
        $this->actingAs($usuario)->get(route('finanzas.transferencias.reportes.show', $pago))->assertForbidden();
        $this->get(route('finanzas.transferencias.reportes.comprobante', $pago))->assertForbidden();
    }

    // De otro residencial: se deniega y queda en la bitácora
    $this->actingAs(($this->usuario)('tesorero', $this->otro))->get(route('finanzas.transferencias.reportes.show', $pago))->assertForbidden();
    $this->get(route('finanzas.transferencias.reportes.comprobante', $pago))->assertForbidden();

    expect(Auditoria::query()->withoutGlobalScopes()->where('accion', 'acceso_denegado')->where('entidad_id', $pago->id)->count())->toBe(2);
});

test('only active accounts of the residential with the transfer permission are notified', function () {
    $inactivo = ($this->usuario)('tesorero', null, ['activo' => false]);
    $deOtro = ($this->usuario)('tesorero', $this->otro);
    $presidente = ($this->usuario)(Rol::PRESIDENTE);
    $cobrador = ($this->usuario)('cobrador');
    $tecnico = ($this->usuario)(Rol::ADMIN_TECNICO);

    ($this->reportar)()->assertSessionHasNoErrors();

    Notification::assertSentTo($this->tesorero, TransferenciaReportada::class);

    foreach ([$inactivo, $deOtro, $presidente, $cobrador, $tecnico, $this->rosa] as $usuario) {
        Notification::assertNotSentTo($usuario, TransferenciaReportada::class);
    }
});

test('the recipients follow the permissions configured for each role', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);
    $cobrador = ($this->usuario)('cobrador');

    // El tesorero deja de registrar transferencias y el cobrador empieza a hacerlo
    $this->actingAs($admin)->put(route('usuarios.permisos.update', ($this->rol)('tesorero')), ['permisos' => ['cobros.ver']])->assertSessionHasNoErrors();
    $this->put(route('usuarios.permisos.update', ($this->rol)('cobrador')), ['permisos' => ['cobros.registrar', 'transferencias.registrar']])->assertSessionHasNoErrors();

    ($this->reportar)()->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->tesorero, TransferenciaReportada::class);
    Notification::assertSentTo($cobrador, TransferenciaReportada::class);
});

test('no notification is sent when the report is rejected', function () {
    ($this->reportar)(['banco' => ''])->assertSessionHasErrors('banco');
    ($this->reportar)(['vivienda_id' => ($this->vivienda)($this->residencial, 'A-02')->id])->assertSessionHasErrors('vivienda_id');

    Notification::assertNothingSent();
});

test('a mail failure is logged and keeps the saved report', function () {
    Exceptions::fake();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('El servidor de correo no responde.'));

    ($this->reportar)()
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('portal.transferencias.index'));

    $pago = ($this->transferencias)()->sole();

    expect($pago->estado)->toBe(Pago::REPORTADO_POR_VECINO);

    Storage::disk(TransferenciaService::DISCO)->assertExists($pago->comprobante_path);
    Exceptions::assertReported(fn (RuntimeException $error) => $error->getMessage() === 'El servidor de correo no responde.');
});

// La vivienda: solo una donde el residente vive

test('any resident living in the house can report, even if not responsible for payment', function () {
    $familiar = ($this->vecino)(null, ['tipo' => 'familiar', 'es_responsable_pago' => false], ['nombres' => 'Rita']);

    ($this->reportar)([], $familiar)->assertSessionHasNoErrors();

    expect(($this->transferencias)()->sole()->registrado_por)->toBe($familiar->id);
});

test('the resident can only report for a house where they currently live', function (string $caso) {
    $vivienda = match ($caso) {
        'ajena' => ($this->vivienda)($this->residencial, 'A-02'),
        'inactiva' => ($this->vivienda)($this->residencial, 'A-03', false),
        'otro residencial' => ($this->vivienda)($this->otro, 'Z-01'),
        'vínculo terminado' => ($this->vivienda)($this->residencial, 'A-04'),
    };

    if ($caso === 'vínculo terminado' || $caso === 'inactiva') {
        $residente = Residente::query()->where('user_id', $this->rosa->id)->sole();

        ViviendaResidente::query()->create([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $vivienda->id,
            'residente_id' => $residente->id,
            'tipo' => 'inquilino',
            'fecha_inicio' => '2025-01-01',
            'fecha_fin' => $caso === 'vínculo terminado' ? '2025-12-31' : null,
        ]);
    }

    ($this->reportar)(['vivienda_id' => $vivienda->id])->assertSessionHasErrors('vivienda_id');

    expect(($this->transferencias)()->exists())->toBeFalse()
        ->and(Storage::disk(TransferenciaService::DISCO)->allFiles())->toBe([]);
})->with(['ajena', 'inactiva', 'otro residencial', 'vínculo terminado']);

test('the form offers only the active houses where the resident lives', function () {
    $segunda = ($this->vivienda)($this->residencial, 'A-05');
    $residente = Residente::query()->where('user_id', $this->rosa->id)->sole();

    ViviendaResidente::query()->create(['residencial_id' => $this->residencial->id, 'vivienda_id' => $segunda->id, 'residente_id' => $residente->id, 'tipo' => 'propietario']);
    ViviendaResidente::query()->create(['residencial_id' => $this->residencial->id, 'vivienda_id' => ($this->vivienda)($this->residencial, 'A-06', false)->id, 'residente_id' => $residente->id, 'tipo' => 'propietario']);
    ViviendaResidente::query()->create(['residencial_id' => $this->residencial->id, 'vivienda_id' => ($this->vivienda)($this->residencial, 'A-07')->id, 'residente_id' => $residente->id, 'tipo' => 'propietario', 'fecha_fin' => '2025-12-31']);
    ($this->vivienda)($this->residencial, 'A-08');

    $this->actingAs($this->rosa)
        ->get(route('portal.transferencias.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/reportar-transferencia')
            ->where('viviendas', [
                ['id' => $this->casa->id, 'codigo' => 'A-01'],
                ['id' => $segunda->id, 'codigo' => 'A-05'],
            ])
            ->where('hoy', TransferenciaService::hoy())
            ->where('tamanoMaximoMb', 5));
});

test('a resident without a linked active resident cannot use the portal', function (string $caso) {
    $cuenta = match ($caso) {
        'sin residente' => ($this->usuario)(Rol::RESIDENTE),
        'residente inactivo' => ($this->vecino)(null, [], ['activo' => false]),
    };

    $this->actingAs($cuenta)->get(route('portal.transferencias.index'))->assertForbidden();
    $this->get(route('portal.transferencias.create'))->assertForbidden();
    ($this->reportar)([], $cuenta)->assertForbidden();

    expect(($this->transferencias)()->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['sin residente', 'residente inactivo']);

test('logging in takes an active resident to the portal and an inactive one to the dashboard', function () {
    $this->post(route('login.store'), ['email' => $this->rosa->email, 'password' => 'password'])
        ->assertRedirect(route('portal.transferencias.index'));

    $this->get(route('portal.transferencias.index'))->assertOk();

    $this->post(route('logout'));
    $this->assertGuest();

    // Desactivado: conserva sus permisos, pero ya no entra al portal
    Residente::query()->withoutGlobalScopes()->where('user_id', $this->rosa->id)->update(['activo' => false]);

    $this->post(route('login.store'), ['email' => $this->rosa->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('portal.transferencias.index'))->assertForbidden();
});

test('a resident whose link ended still sees their previous reports but cannot report for that house', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    // Se muda: el vínculo con A-01 termina, pero sigue siendo un residente activo
    ViviendaResidente::query()->withoutGlobalScopes()
        ->where('residente_id', Residente::query()->withoutGlobalScopes()->where('user_id', $this->rosa->id)->value('id'))
        ->update(['fecha_fin' => '2026-11-01']);

    $this->actingAs($this->rosa)
        ->get(route('portal.transferencias.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reportes', 1)
            ->where('reportes.0.id', $pago->id));

    $this->get(route('portal.transferencias.comprobante', $pago))->assertOk();

    // Ya no puede reportar para esa vivienda ni se la ofrece el formulario
    $this->get(route('portal.transferencias.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas', []));

    ($this->reportar)(['numero_referencia' => 'ATL-6001'])->assertSessionHasErrors('vivienda_id');

    // Quien vive ahora en A-01 no ve los reportes anteriores de Rosa
    $nuevo = ($this->vecino)(null, [], ['nombres' => 'Nuevo']);

    $this->actingAs($nuevo)
        ->get(route('portal.transferencias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('reportes', 0));

    $this->get(route('portal.transferencias.comprobante', $pago))->assertForbidden();

    expect(($this->transferencias)()->count())->toBe(1);
});

test('a receipt whose file no longer exists returns 404 in the portal and in the treasury view', function () {
    ($this->reportar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    Storage::disk(TransferenciaService::DISCO)->delete($pago->comprobante_path);

    $this->actingAs($this->rosa)->get(route('portal.transferencias.comprobante', $pago))->assertNotFound();
    $this->actingAs($this->tesorero)->get(route('finanzas.transferencias.reportes.comprobante', $pago))->assertNotFound();

    // El reporte sigue abriéndose; solo falta el archivo
    $this->get(route('finanzas.transferencias.reportes.show', $pago))->assertOk();
});

// Referencia y datos

test('a reference already reported or registered is rejected in both directions', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    // El tesorero no puede registrar la misma referencia del mismo banco
    $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), ($this->datos)([
        'banco' => ' banco  atlántida ',
        'cuenta_receptora' => '730-123456-7',
    ]))->assertSessionHasErrors(['numero_referencia' => 'Ya existe una transferencia con ese número de referencia en ese banco.']);

    // Ni otro vecino reportar una que registró el tesorero
    $this->post(route('finanzas.transferencias.store'), ($this->datos)([
        'numero_referencia' => 'ATL-3001',
        'cuenta_receptora' => '730-123456-7',
    ]))->assertSessionHasNoErrors();

    ($this->reportar)(['numero_referencia' => 'ATL-3001'], ($this->vecino)())
        ->assertSessionHasErrors(['numero_referencia' => 'Ya existe una transferencia con ese número de referencia en ese banco.']);

    expect(($this->transferencias)()->count())->toBe(2)
        ->and(Storage::disk(TransferenciaService::DISCO)->allFiles())->toHaveCount(2);
});

test('the report data is validated like the treasurer registration', function (array $cambios, string $campo, string $mensaje) {
    ($this->reportar)($cambios)->assertSessionHasErrors([$campo => $mensaje]);

    expect(($this->transferencias)()->exists())->toBeFalse()
        ->and(Storage::disk(TransferenciaService::DISCO)->allFiles())->toBe([]);
})->with([
    'sin banco' => [['banco' => '   '], 'banco', 'Escribe el banco de la transferencia.'],
    'sin referencia' => [['numero_referencia' => ''], 'numero_referencia', 'Escribe el número de referencia.'],
    'sin monto' => [['monto' => ''], 'monto', 'Escribe el monto transferido.'],
    'monto en cero' => [['monto' => '0'], 'monto', 'El monto debe ser mayor que cero.'],
    'sin fecha' => [['fecha_pago' => ''], 'fecha_pago', 'Indica la fecha de la transferencia.'],
    'fecha futura' => [['fecha_pago' => '2999-01-01'], 'fecha_pago', 'La fecha de la transferencia no puede ser posterior a hoy.'],
    'sin vivienda' => [['vivienda_id' => ''], 'vivienda_id', 'Selecciona una vivienda.'],
    'sin comprobante' => [['comprobante' => null], 'comprobante', 'Adjunta el comprobante de la transferencia.'],
    'comprobante gif' => [['comprobante' => UploadedFile::fake()->image('comprobante.gif')], 'comprobante', 'El comprobante debe ser una imagen JPG o PNG, o un PDF.'],
    'más de 5 MB' => [['comprobante' => UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')], 'comprobante', 'El comprobante no puede pasar de 5 MB.'],
    'cuenta muy larga' => [['cuenta_receptora' => str_repeat('1', 61)], 'cuenta_receptora', 'El campo cuenta receptora no puede tener más de 60 caracteres.'],
]);

test('the receiving account is optional in the portal and kept when given', function () {
    ($this->reportar)(['cuenta_receptora' => '  730-123456-7 '])->assertSessionHasNoErrors();

    expect(($this->transferencias)()->sole()->cuenta_receptora)->toBe('730-123456-7');
});

test('the treasurer registration still requires the receiving account', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    $this->actingAs($this->tesorero)->post(route('finanzas.transferencias.store'), ($this->datos)([
        'numero_referencia' => 'ATL-4001',
        'cuenta_receptora' => '',
    ]))->assertSessionHasErrors(['cuenta_receptora' => 'Escribe la cuenta que recibió la transferencia.']);

    // Y sigue quedando pendiente de verificación, con origen sistema
    $this->post(route('finanzas.transferencias.store'), ($this->datos)([
        'numero_referencia' => 'ATL-4001',
        'cuenta_receptora' => '730-123456-7',
    ]))->assertSessionHasNoErrors();

    $registrada = ($this->transferencias)()->where('numero_referencia', 'ATL-4001')->sole();

    expect($registrada->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($registrada->origen)->toBe(Pago::SISTEMA);

    // Registrar no avisa a tesorería: solo el reporte del vecino
    Notification::assertSentToTimes($this->tesorero, TransferenciaReportada::class, 1);
});

// Permisos

test('the resident uses the portal without the treasury permissions', function () {
    $permisos = app(PermisoService::class)->deUsuario($this->rosa);

    expect($permisos)->toEqualCanonicalizing(['portal.ver', 'portal.registrar'])
        ->and(app(PermisoService::class)->rutaInicio($this->rosa))->toBe('portal.transferencias.index');

    $this->actingAs($this->rosa)->get(route('portal.transferencias.create'))->assertOk();
    $this->get(route('finanzas.transferencias.create'))->assertForbidden();
    $this->get(route('finanzas.pagos.index'))->assertForbidden();
    $this->post(route('finanzas.transferencias.store'), ($this->datos)(['cuenta_receptora' => '730-123456-7']))->assertForbidden();

    expect(($this->transferencias)()->exists())->toBeFalse();
});

test('without the portal permissions the resident cannot report nor see reports', function () {
    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->put(route('usuarios.permisos.update', ($this->rol)(Rol::RESIDENTE)), ['permisos' => ['portal.ver']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rosa)->get(route('portal.transferencias.index'))->assertOk();
    $this->get(route('portal.transferencias.create'))->assertForbidden();
    ($this->reportar)()->assertForbidden();

    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->put(route('usuarios.permisos.update', ($this->rol)(Rol::RESIDENTE)), ['permisos' => []])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rosa)->get(route('portal.transferencias.index'))->assertForbidden();

    expect(($this->transferencias)()->exists())->toBeFalse();
});

test('other roles cannot use the portal', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('portal.transferencias.index'))->assertForbidden();
    ($this->reportar)([], $usuario)->assertForbidden();

    expect(($this->transferencias)()->exists())->toBeFalse();
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero', 'fiscal', 'vocal', 'cobrador', 'guardia']);

test('a role given the portal permission still needs a linked resident', function () {
    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->put(route('usuarios.permisos.update', ($this->rol)('vocal')), ['permisos' => ['padron.ver', 'portal.ver', 'portal.registrar']])
        ->assertSessionHasNoErrors();

    $vocal = ($this->usuario)('vocal');

    $this->actingAs($vocal)->get(route('portal.transferencias.index'))->assertForbidden();
    ($this->reportar)([], $vocal)->assertForbidden();
});

test('the permissions screen offers the portal module', function () {
    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->get(route('usuarios.permisos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('identidad/permisos')
            ->where('modulos', fn ($modulos) => collect($modulos)->firstWhere('id', 'portal') === [
                'id' => 'portal',
                'etiqueta' => 'Portal del residente',
                'acciones' => ['ver', 'registrar'],
            ])
            ->where('roles', fn ($roles) => collect(collect($roles)->firstWhere('nombre', 'Residente')['permisos'])->sort()->values()->all() === ['portal.registrar', 'portal.ver']));
});

test('the sidebar shows the portal options only to an account linked to an active resident', function () {
    $this->actingAs($this->rosa)
        ->get(route('portal.transferencias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.esResidente', true)
            ->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('portal.ver') && collect($permisos)->contains('portal.registrar')));

    // El administrador técnico tiene todos los permisos, pero no es residente
    $tecnico = ($this->usuario)(Rol::ADMIN_TECNICO);

    $this->actingAs($tecnico)
        ->get(route('usuarios.permisos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.esResidente', false)
            ->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('portal.ver')));

    $this->get(route('portal.transferencias.index'))->assertForbidden();

    // El tesorero tampoco, y un residente inactivo deja de verlo
    $this->actingAs($this->tesorero)->get(route('finanzas.pagos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.esResidente', false));

    Residente::query()->withoutGlobalScopes()->where('user_id', $this->rosa->id)->update(['activo' => false]);

    $this->actingAs($this->rosa)->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.esResidente', false));
});

test('existing residentials give the resident the portal permissions', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'portal')->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_17_100001_asignar_portal_al_residente.php');
    $migracion->up();
    $migracion->up();

    $permisos = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('residencial_id', $antiguo->id)
        ->where('modulo', 'portal')
        ->orderBy('accion')
        ->get(['roles.codigo', 'accion'])
        ->map(fn ($fila) => "{$fila->codigo}.{$fila->accion}")
        ->all();

    expect($permisos)->toBe(['residente.registrar', 'residente.ver']);

    $migracion->down();

    expect(DB::table('rol_permisos')->where('modulo', 'portal')->exists())->toBeFalse();
});
