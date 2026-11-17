<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    Storage::fake(TransferenciaService::DISCO);

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
        'banco' => 'BAC Credomatic',
        'numero_referencia' => 'REF-1001',
        'cuenta_receptora' => '730-123456-7',
        'comprobante' => ($this->pdf)(),
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->post(route('finanzas.transferencias.store'), ($this->datos)($cambios));

    $this->saldo = fn (): string => number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', '');

    $this->transferencias = fn () => Pago::query()->withoutGlobalScopes()->where('forma_pago', Pago::TRANSFERENCIA);

    // Caso más exigente: una cobradora con caja abierta y un cobro en efectivo de L 500.00
    // que además puede registrar transferencias
    $this->cobradoraConCaja = function (): array {
        $cobradora = ($this->usuario)('cobrador', null, ['name' => 'Marta Cobradora']);

        $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
            ->put(route('usuarios.permisos.update', ($this->rol)('cobrador')), ['permisos' => ['cobros.registrar', 'transferencias.registrar']])
            ->assertSessionHasNoErrors();

        $caja = Caja::query()->create([
            'residencial_id' => $this->residencial->id,
            'cobrador_id' => $cobradora->id,
            'estado' => Caja::ABIERTA,
            'abierta_en' => now(),
        ]);

        Pago::query()->create([
            'residencial_id' => $this->residencial->id,
            'vivienda_id' => $this->casa->id,
            'forma_pago' => Pago::EFECTIVO,
            'estado' => Pago::CONFIRMADO,
            'origen' => 'sistema',
            'monto' => '500.00',
            'fecha_pago' => TransferenciaService::hoy(),
            'numero_recibo' => '1',
            'caja_id' => $caja->id,
            'registrado_por' => $cobradora->id,
        ]);

        // La misma cobradora registra una transferencia de L 800.00
        ($this->registrar)(['monto' => '800'], $cobradora)->assertSessionHasNoErrors();

        return [$cobradora, $caja];
    };
});

test('a new transfer stays pending verification and does not reduce the balance', function () {
    expect(($this->saldo)())->toBe('500.00');

    ($this->registrar)()->assertSessionHasNoErrors();

    $pago = ($this->transferencias)()->sole();

    expect($pago->estado)->toBe(Pago::PENDIENTE_VERIFICACION)
        ->and($pago->residencial_id)->toBe($this->residencial->id)
        ->and($pago->vivienda_id)->toBe($this->casa->id)
        ->and($pago->monto)->toBe('500.00')
        ->and($pago->fecha_pago->toDateString())->toBe(TransferenciaService::hoy())
        ->and($pago->banco)->toBe('BAC Credomatic')
        ->and($pago->numero_referencia)->toBe('REF-1001')
        ->and($pago->cuenta_receptora)->toBe('730-123456-7')
        ->and($pago->registrado_por)->toBe($this->tesorero->id)
        // Sin recibo, sin caja y sin meses aplicados hasta que se verifique (HU-26)
        ->and($pago->numero_recibo)->toBeNull()
        ->and($pago->caja_id)->toBeNull()
        ->and(DB::table('pago_aplicaciones')->where('pago_id', $pago->id)->exists())->toBeFalse()
        ->and(DB::table('cajas')->exists())->toBeFalse()
        ->and(($this->saldo)())->toBe('500.00');
});

test('registering a transfer is audited and leads to the payment detail', function () {
    ($this->registrar)()
        ->assertRedirect(route('finanzas.pagos.show', ($this->transferencias)()->sole()))
        ->assertInertiaFlash('toast.message', 'Transferencia registrada. Queda pendiente de verificación.');

    $pago = ($this->transferencias)()->sole();
    $registro = Auditoria::query()->where('accion', 'transferencia_registrada')->sole();

    expect($registro->modulo)->toBe('transferencias')
        ->and($registro->user_id)->toBe($this->tesorero->id)
        ->and($registro->entidad_id)->toBe($pago->id)
        ->and($registro->valor_nuevo)->toMatchArray([
            'monto' => '500.00',
            'banco' => 'BAC Credomatic',
            'numero_referencia' => 'REF-1001',
            'cuenta_receptora' => '730-123456-7',
            'estado' => Pago::PENDIENTE_VERIFICACION,
        ]);
});

test('the same reference cannot be registered twice for the same bank', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    // Mismo banco aunque cambien las mayúsculas y los espacios
    ($this->registrar)(['banco' => '  bac   CREDOMATIC ', 'numero_referencia' => ' REF-1001 '])
        ->assertSessionHasErrors(['numero_referencia' => 'Ya existe una transferencia con ese número de referencia en ese banco.']);

    expect(($this->transferencias)()->count())->toBe(1)
        // El comprobante de la transferencia rechazada no se guarda
        ->and(Storage::disk(TransferenciaService::DISCO)->allFiles())->toHaveCount(1);
});

