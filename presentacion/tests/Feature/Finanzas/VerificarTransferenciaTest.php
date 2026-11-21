<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Notificaciones\TransferenciaConfirmada;
use Patronato\Negocio\Notificaciones\TransferenciaRechazada;
use Patronato\Negocio\Services\ComprobanteService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Negocio\Services\VerificacionTransferenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Ingreso;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

// HU-26 · Verificar una transferencia

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
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE, null, ['name' => 'Paula Presidenta']);

    $this->vivienda = fn (Residencial $residencial, string $codigo): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)($this->residencial, 'A-01');

    // Residente con correo que vive en la casa; si se pide, con cuenta del portal
    $this->residente = function (string $correo, ?User $cuenta = null): Residente {
        $registro = Residente::query()->create([
            'residencial_id' => $this->residencial->id,
            'user_id' => $cuenta?->id,
            'nombres' => 'Rosa',
            'apellidos' => 'Vecina',
            'correo' => $correo,
            'activo' => true,
        ]);

        ViviendaResidente::query()->create([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $this->casa->id,
            'residente_id' => $registro->id,
            'tipo' => 'propietario',
            'es_responsable_pago' => true,
            'fecha_inicio' => '2026-01-01',
        ]);

        return $registro;
    };

    // Tres meses de L 500.00 pendientes
    $concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->cargos = [];

    foreach (['2026-09-01', '2026-10-01', '2026-11-01'] as $periodo) {
        $this->cargos[] = DB::table('cargos')->insertGetId([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $this->casa->id,
            'concepto_id' => $concepto,
            'periodo' => $periodo,
            'monto' => '500.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->pdf = fn (): UploadedFile => UploadedFile::fake()->createWithContent('comprobante.pdf', "%PDF-1.4\n%comprobante de prueba\n");

    // El tesorero (u otra cuenta) registra una transferencia pendiente
    $this->registrar = function (array $cambios = [], ?User $usuario = null): Pago {
        $this->actingAs($usuario ?? $this->tesorero)->post(route('finanzas.transferencias.store'), [
            'vivienda_id' => $this->casa->id,
            'monto' => '1000',
            'fecha_pago' => TransferenciaService::hoy(),
            'banco' => 'BAC Credomatic',
            'numero_referencia' => 'REF-1001',
            'cuenta_receptora' => '730-123456-7',
            'comprobante' => ($this->pdf)(),
            ...$cambios,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Pago::query()->withoutGlobalScopes()->where('forma_pago', Pago::TRANSFERENCIA)->latest('id')->firstOrFail();
    };

    $this->confirmar = fn (Pago $pago, array $cargos, ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route('finanzas.transferencias.verificar.confirmar', $pago), ['cargos' => $cargos]);

    $this->rechazar = fn (Pago $pago, string $motivo = 'No aparece en el estado de cuenta del banco.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route('finanzas.transferencias.verificar.rechazar', $pago), ['motivo' => $motivo]);

    $this->saldo = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', '');

    $this->dobleControl = fn (bool $activo) => DB::table('configuracion_residencial')
        ->updateOrInsert(['residencial_id' => $this->residencial->id], ['doble_control_transferencias' => $activo, 'updated_at' => now()]);
});

// Bandeja de pendientes

test('the inbox lists the pending transfers, oldest first, with the days without verification', function () {
    // Registrada hace tres días; se envejece en la base porque viajar en el tiempo cierra la sesión por inactividad
    $vieja = ($this->registrar)(['numero_referencia' => 'REF-VIEJA']);
    DB::table('pagos')->where('id', $vieja->id)->update(['created_at' => now()->subDays(3)]);

    $nueva = ($this->registrar)(['numero_referencia' => 'REF-NUEVA', 'monto' => '500']);

    // No aparecen las ya verificadas, el efectivo ni las de otro residencial
    $rechazada = ($this->registrar)(['numero_referencia' => 'REF-RECHAZADA']);
    ($this->rechazar)($rechazada)->assertSessionHasNoErrors();
    ($this->registrar)(['vivienda_id' => ($this->vivienda)($this->otro, 'Z-01')->id, 'numero_referencia' => 'REF-OTRO'], ($this->usuario)('tesorero', $this->otro));

    $this->actingAs($this->tesorero)->get(route('finanzas.transferencias.verificar.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/transferencias-por-verificar')
            ->has('transferencias', 2)
            ->where('transferencias.0.id', $vieja->id)
            ->where('transferencias.0.dias_sin_verificar', 3)
            ->where('transferencias.0.vivienda', 'A-01')
            ->where('transferencias.0.registrado_por', 'Tomás Tesorero')
            ->where('transferencias.1.id', $nueva->id)
            ->where('transferencias.1.dias_sin_verificar', 0)
            // El menú muestra cuántas faltan
            ->where('auth.transferenciasPorVerificar', 2));
});

test('the days without verification are counted in Honduras time', function () {
    $pago = new Pago;
    // 11 p. m. del 16 en Honduras es el 17 en UTC
    $pago->created_at = CarbonImmutable::parse('2026-11-17 05:00:00', 'UTC');

    expect(VerificacionTransferenciaService::diasSinVerificar($pago, CarbonImmutable::parse('2026-11-17 15:00:00', 'UTC')))->toBe(1)
        ->and(VerificacionTransferenciaService::diasSinVerificar($pago, CarbonImmutable::parse('2026-11-17 05:30:00', 'UTC')))->toBe(0);
});

test('the verification page suggests the oldest months that cover the amount', function () {
    $pago = ($this->registrar)();

    $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.show', $pago))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/verificar-transferencia')
            ->where('transferencia.por_verificar', true)
            ->where('transferencia.motivo_no_confirmar', null)
            ->has('transferencia.pendientes', 3)
            ->where('transferencia.sugeridos', [$this->cargos[0], $this->cargos[1]]));
});

// Confirmar

test('confirming applies the transfer to the account, issues a receipt and records who and when', function () {
    $pago = ($this->registrar)();
    expect(($this->saldo)())->toBe('1500.00');

    $this->freezeTime();

    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.transferencias.verificar.index'));

    $pago->refresh();

    expect($pago->estado)->toBe(Pago::CONFIRMADO)
        ->and($pago->numero_recibo)->not->toBeNull()
        ->and($pago->verificado_por)->toBe($this->presidente->id)
        ->and($pago->verificado_en->toDateTimeString())->toBe(now()->toDateTimeString())
        // No entra a ninguna caja: no es efectivo
        ->and($pago->caja_id)->toBeNull()
        ->and($pago->aplicaciones()->withoutGlobalScopes()->pluck('cargo_id')->sort()->values()->all())->toBe([$this->cargos[0], $this->cargos[1]])
        ->and(($this->saldo)())->toBe('500.00');

    // Entra como ingreso de cuotas, a nombre de quien la confirmó
    $ingreso = Ingreso::query()->withoutGlobalScopes()->where('pago_id', $pago->id)->sole();
    expect($ingreso->monto)->toBe('1000.00')
        ->and($ingreso->registrado_por)->toBe($this->presidente->id);

    $auditoria = Auditoria::query()->where('accion', 'transferencia_confirmada')->sole();
    expect($auditoria->user_id)->toBe($this->presidente->id)
        ->and($auditoria->valor_anterior)->toBe(['estado' => Pago::PENDIENTE_VERIFICACION])
        ->and($auditoria->valor_nuevo['numero_recibo'])->toBe($pago->numero_recibo);
});

test('a confirmed transfer gets a receipt that says transfer and who verified it', function () {
    $pago = ($this->registrar)();
    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]])->assertSessionHasNoErrors();

    $datos = app(ComprobanteService::class)->datos($pago->refresh());

    expect($datos['forma_pago'])->toBe('Transferencia')
        ->and($datos['referencia'])->toBe('BAC Credomatic · ref. REF-1001')
        ->and($datos['etiqueta_responsable'])->toBe('Verificó')
        ->and($datos['cobrador'])->toBe('Paula Presidenta')
        ->and($datos['periodos'])->toHaveCount(2);
});

