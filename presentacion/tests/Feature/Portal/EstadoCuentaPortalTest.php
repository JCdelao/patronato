<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Services\CobroService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    Storage::fake(TransferenciaService::DISCO);
    Notification::fake();

    // 15:00 en UTC son las 09:00 en Honduras
    Carbon::setTestNow('2026-11-20 15:00:00');

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
    ]);

    $this->cobrador = ($this->usuario)('cobrador');

    $this->vivienda = fn (string $codigo, bool $activa = true, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)('A-01');
    $this->vecina = ($this->vivienda)('A-02');

    // Residente vinculado a la cuenta, con un vínculo vigente por cada vivienda
    $this->residente = function (array $viviendas, array $residente = [], ?Residencial $residencial = null): User {
        $residencial ??= $this->residencial;
        $cuenta = ($this->usuario)(Rol::RESIDENTE, $residencial);

        $registro = Residente::query()->create([
            'residencial_id' => $residencial->id,
            'user_id' => $cuenta->id,
            'nombres' => 'Rosa',
            'apellidos' => 'Vecina',
            'activo' => true,
            ...$residente,
        ]);

        foreach ($viviendas as $vivienda) {
            ViviendaResidente::query()->create([
                'residencial_id' => $residencial->id,
                'vivienda_id' => $vivienda->id,
                'residente_id' => $registro->id,
                'tipo' => 'inquilino',
                'es_responsable_pago' => false,
                'fecha_inicio' => '2025-01-01',
            ]);
        }

        return $cuenta;
    };

    $this->rosa = ($this->residente)([$this->casa]);

    $this->concepto = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Cuota mensual',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->cargo = fn (Vivienda $vivienda, string $periodo, string $monto = '500.00'): int => (int) DB::table('cargos')->insertGetId([
        'residencial_id' => $vivienda->residencial_id,
        'vivienda_id' => $vivienda->id,
        'concepto_id' => $this->concepto,
        'periodo' => $periodo,
        'monto' => $monto,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Septiembre pagado, octubre con un abono de L 200 y noviembre sin pagar
    DB::table('configuracion_residencial')
        ->updateOrInsert(['residencial_id' => $this->residencial->id], ['permite_abono_parcial' => true, 'updated_at' => now()]);

    $septiembre = ($this->cargo)($this->casa, '2026-09-01');
    $octubre = ($this->cargo)($this->casa, '2026-10-01');
    ($this->cargo)($this->casa, '2026-11-01');

    Carbon::setTestNow('2026-09-15 15:00:00');
    app(CobroService::class)->registrarEfectivo($this->cobrador, $this->casa->id, '500', [$septiembre]);
    Carbon::setTestNow('2026-10-15 15:00:00');
    app(CobroService::class)->registrarEfectivo($this->cobrador, $this->casa->id, '200', [$octubre]);
    Carbon::setTestNow('2026-11-20 15:00:00');

    // Transferencia que la residente reporta desde el portal
    $this->reportar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->rosa)
        ->post(route('portal.transferencias.store'), [
            'vivienda_id' => $this->casa->id,
            'monto' => '300',
            'fecha_pago' => TransferenciaService::hoy(),
            'banco' => 'Banco Atlántida',
            'numero_referencia' => 'ATL-5201',
            'comprobante' => UploadedFile::fake()->createWithContent('comprobante.pdf', "%PDF-1.4\n%comprobante\n"),
            ...$cambios,
        ]);

    $this->ver = fn (?Vivienda $vivienda = null, ?User $usuario = null, array $consulta = []) => $this->actingAs($usuario ?? $this->rosa)
        ->get(route('portal.estado-cuenta.show', ['vivienda' => ($vivienda ?? $this->casa)->id, ...$consulta]));

    // Generador que guarda los datos en lugar de dibujar el PDF
    $this->espia = function (): object {
        $espia = new class implements GeneradorPdf
        {
            /** @var array<string, mixed> */
            public array $datos = [];

            public function generar(string $vista, array $datos): string
            {
                $this->datos = $datos;

                return '%PDF-espia';
            }
        };

        $this->app->instance(GeneradorPdf::class, $espia);

        return $espia;
    };

    $this->descargar = fn (?Vivienda $vivienda = null, ?User $usuario = null, array $rango = []) => $this->actingAs($usuario ?? $this->rosa)
        ->get(route('portal.estado-cuenta.pdf', [
            'vivienda' => ($vivienda ?? $this->casa)->id,
            'desde' => '2026-09-01',
            'hasta' => '2026-11-20',
            ...$rango,
        ]));
});