test('the same reference is allowed in another bank or another residential', function () {
    ($this->registrar)()->assertSessionHasNoErrors();
    ($this->registrar)(['banco' => 'Banco Atlántida'])->assertSessionHasNoErrors();

    $tesoreroAjeno = ($this->usuario)('tesorero', $this->otro);
    $casaAjena = ($this->vivienda)($this->otro, 'Z-01');

    ($this->registrar)(['vivienda_id' => $casaAjena->id], $tesoreroAjeno)->assertSessionHasNoErrors();

    expect(($this->transferencias)()->count())->toBe(3);
});

test('a rejected or voided transfer frees its reference', function (string $estado, array $datos) {
    ($this->registrar)()->assertSessionHasNoErrors();
    ($this->transferencias)()->update(['estado' => $estado, ...$datos]);

    ($this->registrar)()->assertSessionHasNoErrors();

    expect(($this->transferencias)()->where('estado', Pago::PENDIENTE_VERIFICACION)->count())->toBe(1);
})->with([
    'rechazada' => [Pago::RECHAZADO, ['motivo_rechazo' => 'No ingresó a la cuenta.']],
    'anulada' => [Pago::ANULADO, []],
]);

test('the database also rejects a repeated reference of the same bank', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    $copia = ($this->transferencias)()->sole()->replicate(['created_at', 'updated_at'])->fill(['banco' => 'BAC CREDOMATIC']);

    expect(fn () => $copia->save())->toThrow(QueryException::class);
});

test('the receipt can be an image or a PDF', function (string $tipo) {
    $archivo = match ($tipo) {
        'jpg' => UploadedFile::fake()->image('comprobante.jpg'),
        'png' => UploadedFile::fake()->image('comprobante.png'),
        'pdf' => ($this->pdf)(),
    };

    ($this->registrar)(['comprobante' => $archivo])->assertSessionHasNoErrors();

    $ruta = ($this->transferencias)()->sole()->comprobante_path;

    expect($ruta)->toStartWith("transferencias/{$this->residencial->id}/")
        ->and($ruta)->toEndWith(".{$tipo}");

    Storage::disk(TransferenciaService::DISCO)->assertExists($ruta);
})->with(['jpg', 'png', 'pdf']);

