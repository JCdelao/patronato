<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Egreso;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Storage::fake('local');

    // 9:00 en Honduras
    $this->travelTo('2026-11-15 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null, array $datos = []): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
        ...$datos,
    ]);

    $this->tesorero = ($this->usuario)('tesorero', null, ['name' => 'Tomás Tesorero']);

    $this->categoria = fn (string $nombre = 'Vigilancia', ?Residencial $residencial = null): int => (int) CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('tipo', CategoriaFinanciera::EGRESO)
        ->where('nombre', $nombre)
        ->value('id');

    $this->datos = fn (array $cambios = []): array => [
        'fecha' => '2026-11-14',
        'concepto' => 'Servicio de vigilancia de noviembre',
        'categoria_id' => ($this->categoria)(),
        'proveedor' => 'Seguridad Total S. de R. L.',
        'monto' => '15000.50',
        'forma_pago' => 'transferencia',
        'numero_comprobante' => 'F-001-0045',
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->tesorero)
        ->post(route('finanzas.egresos.store'), ($this->datos)($cambios));

    $this->egreso = fn (?Residencial $residencial = null, array $datos = []): Egreso => Egreso::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'categoria_id' => ($this->categoria)('Vigilancia', $residencial),
        'fecha' => '2026-11-10',
        'concepto' => 'Pago de vigilancia',
        'monto' => '8000.00',
        'numero_comprobante' => 'C-100',
        'estado' => Egreso::VIGENTE,
        'registrado_por' => $this->tesorero->id,
        ...$datos,
    ]);

    $this->anular = fn (Egreso $egreso, ?User $usuario = null, string $motivo = 'La factura se registró dos veces.') => $this->actingAs($usuario ?? $this->tesorero)
        ->post(route('finanzas.egresos.anular', $egreso), ['motivo' => $motivo]);
});

test('a new residential starts with the initial expense categories', function () {
    $categorias = CategoriaFinanciera::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', $this->residencial->id)
        ->where('tipo', CategoriaFinanciera::EGRESO)
        ->orderBy('id')
        ->pluck('nombre')
        ->all();

    expect($categorias)->toBe(CategoriaFinancieraService::EGRESOS_PREDETERMINADOS);
});

test('the treasurer registers an expense with all its fields and the invoice', function () {
    $factura = UploadedFile::fake()->create('factura.pdf', 300, 'application/pdf');

    $respuesta = ($this->registrar)(['factura' => $factura])->assertSessionHasNoErrors();

    $egreso = Egreso::query()->sole();

    $respuesta->assertRedirect(route('finanzas.egresos.show', $egreso));

    expect($egreso->residencial_id)->toBe($this->residencial->id)
        ->and($egreso->fecha->toDateString())->toBe('2026-11-14')
        ->and($egreso->concepto)->toBe('Servicio de vigilancia de noviembre')
        ->and($egreso->categoria_id)->toBe(($this->categoria)())
        ->and($egreso->proveedor)->toBe('Seguridad Total S. de R. L.')
        ->and($egreso->monto)->toBe('15000.50')
        ->and($egreso->forma_pago)->toBe('transferencia')
        ->and($egreso->numero_comprobante)->toBe('F-001-0045')
        ->and($egreso->estado)->toBe(Egreso::VIGENTE)
        ->and($egreso->registrado_por)->toBe($this->tesorero->id)
        ->and($egreso->factura_path)->toStartWith("facturas/{$this->residencial->id}/");

    Storage::disk('local')->assertExists($egreso->factura_path);

    $auditoria = Auditoria::query()->where('accion', 'egreso_registrado')->sole();

    expect($auditoria->modulo)->toBe('tesoreria')
        ->and($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->entidad_id)->toBe($egreso->id)
        ->and($auditoria->valor_nuevo['monto'])->toBe('15000.50')
        ->and($auditoria->valor_nuevo['factura'])->toBeTrue();
});

test('an expense can be registered without supplier, payment method or invoice', function () {
    ($this->registrar)(['proveedor' => '', 'forma_pago' => ''])->assertSessionHasNoErrors();

    $egreso = Egreso::query()->sole();

    expect($egreso->proveedor)->toBeNull()
        ->and($egreso->forma_pago)->toBeNull()
        ->and($egreso->factura_path)->toBeNull();
});