afterEach(fn () => Carbon::setTestNow());

// Consulta del estado de cuenta

test('the resident sees the balance, the payment history and the pending months of their house', function () {
    ($this->ver)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/estado-cuenta')
            ->where('estado.vivienda.codigo', 'A-01')
            ->where('estado.totales.saldo', '800.00')
            ->has('estado.movimientos', 5)
            ->where('estado.movimientos.1.tipo', 'pago')
            ->where('estado.movimientos.3.tipo', 'abono')
            ->has('estado.meses_pendientes', 2)
            ->where('estado.meses_pendientes.0.periodo', '2026-10-01')
            ->where('estado.meses_pendientes.0.estado', 'parcial')
            ->where('estado.meses_pendientes.0.pendiente', '300.00')
            ->where('estado.meses_pendientes.1.periodo', '2026-11-01')
            ->where('estado.meses_pendientes.1.pendiente', '500.00')
            ->where('estado.mora.estado', fn (string $estado) => in_array($estado, ['al_dia', 'en_mora'], true))
            ->where('rango.hasta', '2026-11-20'));
});

test('a house without pending months shows none', function () {
    $casa = ($this->vivienda)('B-01');
    $cuenta = ($this->residente)([$casa]);

    ($this->ver)($casa, $cuenta)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('estado.totales.saldo', '0.00')
            ->has('estado.movimientos', 0)
            ->has('estado.meses_pendientes', 0));
});

test('the pending months come from the whole history even when a year is chosen', function () {
    ($this->cargo)($this->casa, '2025-12-01');

    ($this->ver)(null, null, ['anio' => 2026])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('estado.anio', 2026)
            ->where('estado.saldo_inicial', '500.00')
            ->has('estado.movimientos', 5)
            ->has('estado.meses_pendientes', 3)
            ->where('estado.meses_pendientes.0.periodo', '2025-12-01'));
});

test('the portal shows the same balance as the treasury statement', function () {
    $tesorero = ($this->usuario)('tesorero');

    $oficial = null;

    $this->actingAs($tesorero)
        ->get(route('finanzas.estado-cuenta.show', $this->casa->id))
        ->assertInertia(function (AssertableInertia $page) use (&$oficial) {
            $oficial = $page->toArray()['props']['estado']['totales']['saldo'];
        });

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page->where('estado.totales.saldo', $oficial));
});

// Criterio 1: solo ve la información de su vivienda o viviendas

test('a resident with one house is taken straight to its statement', function () {
    $this->actingAs($this->rosa)
        ->get(route('portal.estado-cuenta.index'))
        ->assertRedirect(route('portal.estado-cuenta.show', $this->casa->id));
});

test('a resident with several houses chooses among only their own', function () {
    $casa = ($this->vivienda)('C-01');
    $segunda = ($this->vivienda)('C-02', false);
    $cuenta = ($this->residente)([$casa, $segunda]);

    $this->actingAs($cuenta)
        ->get(route('portal.estado-cuenta.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/estado-cuenta')
            ->where('estado', null)
            ->where('viviendas', [
                ['id' => $casa->id, 'codigo' => 'C-01', 'activa' => true],
                ['id' => $segunda->id, 'codigo' => 'C-02', 'activa' => false],
            ]));

    ($this->ver)($segunda, $cuenta)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('estado.vivienda.codigo', 'C-02')
            ->has('viviendas', 2));
});

test('a resident cannot see a house of the residencial where they do not live', function () {
    ($this->ver)($this->vecina)->assertForbidden();
    ($this->descargar)($this->vecina)->assertForbidden();
});

test('a house of another residencial is rejected', function () {
    $ajena = ($this->vivienda)('A-01', true, $this->otro);

    ($this->ver)($ajena)->assertForbidden();
    ($this->descargar)($ajena)->assertForbidden();
});

test('a resident whose link ended no longer sees that house', function () {
    ViviendaResidente::query()->withoutGlobalScopes()
        ->where('residente_id', Residente::query()->withoutGlobalScopes()->where('user_id', $this->rosa->id)->value('id'))
        ->update(['fecha_fin' => '2026-11-01']);

    ($this->ver)()->assertForbidden();

    $this->actingAs($this->rosa)
        ->get(route('portal.estado-cuenta.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas', []));
});

