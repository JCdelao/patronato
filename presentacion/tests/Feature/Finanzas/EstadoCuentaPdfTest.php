<?php

/*
 * Estado de cuenta de una vivienda en PDF, para un rango de fechas
 *
 * Lo generan el presidente, el secretario y el tesorero con su propio permiso,
 * sin necesitar la pantalla del estado de cuenta.
 */

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Services\CobroService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

/**
 * Generador de PDF que guarda la vista y los datos en lugar de dibujar el documento
 */
class GeneradorPdfEspia implements GeneradorPdf
{
    public ?string $vista = null;

    /** @var array<string, mixed> */
    public array $datos = [];

    public function generar(string $vista, array $datos): string
    {
        $this->vista = $vista;
        $this->datos = $datos;

        return '%PDF-espia';
    }
}

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Storage::fake('public');

    // 15:00 en UTC son las 09:00 en Honduras
    Carbon::setTestNow('2026-11-20 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas', 'direccion' => 'Colonia Las Palmas', 'telefono' => '2222-3333']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $codigo, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($codigo),
    ]);

    $this->secretario = ($this->usuario)(Rol::SECRETARIO);
    $this->tesorero = ($this->usuario)('tesorero');
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE);
    $this->cobrador = ($this->usuario)('cobrador');

    $this->vivienda = fn (string $codigo, bool $activa = true, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    $this->casa = ($this->vivienda)('A-01');

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

    // Historial: cuatro meses de L 500, un pago el 15 de septiembre y una corrección el 20 de octubre
    $agosto = ($this->cargo)($this->casa, '2026-08-01');
    ($this->cargo)($this->casa, '2026-09-01');
    ($this->cargo)($this->casa, '2026-10-01');
    ($this->cargo)($this->casa, '2026-11-01');

    Carbon::setTestNow('2026-09-15 15:00:00');
    app(CobroService::class)->registrarEfectivo($this->cobrador, $this->casa->id, '500', [$agosto]);
    Carbon::setTestNow('2026-11-20 15:00:00');

    DB::table('ajustes')->insert([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $this->casa->id,
        'tipo' => 'correccion',
        'monto' => '-100.00',
        'fecha' => '2026-10-20',
        'motivo' => 'Corrección del recargo de octubre',
        'autorizado_por' => $this->tesorero->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->espia = function (): GeneradorPdfEspia {
        $espia = new GeneradorPdfEspia;
        $this->app->instance(GeneradorPdf::class, $espia);

        return $espia;
    };

    $this->migracion = require database_path('migrations/2026_11_22_200001_asignar_estado_cuenta_pdf.php');

    // Roles con el permiso del PDF en un residencial, ordenados por código
    $this->conPermiso = fn (Residencial $residencial): array => DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('rol_permisos.residencial_id', $residencial->id)
        ->where('modulo', 'estado_cuenta_pdf')
        ->where('accion', 'ver')
        ->pluck('roles.codigo')
        ->sort()
        ->values()
        ->all();

    // Simula residenciales creados antes de este permiso; la cascada limpia sus anotaciones
    $this->sinPermiso = fn () => DB::table('rol_permisos')->where('modulo', 'estado_cuenta_pdf')->delete();

    // Permisos que la migración anotó como suyos
    $this->marcas = fn (): int => DB::table('rol_permisos_migrados')->where('migracion', '2026_11_22_200001_asignar_estado_cuenta_pdf')->count();

    $this->descargar = fn (array $rango = [], ?User $usuario = null, ?Vivienda $vivienda = null) => $this->actingAs($usuario ?? $this->secretario)
        ->get(route('finanzas.estado-cuenta-pdf.descargar', [
            'vivienda' => ($vivienda ?? $this->casa)->id,
            'desde' => '2026-09-10',
            'hasta' => '2026-10-31',
            ...$rango,
        ]));
});

afterEach(fn () => Carbon::setTestNow());

// Permisos

test('the secretary, the treasurer and the president can generate the PDF', function () {
    foreach ([$this->secretario, $this->tesorero, $this->presidente] as $usuario) {
        $this->actingAs($usuario)->get(route('finanzas.estado-cuenta-pdf.index'))->assertOk();

        ($this->descargar)([], $usuario)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="estado-de-cuenta-A-01-2026-09-10-2026-10-31.pdf"');
    }
});

test('the secretary downloads the PDF without permission to see payments', function () {
    expect(app(PermisoService::class)->puede($this->secretario, 'cobros', 'ver'))->toBeFalse();

    ($this->descargar)()->assertOk();
});

test('the account statement screen keeps its previous access', function () {
    // El secretario y el presidente siguen sin la pantalla; el tesorero la sigue viendo
    foreach ([$this->secretario, $this->presidente] as $usuario) {
        $this->actingAs($usuario)->get(route('finanzas.estado-cuenta.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('finanzas.estado-cuenta.show', $this->casa))->assertForbidden();
    }

    $this->actingAs($this->tesorero)->get(route('finanzas.estado-cuenta.show', $this->casa))->assertOk();

    expect(PermisoService::MODULOS)->not->toHaveKey('estado_cuenta');
});

test('users without the permission get 403', function (string $codigo) {
    $usuario = ($this->usuario)($codigo);

    $this->actingAs($usuario)->get(route('finanzas.estado-cuenta-pdf.index'))->assertForbidden();
    ($this->descargar)([], $usuario)->assertForbidden();
})->with(['fiscal', 'vocal', 'cobrador', 'guardia', 'residente', 'admin_tecnico']);

test('the technical administrator does not get the permission with full access', function () {
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    expect(app(PermisoService::class)->deUsuario($admin))->not->toContain('estado_cuenta_pdf.ver')
        ->and(app(PermisoService::class)->puede($admin, 'estado_cuenta_pdf', 'ver'))->toBeFalse();
});

test('guests are sent to the login', function () {
    $this->get(route('finanzas.estado-cuenta-pdf.index'))->assertRedirect(route('login'));
    $this->get(route('finanzas.estado-cuenta-pdf.descargar', ['vivienda' => $this->casa->id, 'desde' => '2026-01-01', 'hasta' => '2026-11-20']))
        ->assertRedirect(route('login'));
});

test('only the president, the secretary and the treasurer get the permission by default', function () {
    foreach (PermisoService::PREDETERMINADOS as $codigo => $permisos) {
        expect(in_array('estado_cuenta_pdf.ver', $permisos, true))->toBe(in_array($codigo, [Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero'], true));
    }
});

// Migración del permiso

test('the migration gives the permission to the three roles of existing residenciales and records what it inserted', function () {
    ($this->sinPermiso)();

    $this->migracion->up();

    foreach ([$this->residencial, $this->otro] as $residencial) {
        expect(($this->conPermiso)($residencial))->toBe([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero']);
    }

    expect(($this->marcas)())->toBe(6);
});

test('running the migration twice does not duplicate permissions nor records', function () {
    ($this->sinPermiso)();

    $this->migracion->up();
    $this->migracion->up();

    // Dos residenciales por tres roles
    expect(DB::table('rol_permisos')->where('modulo', 'estado_cuenta_pdf')->count())->toBe(6)
        ->and(($this->marcas)())->toBe(6);
});

test('reverting the migration removes the permissions it inserted', function () {
    ($this->sinPermiso)();
    $this->migracion->up();

    $otrosPermisos = DB::table('rol_permisos')->where('modulo', '<>', 'estado_cuenta_pdf')->count();

    $this->migracion->down();

    foreach ([$this->residencial, $this->otro] as $residencial) {
        expect(($this->conPermiso)($residencial))->toBe([]);
    }

    // Las anotaciones se van y los demás permisos de esos roles no se tocan
    expect(($this->marcas)())->toBe(0)
        ->and(DB::table('rol_permisos')->where('modulo', '<>', 'estado_cuenta_pdf')->count())->toBe($otrosPermisos)
        ->and(app(PermisoService::class)->puede($this->secretario, 'padron', 'ver'))->toBeTrue();
});

test('a permission that existed before the migration is kept when reverting', function () {
    ($this->sinPermiso)();

    // El tesorero de Las Palmas ya lo tenía antes de la migración
    DB::table('rol_permisos')->insert([
        'residencial_id' => $this->residencial->id,
        'rol_id' => ($this->rol)('tesorero'),
        'modulo' => 'estado_cuenta_pdf',
        'accion' => 'ver',
    ]);

    $this->migracion->up();

    // No se anota lo que ya existía
    expect(($this->marcas)())->toBe(5);

    $this->migracion->down();

    expect(($this->conPermiso)($this->residencial))->toBe(['tesorero'])
        ->and(($this->conPermiso)($this->otro))->toBe([]);
});

test('a permission saved by hand after the migration is kept even for the three default roles', function () {
    ($this->sinPermiso)();
    $this->migracion->up();

    // El administrador guarda los tres roles con el permiso marcado y otro permiso nuevo
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    foreach ([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero'] as $codigo) {
        $rol = Rol::query()->where('codigo', $codigo)->sole();
        app(PermisoService::class)->actualizar($admin, $rol, [...app(PermisoService::class)->deRol($this->residencial->id, $rol->id), 'incidencias.registrar']);
    }

    $this->migracion->down();

    // Las Palmas los conserva porque se guardaron a mano; el otro residencial no
    expect(($this->conPermiso)($this->residencial))->toBe([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero'])
        ->and(($this->conPermiso)($this->otro))->toBe([]);
});

test('reverting the migration keeps the permission given by hand to other roles', function () {
    ($this->sinPermiso)();
    $this->migracion->up();

    // El administrador da el permiso al fiscal y al vocal después de la migración
    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);

    foreach (['fiscal', 'vocal'] as $codigo) {
        $rol = Rol::query()->where('codigo', $codigo)->sole();
        app(PermisoService::class)->actualizar($admin, $rol, [...app(PermisoService::class)->deRol($this->residencial->id, $rol->id), 'estado_cuenta_pdf.ver']);
    }

    expect(($this->conPermiso)($this->residencial))->toBe(['fiscal', Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero', 'vocal']);

    $this->migracion->down();

    expect(($this->conPermiso)($this->residencial))->toBe(['fiscal', 'vocal'])
        ->and(($this->conPermiso)($this->otro))->toBe([]);
});

test('saving or removing a permission from the service removes its record in cascade', function () {
    ($this->sinPermiso)();
    $this->migracion->up();

    $admin = ($this->usuario)(Rol::ADMIN_TECNICO);
    $tesorero = Rol::query()->where('codigo', 'tesorero')->sole();
    $secretario = Rol::query()->where('codigo', Rol::SECRETARIO)->sole();
    $permisos = app(PermisoService::class);

    $marca = fn (Rol $rol): bool => DB::table('rol_permisos_migrados')
        ->where('residencial_id', $this->residencial->id)
        ->where('rol_id', $rol->id)
        ->where('modulo', 'estado_cuenta_pdf')
        ->exists();

    expect($marca($tesorero))->toBeTrue()->and($marca($secretario))->toBeTrue();

    // Quitar el permiso borra la fila y su anotación
    $permisos->actualizar($admin, $tesorero, array_values(array_diff($permisos->deRol($this->residencial->id, $tesorero->id), ['estado_cuenta_pdf.ver'])));

    // Guardar el rol con el permiso marcado reescribe la fila sin anotación
    $permisos->actualizar($admin, $secretario, [...$permisos->deRol($this->residencial->id, $secretario->id), 'incidencias.registrar']);

    expect($marca($tesorero))->toBeFalse()
        ->and($marca($secretario))->toBeFalse()
        ->and($permisos->deRol($this->residencial->id, $secretario->id))->toContain('estado_cuenta_pdf.ver')
        ->and(($this->marcas)())->toBe(4);
});

test('the auxiliary table is created before the permission migration and removed after it', function () {
    $auxiliar = require database_path('migrations/2026_11_22_150001_crear_rol_permisos_migrados.php');

    // Laravel ejecuta las migraciones por nombre y las revierte en el orden inverso
    expect('2026_11_22_150001_crear_rol_permisos_migrados' < '2026_11_22_200001_asignar_estado_cuenta_pdf')->toBeTrue();

    ($this->sinPermiso)();
    $this->migracion->up();

    // Reversión completa: primero los permisos, después la tabla
    $this->migracion->down();
    $auxiliar->down();

    expect(Schema::hasTable('rol_permisos_migrados'))->toBeFalse()
        ->and(($this->conPermiso)($this->residencial))->toBe([]);

    // Y se puede volver a aplicar en el orden normal
    $auxiliar->up();
    $this->migracion->up();

    expect(($this->marcas)())->toBe(6);
});

// Viviendas

test('a housing unit of another residencial is rejected', function () {
    $ajena = ($this->vivienda)('Z-01', residencial: $this->otro);

    ($this->descargar)([], null, $ajena)->assertForbidden();

    expect(Auditoria::query()->where('accion', 'estado_cuenta_pdf_generado')->exists())->toBeFalse();
});

test('inactive housing units are allowed', function () {
    $inactiva = ($this->vivienda)('B-09', activa: false);
    ($this->cargo)($inactiva, '2026-10-01');

    $this->actingAs($this->secretario)
        ->get(route('finanzas.estado-cuenta-pdf.index', ['buscar' => 'B-09']))
        ->assertInertia(fn ($page) => $page->where('viviendas.0.id', $inactiva->id)->where('viviendas.0.activa', false));

    $espia = ($this->espia)();

    ($this->descargar)([], null, $inactiva)->assertOk();

    expect($espia->datos['vivienda']['activa'])->toBeFalse()
        ->and($espia->datos['movimientos'])->toHaveCount(1);
});

// Contenido

test('only the movements of the range are included, with their balances', function () {
    $espia = ($this->espia)();

    ($this->descargar)()->assertOk();

    $datos = $espia->datos;

    expect($espia->vista)->toBe('pdf.estado-cuenta')
        ->and(array_map(fn (array $movimiento): array => [$movimiento['fecha'], $movimiento['clase'], $movimiento['saldo']], $datos['movimientos']))
        ->toBe([
            ['2026-09-15', 'pago', '500.00'],
            ['2026-10-01', 'cargo', '1000.00'],
            ['2026-10-20', 'ajuste', '900.00'],
        ])
        // Antes del rango: agosto y septiembre sin pagar; al cerrarlo, después de la corrección
        ->and($datos['saldo_inicial'])->toBe('1000.00')
        ->and($datos['saldo_final'])->toBe('900.00')
        // El saldo oficial incluye noviembre, fuera del rango
        ->and($datos['saldo_oficial'])->toBe('1400.00')
        ->and($datos['saldo_oficial'])->toBe(number_format((float) DB::table('v_saldos_vivienda')->where('vivienda_id', $this->casa->id)->value('saldo'), 2, '.', ''));
});

test('the PDF has the residencial, the housing unit, the range and the issue date in Honduras', function () {
    $espia = ($this->espia)();

    ($this->descargar)()->assertOk();

    expect($espia->datos['residencial'])->toMatchArray(['nombre' => 'Las Palmas', 'direccion' => 'Colonia Las Palmas', 'telefono' => '2222-3333'])
        ->and($espia->datos['vivienda'])->toMatchArray(['id' => $this->casa->id, 'codigo' => 'A-01', 'activa' => true])
        ->and($espia->datos['desde'])->toBe('2026-09-10')
        ->and($espia->datos['hasta'])->toBe('2026-10-31')
        ->and($espia->datos['emitido'])->toBe('2026-11-20 09:00');
});

test('the whole history can be consulted from its beginning without a year limit', function () {
    $espia = ($this->espia)();

    ($this->descargar)(['desde' => '2000-01-01', 'hasta' => '2026-11-20'])->assertOk();

    expect($espia->datos['saldo_inicial'])->toBe('0.00')
        ->and($espia->datos['movimientos'])->toHaveCount(6)
        ->and($espia->datos['saldo_final'])->toBe($espia->datos['saldo_oficial']);
});

test('a range without movements keeps the balance it had', function () {
    $espia = ($this->espia)();

    ($this->descargar)(['desde' => '2026-10-21', 'hasta' => '2026-10-31'])->assertOk();

    expect($espia->datos['movimientos'])->toBe([])
        ->and($espia->datos['saldo_inicial'])->toBe('900.00')
        ->and($espia->datos['saldo_final'])->toBe('900.00');
});

test('the dates of the range are validated', function (array $rango, string $campo) {
    ($this->descargar)($rango)->assertSessionHasErrors($campo);

    expect(Auditoria::query()->where('accion', 'estado_cuenta_pdf_generado')->exists())->toBeFalse();
})->with([
    'inicial posterior a la final' => [['desde' => '2026-10-01', 'hasta' => '2026-09-01'], 'desde'],
    'final en el futuro' => [['hasta' => '2026-11-21'], 'hasta'],
    'sin fecha inicial' => [['desde' => ''], 'desde'],
    'sin fecha final' => [['hasta' => ''], 'hasta'],
    'fecha inválida' => [['desde' => '2026-02-30'], 'desde'],
    'otro formato' => [['hasta' => '20/10/2026'], 'hasta'],
]);

test('today is taken in Honduras, not in UTC', function () {
    // 03:00 del 21 en UTC todavía es 20 de noviembre en Honduras
    Carbon::setTestNow('2026-11-21 03:00:00');

    ($this->descargar)(['hasta' => '2026-11-21'])->assertSessionHasErrors('hasta');
    ($this->descargar)(['hasta' => '2026-11-20'])->assertOk();
});

test('the response is a real PDF document', function () {
    $respuesta = ($this->descargar)()->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');
});

test('the logo is included when the residencial has one and left out when not', function () {
    $espia = ($this->espia)();

    ($this->descargar)()->assertOk();

    expect($espia->datos['logo'])->toBeNull();

    // PNG de un píxel
    Storage::disk('public')->put('logos/palmas.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $this->residencial->update(['logo_path' => 'logos/palmas.png']);

    ($this->descargar)()->assertOk();

    expect($espia->datos['logo'])->toStartWith('data:image/png;base64,');
});

test('a real PDF with the logo is generated', function () {
    Storage::disk('public')->put('logos/palmas.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $this->residencial->update(['logo_path' => 'logos/palmas.png']);

    $respuesta = ($this->descargar)()->assertOk();

    expect(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');
});

// Auditoría y datos

test('each generation is audited with the user, the housing unit and the range', function () {
    ($this->descargar)([], $this->tesorero)->assertOk();

    $auditoria = Auditoria::query()->where('accion', 'estado_cuenta_pdf_generado')->sole();

    expect($auditoria->user_id)->toBe($this->tesorero->id)
        ->and($auditoria->modulo)->toBe('estado_cuenta_pdf')
        ->and($auditoria->entidad)->toBe('viviendas')
        ->and($auditoria->entidad_id)->toBe($this->casa->id)
        ->and($auditoria->valor_nuevo)->toBe([
            'vivienda_id' => $this->casa->id,
            'codigo' => 'A-01',
            'desde' => '2026-09-10',
            'hasta' => '2026-10-31',
        ]);
});

test('generating the PDF does not change any financial data', function () {
    $foto = fn (): array => collect(['cargos', 'pagos', 'pago_aplicaciones', 'ajustes', 'ingresos', 'cajas'])
        ->mapWithKeys(fn (string $tabla): array => [$tabla => DB::table($tabla)->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all()])
        ->all();

    $antes = $foto();

    ($this->descargar)()->assertOk();
    ($this->descargar)(['desde' => '2000-01-01', 'hasta' => '2026-11-20'])->assertOk();

    expect($foto())->toBe($antes);
});

// Rendimiento

test('a four year history is generated in less than five seconds', function () {
    $casa = ($this->vivienda)('C-48');
    $agua = DB::table('conceptos_cobro')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'nombre' => 'Agua',
        'tipo' => 'recurrente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 48 meses con dos conceptos, un pago mensual que cubre ambos y una corrección por año
    $mes = Carbon::parse('2022-12-01');

    for ($i = 1; $i <= 48; $i++, $mes->addMonth()) {
        $cuota = ($this->cargo)($casa, $mes->toDateString());
        $consumo = (int) DB::table('cargos')->insertGetId([
            'residencial_id' => $this->residencial->id, 'vivienda_id' => $casa->id, 'concepto_id' => $agua,
            'periodo' => $mes->toDateString(), 'monto' => '150.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $pago = DB::table('pagos')->insertGetId([
            'residencial_id' => $this->residencial->id, 'vivienda_id' => $casa->id, 'forma_pago' => 'efectivo',
            'estado' => 'confirmado', 'origen' => 'sistema', 'monto' => '650.00', 'fecha_pago' => $mes->copy()->addDays(9)->toDateString(),
            'numero_recibo' => (string) (1000 + $i), 'registrado_por' => $this->cobrador->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pago_aplicaciones')->insert([
            ['residencial_id' => $this->residencial->id, 'pago_id' => $pago, 'cargo_id' => $cuota, 'monto' => '500.00', 'origen' => 'pago', 'created_at' => now()],
            ['residencial_id' => $this->residencial->id, 'pago_id' => $pago, 'cargo_id' => $consumo, 'monto' => '150.00', 'origen' => 'pago', 'created_at' => now()],
        ]);

        if ($i % 12 === 0) {
            DB::table('ajustes')->insert([
                'residencial_id' => $this->residencial->id, 'vivienda_id' => $casa->id, 'tipo' => 'correccion', 'monto' => '25.00',
                'fecha' => $mes->copy()->addDays(20)->toDateString(), 'motivo' => 'Corrección anual de la cuota',
                'autorizado_por' => $this->tesorero->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $inicio = microtime(true);

    $respuesta = ($this->descargar)(['desde' => '2022-12-01', 'hasta' => '2026-11-20'], null, $casa)->assertOk();

    $segundos = microtime(true) - $inicio;

    expect(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF')
        ->and($segundos)->toBeLessThan(5.0);
});