test('date, category, amount and receipt number are required', function (string $campo) {
    ($this->registrar)([$campo => ''])->assertSessionHasErrors($campo);

    expect(Egreso::query()->count())->toBe(0);
})->with(['fecha', 'categoria_id', 'monto', 'numero_comprobante', 'concepto']);

test('invalid values are rejected', function (string $campo, mixed $valor) {
    ($this->registrar)([$campo => $valor])->assertSessionHasErrors($campo);

    expect(Egreso::query()->count())->toBe(0);
})->with([
    'zero amount' => ['monto', '0'],
    'negative amount' => ['monto', '-50'],
    'three decimals' => ['monto', '10.555'],
    'future date' => ['fecha', '2026-11-16'],
    'unknown payment method' => ['forma_pago', 'tarjeta'],
]);

test('the date of today in Honduras is accepted even when it is already tomorrow in UTC', function () {
    // 23:30 del 15 en Honduras: ya es 16 en UTC
    $this->travelTo('2026-11-16 05:30:00');

    ($this->registrar)(['fecha' => '2026-11-15'])->assertSessionHasNoErrors();
    ($this->registrar)(['fecha' => '2026-11-16'])->assertSessionHasErrors('fecha');

    expect(Egreso::query()->count())->toBe(1);
});

test('the invoice must be a pdf, jpg or png of up to 5 MB', function (UploadedFile $factura) {
    ($this->registrar)(['factura' => $factura])->assertSessionHasErrors('factura');

    expect(Egreso::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'other format' => fn () => UploadedFile::fake()->create('factura.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    'too large' => fn () => UploadedFile::fake()->create('factura.pdf', 5121, 'application/pdf'),
]);

test('an image invoice is accepted', function () {
    ($this->registrar)(['factura' => UploadedFile::fake()->image('factura.jpg')])->assertSessionHasNoErrors();

    Storage::disk('local')->assertExists(Egreso::query()->sole()->factura_path);
});

test('only an active expense category of the same residential can be used', function (string $caso) {
    $categoria = match ($caso) {
        'another residential' => ($this->categoria)('Vigilancia', $this->otro),
        'income category' => CategoriaFinanciera::query()->create([
            'residencial_id' => $this->residencial->id,
            'tipo' => CategoriaFinanciera::INGRESO,
            'nombre' => 'Alquiler del salón',
        ])->id,
        'inactive category' => CategoriaFinanciera::query()->create([
            'residencial_id' => $this->residencial->id,
            'tipo' => CategoriaFinanciera::EGRESO,
            'nombre' => 'Categoría vieja',
            'activa' => false,
        ])->id,
    };

    ($this->registrar)(['categoria_id' => $categoria])->assertSessionHasErrors('categoria_id');

    expect(Egreso::query()->count())->toBe(0);
})->with(['another residential', 'income category', 'inactive category']);

test('the new expense form lists only the active expense categories of the residential', function () {
    CategoriaFinanciera::query()->create([
        'residencial_id' => $this->residencial->id,
        'tipo' => CategoriaFinanciera::EGRESO,
        'nombre' => 'Categoría vieja',
        'activa' => false,
    ]);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.egresos.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/egreso-nuevo')
            ->has('categorias', count(CategoriaFinancieraService::EGRESOS_PREDETERMINADOS))
            ->where('formas_pago', Egreso::FORMAS_PAGO)
            ->where('hoy', '2026-11-15'));
});

test('the treasurer adds a new category and can use it right away', function () {
    $this->actingAs($this->tesorero)
        ->from(route('finanzas.egresos.create'))
        ->post(route('finanzas.categorias-egreso.store'), ['nombre' => '  Eventos comunitarios '])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.egresos.create'));

    $nueva = ($this->categoria)('Eventos comunitarios');

    expect($nueva)->toBeGreaterThan(0);

    ($this->registrar)(['categoria_id' => $nueva])->assertSessionHasNoErrors();

    expect(Egreso::query()->sole()->categoria_id)->toBe($nueva);

    $auditoria = Auditoria::query()->where('accion', 'categoria_creada')->sole();

    expect($auditoria->modulo)->toBe('tesoreria')
        ->and($auditoria->entidad_id)->toBe($nueva)
        ->and($auditoria->valor_nuevo)->toBe(['tipo' => 'egreso', 'nombre' => 'Eventos comunitarios']);
});

test('a category name cannot be repeated regardless of case', function () {
    $this->actingAs($this->tesorero)
        ->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'VIGILANCIA'])
        ->assertSessionHasErrors(['nombre' => 'Ya existe una categoría con ese nombre.']);

    // Otro residencial sí puede usar el mismo nombre que este
    $this->actingAs(($this->usuario)('tesorero', $this->otro))
        ->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'Eventos comunitarios'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->tesorero)
        ->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'Eventos comunitarios'])
        ->assertSessionHasNoErrors();
});