test('the receipt is required, must be an image or PDF and up to 5 MB', function (?callable $archivo, string $mensaje) {
    ($this->registrar)(['comprobante' => $archivo === null ? null : $archivo()])
        ->assertSessionHasErrors(['comprobante' => $mensaje]);

    expect(($this->transferencias)()->exists())->toBeFalse()
        ->and(Storage::disk(TransferenciaService::DISCO)->allFiles())->toBe([]);
})->with([
    'sin comprobante' => [null, 'Adjunta el comprobante de la transferencia.'],
    'texto' => [fn () => UploadedFile::fake()->create('nota.txt', 1, 'text/plain'), 'El comprobante debe ser una imagen JPG o PNG, o un PDF.'],
    'gif' => [fn () => UploadedFile::fake()->image('comprobante.gif'), 'El comprobante debe ser una imagen JPG o PNG, o un PDF.'],
    'más de 5 MB' => [fn () => UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf'), 'El comprobante no puede pasar de 5 MB.'],
]);

test('the transfer data is validated', function (array $cambios, string $campo, string $mensaje) {
    ($this->registrar)($cambios)->assertSessionHasErrors([$campo => $mensaje]);

    expect(($this->transferencias)()->exists())->toBeFalse();
})->with([
    'sin banco' => [['banco' => '   '], 'banco', 'Escribe el banco de la transferencia.'],
    'sin referencia' => [['numero_referencia' => ''], 'numero_referencia', 'Escribe el número de referencia.'],
    'sin cuenta receptora' => [['cuenta_receptora' => ''], 'cuenta_receptora', 'Escribe la cuenta que recibió la transferencia.'],
    'sin monto' => [['monto' => ''], 'monto', 'Escribe el monto transferido.'],
    'monto en cero' => [['monto' => '0'], 'monto', 'El monto debe ser mayor que cero.'],
    'monto con tres decimales' => [['monto' => '10.555'], 'monto', 'El monto admite hasta dos decimales.'],
    'sin fecha' => [['fecha_pago' => ''], 'fecha_pago', 'Indica la fecha de la transferencia.'],
    'fecha futura' => [['fecha_pago' => '2999-01-01'], 'fecha_pago', 'La fecha de la transferencia no puede ser posterior a hoy.'],
    'sin vivienda' => [['vivienda_id' => ''], 'vivienda_id', 'Selecciona una vivienda.'],
]);

test('only active houses of the residential can receive a transfer', function () {
    $inactiva = ($this->vivienda)($this->residencial, 'A-99', false);
    $ajena = ($this->vivienda)($this->otro, 'Z-01');

    ($this->registrar)(['vivienda_id' => $inactiva->id])->assertSessionHasErrors(['vivienda_id' => 'Selecciona una vivienda válida.']);
    ($this->registrar)(['vivienda_id' => $ajena->id])->assertSessionHasErrors(['vivienda_id' => 'Selecciona una vivienda válida.']);

    expect(($this->transferencias)()->exists())->toBeFalse();
});

test('the list and the detail show the pending transfer without a receipt number', function () {
    ($this->registrar)()->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    $this->get(route('finanzas.pagos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/pagos')
            ->where('pagos.data.0.estado', Pago::PENDIENTE_VERIFICACION)
            ->where('pagos.data.0.forma_pago', Pago::TRANSFERENCIA)
            ->where('pagos.data.0.numero_recibo', null)
            ->where('pagos.data.0.numero_referencia', 'REF-1001'));

    $this->get(route('finanzas.pagos.show', $pago))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/pago')
            ->where('pago.estado', Pago::PENDIENTE_VERIFICACION)
            ->where('pago.numero_recibo', null)
            ->where('pago.saldo_vivienda', '500.00')
            ->where('pago.cobrador', 'Tomás Tesorero')
            ->where('pago.transferencia', [
                'banco' => 'BAC Credomatic',
                'numero_referencia' => 'REF-1001',
                'cuenta_receptora' => '730-123456-7',
                'tiene_comprobante' => true,
                // Sin verificar todavía (HU-26)
                'verificado_por' => null,
                'verificado_en' => null,
                'motivo_rechazo' => null,
            ])
            // Pendiente no se anula: si no ingresó, se rechaza al verificarla
            ->where('pago.puede_solicitar', false));

    // Todavía no emite el comprobante de pago de la HU-20
    $this->get(route('finanzas.comprobantes.show', $pago))->assertNotFound();
});

test('the attached receipt is shown only to who can see the payments of its residential', function () {
    ($this->registrar)(['comprobante' => ($this->pdf)('mi-comprobante.pdf')])->assertSessionHasNoErrors();
    $pago = ($this->transferencias)()->sole();

    $respuesta = $this->get(route('finanzas.transferencias.comprobante', $pago))->assertOk();

    expect($respuesta->streamedContent())->toContain('%PDF-1.4')
        ->and($respuesta->headers->get('content-type'))->toContain('application/pdf');

    // Sin permiso de ver los cobros
    $this->actingAs(($this->usuario)('cobrador'))->get(route('finanzas.transferencias.comprobante', $pago))->assertForbidden();

    // De otro residencial: se deniega y queda en la bitácora
    $this->actingAs(($this->usuario)('tesorero', $this->otro))->get(route('finanzas.transferencias.comprobante', $pago))->assertForbidden();

    expect(Auditoria::query()->where('accion', 'acceso_denegado')->where('entidad_id', $pago->id)->exists())->toBeTrue();
});

test('a cash payment has no transfer receipt', function () {
    $efectivo = Pago::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'forma_pago' => Pago::EFECTIVO,
        'estado' => Pago::CONFIRMADO,
        'origen' => 'sistema',
        'monto' => '500.00',
        'fecha_pago' => '2026-11-14',
        'numero_recibo' => '1',
        'registrado_por' => $this->tesorero->id,
    ]);

    $this->actingAs($this->tesorero)->get(route('finanzas.transferencias.comprobante', $efectivo))->assertNotFound();
});

test('only who has the transfer permission can register one', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('finanzas.transferencias.create'))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();

    expect(($this->transferencias)()->exists())->toBeFalse();
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'fiscal', 'vocal', 'cobrador', 'guardia', 'residente']);

test('the transfer permission is separate from registering cash payments', function () {
    $cobrador = ($this->usuario)('cobrador');

    // El tesorero registra transferencias pero no cobra en efectivo
    $this->actingAs($this->tesorero)->get(route('finanzas.transferencias.create'))->assertOk();
    $this->get(route('finanzas.cobros.create'))->assertForbidden();

    // El cobrador cobra en efectivo pero no registra transferencias
    $this->actingAs($cobrador)->get(route('finanzas.cobros.create'))->assertOk();
    $this->get(route('finanzas.transferencias.create'))->assertForbidden();

    // Con solo el permiso de transferencias, vuelve al formulario para registrar otra
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);
    $this->actingAs($admin)->put(route('usuarios.permisos.update', ($this->rol)('cobrador')), ['permisos' => ['transferencias.registrar']]);

    ($this->registrar)([], $cobrador)->assertRedirect(route('finanzas.transferencias.create'));
    $this->get(route('finanzas.cobros.create'))->assertForbidden();
});