test('a resident without a linked active resident cannot see any statement', function (string $caso) {
    $cuenta = match ($caso) {
        'sin residente' => ($this->usuario)(Rol::RESIDENTE),
        'residente inactivo' => ($this->residente)([$this->casa], ['activo' => false]),
    };

    $this->actingAs($cuenta)->get(route('portal.estado-cuenta.index'))->assertForbidden();
    ($this->ver)(null, $cuenta)->assertForbidden();
    ($this->descargar)(null, $cuenta)->assertForbidden();
})->with(['sin residente', 'residente inactivo']);

test('other roles cannot use the portal statement', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('portal.estado-cuenta.index'))->assertForbidden();
    ($this->ver)(null, $usuario)->assertForbidden();
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero', 'fiscal', 'vocal', 'cobrador', 'guardia']);

test('without the portal permission the resident cannot see the statement', function () {
    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->put(route('usuarios.permisos.update', ($this->rol)(Rol::RESIDENTE)), ['permisos' => []])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rosa)->get(route('portal.estado-cuenta.index'))->assertForbidden();
    ($this->ver)()->assertForbidden();
    ($this->descargar)()->assertForbidden();
});

test('guests are sent to the login', function () {
    $this->get(route('portal.estado-cuenta.show', $this->casa->id))->assertRedirect(route('login'));
});

// Criterio 2: descarga su estado de cuenta en PDF

test('the resident downloads the PDF of their house for the chosen range', function () {
    $espia = ($this->espia)();

    ($this->descargar)()
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="estado-de-cuenta-A-01-2026-09-01-2026-11-20.pdf"');

    expect($espia->datos['vivienda']['codigo'])->toBe('A-01')
        ->and($espia->datos['saldo_final'])->toBe('800.00')
        ->and($espia->datos['movimientos'])->toHaveCount(5);
});

test('each download is audited with the resident account', function () {
    ($this->espia)();

    ($this->descargar)()->assertOk();

    $registro = Auditoria::query()->withoutGlobalScopes()->where('accion', 'estado_cuenta_pdf_generado')->sole();

    expect($registro->user_id)->toBe($this->rosa->id)
        ->and($registro->valor_nuevo)->toMatchArray(['vivienda_id' => $this->casa->id, 'desde' => '2026-09-01', 'hasta' => '2026-11-20']);
});

test('the PDF range is validated', function (array $rango, string $campo) {
    ($this->espia)();

    ($this->descargar)(null, null, $rango)->assertSessionHasErrors($campo);
})->with([
    'sin fecha inicial' => [['desde' => ''], 'desde'],
    'inicio después del final' => [['desde' => '2026-11-01', 'hasta' => '2026-10-01'], 'desde'],
    'final en el futuro' => [['hasta' => '2026-11-21'], 'hasta'],
]);

test('the resident downloads the PDF without the treasury PDF permission', function () {
    ($this->espia)();

    $this->actingAs($this->rosa)
        ->get(route('finanzas.estado-cuenta-pdf.index'))
        ->assertForbidden();

    ($this->descargar)()->assertOk();
});

// Criterio 3: ve sus pagos pendientes de verificación con su estado actual

test('the resident sees their payments pending verification with their status', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    ($this->ver)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('estado.totales.saldo', '800.00')
            ->where('estado.totales.por_verificar', '300.00')
            ->has('estado.por_verificar', 1)
            ->where('estado.por_verificar.0.monto', '300.00')
            ->where('estado.por_verificar.0.estado', Pago::REPORTADO_POR_VECINO)
            ->where('estado.por_verificar.0.referencia', 'Banco Atlántida · ref. ATL-5201'));
});

test('a transfer pending verification registered by the treasury also appears with its status', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    Pago::query()->withoutGlobalScopes()->where('numero_referencia', 'ATL-5201')->update(['estado' => Pago::PENDIENTE_VERIFICACION]);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('estado.por_verificar.0.estado', Pago::PENDIENTE_VERIFICACION));
});

test('a rejected transfer leaves the pending list and shows its reason', function () {
    ($this->reportar)()->assertSessionHasNoErrors();

    Pago::query()->withoutGlobalScopes()->where('numero_referencia', 'ATL-5201')->update([
        'estado' => Pago::RECHAZADO,
        'motivo_rechazo' => 'La referencia no aparece en el banco',
    ]);

    ($this->ver)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('estado.por_verificar', 0)
            ->has('estado.sin_efecto', 1)
            ->where('estado.sin_efecto.0.estado', Pago::RECHAZADO)
            ->where('estado.sin_efecto.0.motivo', 'La referencia no aparece en el banco')
            ->where('estado.totales.saldo', '800.00'));
});