test('the treasurer voids an expense with a reason that stays in the audit log', function () {
    $egreso = ($this->egreso)();

    ($this->anular)($egreso)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('finanzas.egresos.show', $egreso));

    $egreso->refresh();

    expect($egreso->estado)->toBe(Egreso::ANULADO)
        ->and($egreso->motivo_anulacion)->toBe('La factura se registró dos veces.')
        ->and(Egreso::query()->count())->toBe(1);

    $auditoria = Auditoria::query()->where('accion', 'egreso_anulado')->sole();

    expect($auditoria->modulo)->toBe('tesoreria')
        ->and($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->entidad_id)->toBe($egreso->id)
        ->and($auditoria->motivo)->toBe('La factura se registró dos veces.')
        ->and($auditoria->valor_anterior)->toBe(['estado' => 'vigente'])
        ->and($auditoria->valor_nuevo)->toBe(['estado' => 'anulado']);
});

test('an expense cannot be voided twice or without a reason', function () {
    $egreso = ($this->egreso)();

    ($this->anular)($egreso, null, '')->assertSessionHasErrors('motivo');
    ($this->anular)($egreso, null, '   corto   ')->assertSessionHasErrors('motivo');

    expect($egreso->fresh()->estado)->toBe(Egreso::VIGENTE);

    ($this->anular)($egreso)->assertSessionHasNoErrors();
    ($this->anular)($egreso, null, 'Otro motivo para anular.')->assertSessionHasErrors(['motivo' => 'El egreso ya está anulado.']);

    expect($egreso->fresh()->motivo_anulacion)->toBe('La factura se registró dos veces.')
        ->and(Auditoria::query()->where('accion', 'egreso_anulado')->count())->toBe(1);
});

test('an expense cannot be deleted', function () {
    $egreso = ($this->egreso)();

    $this->actingAs($this->tesorero)
        ->delete(route('finanzas.egresos.show', $egreso))
        ->assertMethodNotAllowed();

    expect($egreso->fresh())->not->toBeNull();
});

test('the list shows the expenses of the residential, including the voided ones, and searches them', function () {
    ($this->egreso)(null, ['concepto' => 'Compra de bombillos', 'proveedor' => 'Ferretería Ávila', 'fecha' => '2026-11-12']);
    ($this->egreso)(null, ['concepto' => 'Pago de agua', 'estado' => Egreso::ANULADO, 'motivo_anulacion' => 'Duplicado del recibo.']);
    ($this->egreso)($this->otro, ['concepto' => 'Gasto ajeno']);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.egresos.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/egresos')
            ->has('egresos.data', 2)
            ->where('egresos.data.0.concepto', 'Compra de bombillos')
            ->where('egresos.data.0.categoria', 'Vigilancia')
            ->where('egresos.data.1.estado', 'anulado'));

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.egresos.index', ['buscar' => 'avila']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('egresos.data', 1)
            ->where('egresos.data.0.concepto', 'Compra de bombillos'));
});

test('the detail shows the expense and who voided it and why', function () {
    $egreso = ($this->egreso)(null, ['forma_pago' => 'cheque']);
    ($this->anular)($egreso);

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.egresos.show', $egreso))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('finanzas/egreso')
            ->where('egreso.monto', '8000.00')
            ->where('egreso.forma_pago', 'cheque')
            ->where('egreso.registrado_por', 'Tomás Tesorero')
            ->where('egreso.anulacion.motivo', 'La factura se registró dos veces.')
            ->where('egreso.anulacion.anulado_por', 'Tomás Tesorero')
            ->where('egreso.puede_anular', false));
});