test('the permissions screen offers the transfers module with the register and verify actions', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    $this->actingAs($admin)->get(route('usuarios.permisos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('identidad/permisos')
            ->where('modulos', fn ($modulos) => collect($modulos)->firstWhere('id', 'transferencias') === [
                'id' => 'transferencias',
                'etiqueta' => 'Transferencias',
                // La verificación llegó con la HU-26
                'acciones' => ['registrar', 'verificar'],
            ])
            ->where('roles', fn ($roles) => in_array('transferencias.registrar', collect($roles)->firstWhere('nombre', 'Tesorero')['permisos'], true)));

    // Una acción que el módulo no tiene se rechaza
    $this->put(route('usuarios.permisos.update', ($this->rol)('tesorero')), ['permisos' => ['transferencias.aprobar']])
        ->assertSessionHasErrors('permisos.0');

    expect(app(PermisoService::class)->puede($admin, 'transferencias', 'registrar'))->toBeTrue()
        ->and(app(PermisoService::class)->puede($admin, 'transferencias', 'verificar'))->toBeTrue();
});

test('the sidebar shows the register option to the treasurer', function () {
    $this->actingAs($this->tesorero)->get(route('finanzas.pagos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('transferencias.registrar')));
});

test('existing residentials give the treasurer the transfer permission', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'transferencias')->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_16_100001_asignar_registro_de_transferencias.php');
    $migracion->up();
    $migracion->up();

    $permisos = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('residencial_id', $antiguo->id)
        ->where('modulo', 'transferencias')
        ->get(['roles.codigo', 'accion'])
        ->map(fn ($fila) => "{$fila->codigo}.{$fila->accion}")
        ->all();

    expect($permisos)->toBe(['tesorero.registrar']);
});

test('a pending transfer is not reported as a cash collection nor adds to the report totals', function () {
    [$cobradora] = ($this->cobradoraConCaja)();

    $hoy = TransferenciaService::hoy();

    $this->actingAs($this->tesorero)
        ->get(route('reportes.cobros.index', ['desde' => $hoy, 'hasta' => $hoy]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reportes/cobros')
            // Solo el cobro en efectivo; la transferencia de L 800.00 no aparece
            ->where('reporte.subtotales', [
                ['cobrador_id' => $cobradora->id, 'cobrador' => 'Marta Cobradora', 'cobros' => 1, 'total' => '500.00'],
            ])
            ->where('reporte.total_cobros', 1)
            ->where('reporte.total', '500.00')
            ->where('reporte.anulados', 0)
            ->where('reporte.monto_anulado', '0.00'));

    // Tampoco al filtrar por la cobradora que la registró
    $this->get(route('reportes.cobros.index', ['desde' => $hoy, 'hasta' => $hoy, 'cobrador' => $cobradora->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporte.total_cobros', 1)
            ->where('reporte.total', '500.00'));
});

test('a pending transfer does not add to the registered total nor to the box closing', function () {
    [$cobradora, $caja] = ($this->cobradoraConCaja)();

    // La transferencia no entra en la caja de quien la registró ni abre otra
    expect(($this->transferencias)()->sole()->caja_id)->toBeNull()
        ->and(Caja::query()->withoutGlobalScopes()->count())->toBe(1);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.cajas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('abiertas', 1)
            ->where('abiertas.0.cobrador', 'Marta Cobradora')
            ->where('abiertas.0.cobros', 1)
            ->where('abiertas.0.total_registrado', '500.00'));

    $this->get(route('finanzas.cajas.show', $caja))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('caja.cobros', 1)
            ->where('caja.cobros.0.numero_recibo', '1')
            ->where('caja.total_registrado', '500.00'));

    // Entregar L 500.00 cuadra la caja: no hace falta justificar una diferencia
    $this->post(route('finanzas.cajas.cerrar', $caja), ['monto_entregado' => '500'])
        ->assertSessionHasNoErrors();

    $caja->refresh();

    expect($caja->estado)->toBe(Caja::CERRADA)
        ->and($caja->total_registrado)->toBe('500.00')
        ->and($caja->diferencia)->toBe('0.00')
        ->and(($this->transferencias)()->sole()->estado)->toBe(Pago::PENDIENTE_VERIFICACION);
});