test('the residents receive the confirmation with the receipt attached', function () {
    $cuenta = ($this->usuario)(Rol::RESIDENTE, null, ['email' => 'rosa.portal@correo.hn']);
    ($this->residente)('rosa@correo.hn', $cuenta);

    // La reportó la vecina desde el portal
    $this->actingAs($cuenta)->post(route('portal.transferencias.store'), [
        'vivienda_id' => $this->casa->id,
        'monto' => '500',
        'fecha_pago' => TransferenciaService::hoy(),
        'banco' => 'BAC Credomatic',
        'numero_referencia' => 'REF-PORTAL',
        'comprobante' => ($this->pdf)(),
    ])->assertSessionHasNoErrors();

    $pago = Pago::query()->withoutGlobalScopes()->where('numero_referencia', 'REF-PORTAL')->sole();

    ($this->confirmar)($pago, [$this->cargos[0]])->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(TransferenciaConfirmada::class, function (TransferenciaConfirmada $aviso, array $canales, AnonymousNotifiable $destino) use ($pago) {
        return $destino->routes['mail'] === ['rosa@correo.hn', 'rosa.portal@correo.hn']
            && $aviso->numeroRecibo === $pago->refresh()->numero_recibo
            && str_starts_with($aviso->pdf, '%PDF');
    });
});