test('the invoice opens only from the system and only when it exists', function () {
    ($this->registrar)(['factura' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf')]);
    $conFactura = Egreso::query()->sole();
    $sinFactura = ($this->egreso)();

    $this->actingAs(($this->usuario)('fiscal'))->get(route('finanzas.egresos.factura', $conFactura))->assertOk();
    $this->actingAs($this->tesorero)->get(route('finanzas.egresos.factura', $sinFactura))->assertNotFound();
    $this->actingAs(($this->usuario)('cobrador'))->get(route('finanzas.egresos.factura', $conFactura))->assertForbidden();

    auth()->logout();
    $this->get(route('finanzas.egresos.factura', $conFactura))->assertRedirect(route('login'));
});

test('the president and the auditor see the expenses but cannot register or void them', function (string $rol) {
    $egreso = ($this->egreso)();
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('finanzas.egresos.index'))->assertOk();
    $this->actingAs($usuario)
        ->get(route('finanzas.egresos.show', $egreso))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('egreso.puede_anular', false));

    $this->actingAs($usuario)->get(route('finanzas.egresos.create'))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();
    $this->actingAs($usuario)->post(route('finanzas.categorias-egreso.store'), ['nombre' => 'Nueva'])->assertForbidden();
    ($this->anular)($egreso, $usuario)->assertForbidden();

    expect(Egreso::query()->count())->toBe(1)
        ->and($egreso->fresh()->estado)->toBe(Egreso::VIGENTE);
})->with(['presidente', 'fiscal']);

test('other roles cannot see the expenses', function (string $rol) {
    $egreso = ($this->egreso)();
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('finanzas.egresos.index'))->assertForbidden();
    $this->actingAs($usuario)->get(route('finanzas.egresos.show', $egreso))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();
    ($this->anular)($egreso, $usuario)->assertForbidden();
})->with(['secretario', 'vocal', 'cobrador']);

test('the treasurer sees the expenses option in the menu and the voiding form', function () {
    $egreso = ($this->egreso)();

    $this->actingAs($this->tesorero)
        ->get(route('finanzas.egresos.show', $egreso))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('egreso.puede_anular', true)
            ->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('tesoreria.ver')));
});

test('the expenses of another residential are denied and audited', function () {
    $egreso = ($this->egreso)();
    $ajeno = ($this->usuario)('tesorero', $this->otro);

    $this->actingAs($ajeno)
        ->get(route('finanzas.egresos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('egresos.data', 0));

    $this->actingAs($ajeno)->get(route('finanzas.egresos.show', $egreso))->assertForbidden();
    $this->actingAs($ajeno)->get(route('finanzas.egresos.factura', $egreso))->assertForbidden();
    ($this->anular)($egreso, $ajeno)->assertForbidden();

    expect($egreso->fresh()->estado)->toBe(Egreso::VIGENTE)
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(3);
});

test('existing residentials get the expense permissions and the initial categories', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'tesoreria')->delete();
    DB::table('categorias_financieras')->where('residencial_id', $antiguo->id)->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_15_200001_asignar_tesoreria_y_categorias_de_egreso.php');
    $migracion->up();
    $migracion->up();

    $permisos = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('residencial_id', $antiguo->id)
        ->where('modulo', 'tesoreria')
        ->orderBy('roles.codigo')
        ->orderBy('accion')
        ->get(['roles.codigo', 'accion'])
        ->map(fn ($fila) => "{$fila->codigo}.{$fila->accion}")
        ->all();

    $categorias = DB::table('categorias_financieras')
        ->where('residencial_id', $antiguo->id)
        ->where('tipo', 'egreso')
        ->orderBy('id')
        ->pluck('nombre')
        ->all();

    expect($permisos)->toBe(['fiscal.ver', 'presidente.ver', 'tesorero.anular', 'tesorero.registrar', 'tesorero.ver'])
        ->and($categorias)->toBe(CategoriaFinancieraService::EGRESOS_PREDETERMINADOS);
});