test('the resident does not see the pending payments of other houses', function () {
    $cuenta = ($this->residente)([$this->vecina]);
    ($this->cargo)($this->vecina, '2026-11-01');

    ($this->reportar)(['vivienda_id' => $this->vecina->id, 'numero_referencia' => 'ATL-9999'], $cuenta)->assertSessionHasNoErrors();

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page->has('estado.por_verificar', 0));
});

test('consulting the statement does not change any financial data', function () {
    $antes = [DB::table('cargos')->count(), DB::table('pagos')->count(), DB::table('pago_aplicaciones')->count(), Auditoria::query()->withoutGlobalScopes()->count()];

    ($this->ver)()->assertOk();
    ($this->ver)(null, null, ['anio' => 2026])->assertOk();

    expect([DB::table('cargos')->count(), DB::table('pagos')->count(), DB::table('pago_aplicaciones')->count(), Auditoria::query()->withoutGlobalScopes()->count()])->toBe($antes);
});

// Datos que recibe el residente

test('the portal sends only the data the resident needs, without staff names nor treasury details', function () {
    $tesorero = User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('tesorero'),
        'name' => 'Tomás Tesorero',
    ]);
    $this->cobrador->update(['name' => 'Carlos Cobrador']);

    DB::table('ajustes')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'tipo' => 'correccion',
        'monto' => '-100.00',
        'fecha' => '2026-10-20',
        'motivo' => 'Corrección del recargo de octubre',
        'autorizado_por' => $tesorero->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ($this->reportar)()->assertSessionHasNoErrors();
    ($this->reportar)(['numero_referencia' => 'ATL-5202'])->assertSessionHasNoErrors();

    Pago::query()->withoutGlobalScopes()->where('numero_referencia', 'ATL-5202')->update([
        'estado' => Pago::RECHAZADO,
        'motivo_rechazo' => 'La referencia no aparece en el banco',
        'verificado_por' => $tesorero->id,
    ]);

    $respuesta = ($this->ver)()->assertOk();

    $respuesta->assertInertia(fn (AssertableInertia $page) => $page
        ->where('estado.vivienda', ['id' => $this->casa->id, 'codigo' => 'A-01', 'activa' => true])
        ->where('estado.totales', ['saldo' => '700.00', 'por_verificar' => '300.00'])
        ->missing('estado.cuadra')
        ->has('estado.mora', 3)
        ->has('estado', 10)
        ->where('estado.movimientos.1.forma_pago', 'efectivo')
        ->missing('estado.movimientos.1.responsable')
        ->where('estado.movimientos.1.meses', [['periodo' => '2026-09-01']])
        ->where('estado.movimientos.4.motivo', 'Corrección del recargo de octubre')
        ->missing('estado.movimientos.4.autorizado_por')
        ->where('estado.por_verificar.0', [
            'id' => Pago::query()->withoutGlobalScopes()->where('numero_referencia', 'ATL-5201')->value('id'),
            'fecha_pago' => '2026-11-20',
            'monto' => '300.00',
            'forma_pago' => Pago::TRANSFERENCIA,
            'estado' => Pago::REPORTADO_POR_VECINO,
            'referencia' => 'Banco Atlántida · ref. ATL-5201',
        ])
        ->where('estado.sin_efecto.0.motivo', 'La referencia no aparece en el banco')
        ->missing('estado.sin_efecto.0.responsable'));

    $estado = json_encode($respuesta->viewData('page')['props']['estado'], JSON_UNESCAPED_UNICODE);

    expect($estado)->not->toContain('Carlos Cobrador')
        ->and($estado)->not->toContain('Tomás Tesorero')
        ->and($estado)->not->toContain('dias_sin_verificar')
        ->and($estado)->not->toContain('responsable');
});

test('a failed PDF download returns to the statement with the error of each field', function () {
    ($this->espia)();

    $pagina = route('portal.estado-cuenta.show', $this->casa->id);

    $this->actingAs($this->rosa)
        ->from($pagina)
        ->get(route('portal.estado-cuenta.pdf', ['vivienda' => $this->casa->id, 'desde' => '2026-11-01', 'hasta' => '2026-11-21']))
        ->assertRedirect($pagina);

    ($this->ver)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('errors.hasta', 'La fecha final no puede ser posterior a hoy.')
            ->missing('errors.desde'));

    $this->actingAs($this->rosa)
        ->from($pagina)
        ->get(route('portal.estado-cuenta.pdf', ['vivienda' => $this->casa->id, 'desde' => '2026-11-10', 'hasta' => '2026-11-01']))
        ->assertRedirect($pagina);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('errors.desde', 'La fecha inicial no puede ser posterior a la final.')
        ->missing('errors.hasta'));
});