test('if the email fails the confirmation is kept', function () {
    Exceptions::fake();
    ($this->residente)('rosa@correo.hn');
    $pago = ($this->registrar)();

    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Servidor de correo caído'));

    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]])->assertSessionHasNoErrors();

    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO);
    Exceptions::assertReported(RuntimeException::class);
});

test('the months must cover the amount of the transfer', function () {
    $pago = ($this->registrar)();

    // L 1000 no cabe en un mes de L 500: no hay saldo a favor todavía
    ($this->confirmar)($pago, [$this->cargos[0]])->assertSessionHasErrors('cargos');

    // Sin abonos parciales, L 1000 no puede dejar a medias tres meses de L 1500
    ($this->confirmar)($pago, $this->cargos)->assertSessionHasErrors('cargos');

    // Un mes de otra vivienda o inexistente no se acepta
    ($this->confirmar)($pago, [$this->cargos[0], 999999])->assertSessionHasErrors('cargos');

    expect($pago->refresh()->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($pago->numero_recibo)->toBeNull();
});

test('with partial payments allowed the last month can stay partly covered', function () {
    DB::table('configuracion_residencial')->updateOrInsert(['residencial_id' => $this->residencial->id], ['permite_abono_parcial' => true, 'updated_at' => now()]);
    $pago = ($this->registrar)();

    ($this->confirmar)($pago, $this->cargos)->assertSessionHasNoErrors();

    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and(($this->saldo)())->toBe('500.00');
});

// Doble control

test('with double control the person who registered the transfer cannot confirm it', function () {
    ($this->dobleControl)(true);
    $pago = ($this->registrar)();

    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]], $this->tesorero)
        ->assertSessionHasErrors(['pago' => 'El patronato usa doble control: no puedes confirmar una transferencia que registraste. Otra persona de tesorería debe hacerlo.']);

    expect($pago->refresh()->estado)->toBe(Pago::PENDIENTE_VERIFICACION);

    // La pantalla lo avisa
    $this->actingAs($this->tesorero)->get(route('finanzas.transferencias.verificar.show', $pago))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('transferencia.motivo_no_confirmar', fn ($motivo) => $motivo !== null));

    // Otra persona sí puede
    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]], $this->presidente)->assertSessionHasNoErrors();
    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO);
});

test('without double control the person who registered the transfer can confirm it', function () {
    ($this->dobleControl)(false);
    $pago = ($this->registrar)();

    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]], $this->tesorero)->assertSessionHasNoErrors();

    expect($pago->refresh()->estado)->toBe(Pago::CONFIRMADO)
        ->and($pago->verificado_por)->toBe($this->tesorero->id);
});

// Rechazar

test('rejecting requires a reason, keeps the balance and frees the bank reference', function () {
    $pago = ($this->registrar)();
    $comprobante = $pago->comprobante_path;

    ($this->rechazar)($pago, '')->assertSessionHasErrors('motivo');
    ($this->rechazar)($pago, '   corto  ')->assertSessionHasErrors('motivo');

    ($this->rechazar)($pago)->assertSessionHasNoErrors()->assertRedirect(route('finanzas.transferencias.verificar.index'));

    $pago->refresh();

    expect($pago->estado)->toBe(Pago::RECHAZADO)
        ->and($pago->motivo_rechazo)->toBe('No aparece en el estado de cuenta del banco.')
        ->and($pago->verificado_por)->toBe($this->presidente->id)
        ->and($pago->verificado_en)->not->toBeNull()
        ->and($pago->numero_recibo)->toBeNull()
        ->and($pago->aplicaciones()->withoutGlobalScopes()->count())->toBe(0)
        ->and(($this->saldo)())->toBe('1500.00')
        // El comprobante se conserva
        ->and(Storage::disk(TransferenciaService::DISCO)->exists($comprobante))->toBeTrue()
        ->and(Ingreso::query()->withoutGlobalScopes()->where('pago_id', $pago->id)->exists())->toBeFalse();

    expect(Auditoria::query()->where('accion', 'transferencia_rechazada')->sole()->motivo)->toBe('No aparece en el estado de cuenta del banco.');

    // La misma referencia puede registrarse de nuevo
    ($this->registrar)();
});

test('the resident receives the rejection with its reason and sees it in the portal', function () {
    $cuenta = ($this->usuario)(Rol::RESIDENTE, null, ['email' => 'rosa@correo.hn']);
    ($this->residente)('rosa@correo.hn', $cuenta);

    $this->actingAs($cuenta)->post(route('portal.transferencias.store'), [
        'vivienda_id' => $this->casa->id,
        'monto' => '500',
        'fecha_pago' => TransferenciaService::hoy(),
        'banco' => 'BAC Credomatic',
        'numero_referencia' => 'REF-PORTAL',
        'comprobante' => ($this->pdf)(),
    ])->assertSessionHasNoErrors();

    $pago = Pago::query()->withoutGlobalScopes()->where('numero_referencia', 'REF-PORTAL')->sole();

    ($this->rechazar)($pago, 'El monto no coincide con el depósito.')->assertSessionHasNoErrors();

    // El mismo correo no se repite aunque sea del residente y de la cuenta
    Notification::assertSentOnDemand(TransferenciaRechazada::class, fn (TransferenciaRechazada $aviso, array $canales, AnonymousNotifiable $destino) => $destino->routes['mail'] === ['rosa@correo.hn']
        && $aviso->motivo === 'El monto no coincide con el depósito.');

    $this->actingAs($cuenta)->get(route('portal.transferencias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reportes.0.estado', Pago::RECHAZADO)
            ->where('reportes.0.motivo_rechazo', 'El monto no coincide con el depósito.'));
});

test('a transfer cannot be verified twice', function () {
    $pago = ($this->registrar)();
    ($this->rechazar)($pago)->assertSessionHasNoErrors();

    ($this->confirmar)($pago, [$this->cargos[0], $this->cargos[1]])->assertSessionHasErrors(['pago' => 'Esta transferencia ya fue verificada.']);
    ($this->rechazar)($pago)->assertSessionHasErrors(['pago' => 'Esta transferencia ya fue verificada.']);

    expect($pago->refresh()->estado)->toBe(Pago::RECHAZADO);

    // La pantalla muestra el resultado en lugar de los botones
    $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.show', $pago))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('transferencia.por_verificar', false)
            ->where('transferencia.verificado_por', 'Paula Presidenta')
            ->where('transferencia.pendientes', null));
});

// Permisos

test('the treasurer and the president verify by default; other roles cannot', function () {
    $pago = ($this->registrar)();

    $permisos = app(PermisoService::class);
    expect($permisos->puede($this->tesorero, 'transferencias', 'verificar'))->toBeTrue()
        ->and($permisos->puede($this->presidente, 'transferencias', 'verificar'))->toBeTrue();

    foreach (['cobrador', 'fiscal', 'vocal', Rol::SECRETARIO, Rol::RESIDENTE] as $rol) {
        $usuario = ($this->usuario)($rol);

        $this->actingAs($usuario)->get(route('finanzas.transferencias.verificar.index'))->assertForbidden();
        $this->actingAs($usuario)->post(route('finanzas.transferencias.verificar.confirmar', $pago), ['cargos' => [$this->cargos[0]]])->assertForbidden();
        $this->actingAs($usuario)->post(route('finanzas.transferencias.verificar.rechazar', $pago), ['motivo' => 'No aparece en el banco.'])->assertForbidden();
    }

    expect($pago->refresh()->estado)->toBe(Pago::PENDIENTE_VERIFICACION);
});

test('the president opens the receipt of the transfer without seeing the payments list', function () {
    $pago = ($this->registrar)();

    $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.comprobante', $pago))->assertOk();
    $this->actingAs($this->presidente)->get(route('finanzas.pagos.index'))->assertForbidden();
});

test('a transfer of another residential is denied and a cash payment is not found', function () {
    $ajena = ($this->registrar)(['vivienda_id' => ($this->vivienda)($this->otro, 'Z-01')->id], ($this->usuario)('tesorero', $this->otro));

    // El aislamiento por residencial responde "Acceso denegado" y lo deja en la auditoría
    $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.show', $ajena))->assertForbidden();
    ($this->rechazar)($ajena)->assertForbidden();
    ($this->confirmar)($ajena, [$this->cargos[0]])->assertForbidden();

    $efectivo = Pago::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'forma_pago' => Pago::EFECTIVO,
        'estado' => Pago::CONFIRMADO,
        'origen' => 'sistema',
        'monto' => '500.00',
        'fecha_pago' => TransferenciaService::hoy(),
        'numero_recibo' => '900',
        'registrado_por' => $this->tesorero->id,
    ]);

    $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.show', $efectivo))->assertNotFound();
    ($this->rechazar)($efectivo)->assertNotFound();

    expect($ajena->refresh()->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($efectivo->refresh()->estado)->toBe(Pago::CONFIRMADO);
});

test('the permissions screen offers the verify action for transfers', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    $this->actingAs($admin)->get(route('usuarios.permisos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('modulos', fn ($modulos) => collect($modulos)->firstWhere('id', 'transferencias')['acciones'] === ['registrar', 'verificar']));

    $this->actingAs($this->presidente)->get(route('finanzas.transferencias.verificar.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('transferencias.verificar')));
});
