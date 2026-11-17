<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    // 9:00 del 17 de noviembre de 2026 en Honduras
    $this->travelTo('2026-11-17 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->secretario = ($this->usuario)('secretario');

    $this->vivienda = fn (string $codigo, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    // Residente que vive en una o varias viviendas
    $this->residente = function (string $nombres, array $viviendas = [], ?Residencial $residencial = null, bool $activo = true): Residente {
        $residencial ??= $this->residencial;
        $residente = Residente::query()->create([
            'residencial_id' => $residencial->id,
            'nombres' => $nombres,
            'apellidos' => 'López',
            'activo' => $activo,
        ]);

        foreach ($viviendas ?: [($this->vivienda)("V-{$nombres}", $residencial)] as $vivienda) {
            DB::table('vivienda_residentes')->insert([
                'residencial_id' => $residencial->id,
                'vivienda_id' => $vivienda->id,
                'residente_id' => $residente->id,
                'tipo' => 'propietario',
                'fecha_inicio' => '2024-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $residente;
    };

    $this->carnet = fn (Residente $residente, string $numero, string $estado = Carnet::ACTIVO, ?string $vigenteHasta = '2027-11-17'): Carnet => Carnet::query()->create([
        'residencial_id' => $residente->residencial_id,
        'residente_id' => $residente->id,
        'numero' => $numero,
        'fecha_emision' => '2026-01-10',
        'vigente_hasta' => $vigenteHasta,
        'estado' => $estado,
        'motivo' => $estado === Carnet::ACTIVO ? null : 'Motivo anterior.',
    ]);

    $this->maria = ($this->residente)('María');

    $this->emitir = fn (Residente $residente, string $vigenteHasta = '2027-11-17', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('carnets.emitir', $residente), ['vigente_hasta' => $vigenteHasta]);

    $this->renovar = fn (Carnet $carnet, string $vigenteHasta = '2028-01-31', string $motivo = 'Renovación anual del carnet.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('carnets.renovar', $carnet), ['vigente_hasta' => $vigenteHasta, 'motivo' => $motivo]);

    $this->cambiar = fn (Carnet $carnet, string $accion, string $motivo = 'Revisión del comité de seguridad.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('carnets.estado', $carnet), ['accion' => $accion, 'motivo' => $motivo]);

    $this->baja = fn (Carnet $carnet, string $motivo = 'Se perdió el carnet.', ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('carnets.baja', $carnet), ['motivo' => $motivo]);
});

test('the secretary issues a carnet with a consecutive number and a validity date', function () {
    ($this->emitir)($this->maria)->assertSessionHasNoErrors();
    ($this->emitir)(($this->residente)('Pedro'), '2027-06-30')->assertSessionHasNoErrors();

    $carnets = Carnet::query()->orderBy('id')->get();

    expect($carnets->pluck('numero')->all())->toBe(['C-0001', 'C-0002'])
        ->and($carnets[0]->residente_id)->toBe($this->maria->id)
        ->and($carnets[0]->estado)->toBe(Carnet::ACTIVO)
        ->and($carnets[0]->fecha_emision->toDateString())->toBe('2026-11-17')
        ->and($carnets[0]->vigente_hasta?->toDateString())->toBe('2027-11-17')
        ->and($carnets[1]->vigente_hasta?->toDateString())->toBe('2027-06-30');

    $auditoria = Auditoria::query()->where('accion', 'carnet_emitido')->orderBy('id')->first();

    expect($auditoria->modulo)->toBe('carnets')
        ->and($auditoria->valor_nuevo)->toBe(['residente_id' => $this->maria->id, 'numero' => 'C-0001', 'vigente_hasta' => '2027-11-17']);
});

test('the numbering never reuses a number, not even from old carnets, and each residential has its own', function () {
    ($this->carnet)(($this->residente)('Antiguo'), 'C-0007', Carnet::DADO_DE_BAJA);
    ($this->carnet)(($this->residente)('Ajeno', [], $this->otro), 'C-0040');

    ($this->emitir)($this->maria)->assertSessionHasNoErrors();
    ($this->emitir)(($this->residente)('Rosa', [], $this->otro), '2027-11-17', ($this->usuario)('secretario', $this->otro))->assertSessionHasNoErrors();

    expect(Carnet::query()->withoutGlobalScopes()->where('residente_id', $this->maria->id)->value('numero'))->toBe('C-0008')
        ->and(Carnet::query()->withoutGlobalScopes()->where('residencial_id', $this->otro->id)->orderByDesc('id')->value('numero'))->toBe('C-0041');
});

test('the database rejects a repeated carnet number in the residential', function () {
    ($this->carnet)($this->maria, 'C-0001', Carnet::REEMPLAZADO);

    expect(fn () => ($this->carnet)(($this->residente)('Pedro'), 'C-0001'))->toThrow(UniqueConstraintViolationException::class);
});

test('the validity must end after today and within five years', function (string $fecha) {
    ($this->emitir)($this->maria, $fecha)->assertSessionHasErrors('vigente_hasta');

    expect(Carnet::query()->count())->toBe(0);
})->with(['', '2026-11-17', '2026-01-01', '2031-11-18', '17/11/2027']);

test('the form proposes one year of validity', function () {
    $this->actingAs($this->secretario)
        ->get(route('carnets.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('carnets/index')->where('vigenciaPredeterminada', '2027-11-17'));
});

test('a resident has a single carnet at a time and an inactive resident cannot get one', function () {
    ($this->carnet)($this->maria, 'C-0001', Carnet::SUSPENDIDO);

    ($this->emitir)($this->maria)->assertSessionHasErrors(['vigente_hasta' => 'El residente ya tiene un carnet; para cambiarlo, renuévalo.']);
    ($this->emitir)(($this->residente)('Inactivo', [], null, false))->assertSessionHasErrors(['vigente_hasta' => 'El residente está dado de baja.']);

    expect(Carnet::query()->count())->toBe(1);
});

test('renewing replaces the carnet with a new number and validity, keeping the reason', function (string $estado) {
    $anterior = ($this->carnet)($this->maria, 'C-0001', $estado);

    ($this->renovar)($anterior)->assertSessionHasNoErrors();

    $anterior->refresh();
    $nuevo = Carnet::query()->where('estado', Carnet::ACTIVO)->sole();

    expect($anterior->estado)->toBe(Carnet::REEMPLAZADO)
        ->and($anterior->motivo)->toBe('Renovación anual del carnet.')
        ->and($nuevo->numero)->toBe('C-0002')
        ->and($nuevo->residente_id)->toBe($this->maria->id)
        ->and($nuevo->vigente_hasta?->toDateString())->toBe('2028-01-31');

    $auditoria = Auditoria::query()->where('accion', 'carnet_renovado')->sole();

    expect($auditoria->valor_anterior)->toBe(['numero' => 'C-0001', 'estado' => $estado])
        ->and($auditoria->valor_nuevo)->toBe(['numero' => 'C-0002', 'carnet_id' => $nuevo->id, 'vigente_hasta' => '2028-01-31']);
})->with([Carnet::ACTIVO, Carnet::VENCIDO, Carnet::SUSPENDIDO]);

test('a renewal needs a reason and a valid date', function () {
    $carnet = ($this->carnet)($this->maria, 'C-0001');

    ($this->renovar)($carnet, '2028-01-31', 'corto')->assertSessionHasErrors('motivo');
    ($this->renovar)($carnet, '2026-11-01')->assertSessionHasErrors('vigente_hasta');

    expect($carnet->fresh()->estado)->toBe(Carnet::ACTIVO);
});

test('the secretary suspends, reactivates and expires a carnet following its states', function () {
    $carnet = ($this->carnet)($this->maria, 'C-0001');

    ($this->cambiar)($carnet, 'suspender')->assertSessionHasNoErrors();
    expect($carnet->fresh()->estado)->toBe(Carnet::SUSPENDIDO);

    ($this->cambiar)($carnet, 'reactivar')->assertSessionHasNoErrors();
    expect($carnet->fresh()->estado)->toBe(Carnet::ACTIVO);

    ($this->cambiar)($carnet, 'vencer', 'El residente entregó el carnet viejo.')->assertSessionHasNoErrors();
    expect($carnet->fresh()->estado)->toBe(Carnet::VENCIDO);

    expect(Auditoria::query()->where('entidad', 'carnets')->orderBy('id')->pluck('accion')->all())
        ->toBe(['carnet_suspendido', 'carnet_reactivado', 'carnet_vencido']);
});

test('a suspended carnet whose validity already ended cannot be reactivated', function () {
    $carnet = ($this->carnet)($this->maria, 'C-0001', Carnet::SUSPENDIDO, '2026-11-16');

    ($this->cambiar)($carnet, 'reactivar')->assertSessionHasErrors(['accion' => 'La vigencia del carnet ya terminó; renuévalo.']);

    expect($carnet->fresh()->estado)->toBe(Carnet::SUSPENDIDO);
});

test('a carnet state only admits its own transitions', function (string $estado, string $accion) {
    $carnet = ($this->carnet)($this->maria, 'C-0001', $estado);

    $respuesta = match ($accion) {
        'renovar' => ($this->renovar)($carnet),
        'dar_de_baja' => ($this->baja)($carnet),
        default => ($this->cambiar)($carnet, $accion),
    };

    $respuesta->assertSessionHasErrors(['accion' => 'El carnet ya no admite ese cambio; actualiza la página.']);

    expect($carnet->fresh()->estado)->toBe($estado);
})->with([
    'reactivate an active one' => [Carnet::ACTIVO, 'reactivar'],
    'suspend an expired one' => [Carnet::VENCIDO, 'suspender'],
    'renew a replaced one' => [Carnet::REEMPLAZADO, 'renovar'],
    'deactivate a deactivated one' => [Carnet::DADO_DE_BAJA, 'dar_de_baja'],
]);

test('a carnet is deactivated with a reason', function () {
    $carnet = ($this->carnet)($this->maria, 'C-0001');

    ($this->baja)($carnet)->assertSessionHasNoErrors();

    expect($carnet->fresh())->estado->toBe(Carnet::DADO_DE_BAJA)->motivo->toBe('Se perdió el carnet.');
});

test('the daily task expires the active carnets whose validity ended, in every residential', function () {
    $ayer = ($this->carnet)($this->maria, 'C-0001', Carnet::ACTIVO, '2026-11-16');
    $hoy = ($this->carnet)(($this->residente)('Pedro'), 'C-0002', Carnet::ACTIVO, '2026-11-17');
    $suspendido = ($this->carnet)(($this->residente)('Ana'), 'C-0003', Carnet::SUSPENDIDO, '2026-10-01');
    $ajeno = ($this->carnet)(($this->residente)('Ajeno', [], $this->otro), 'C-0001', Carnet::ACTIVO, '2026-11-01');

    $this->artisan('carnets:vencer')->expectsOutput('2 carnets pasaron a vencidos.')->assertExitCode(0);

    expect($ayer->fresh())->estado->toBe(Carnet::VENCIDO)->motivo->toBe('Terminó su vigencia el 2026-11-16.')
        ->and($hoy->fresh()->estado)->toBe(Carnet::ACTIVO)
        ->and($suspendido->fresh()->estado)->toBe(Carnet::SUSPENDIDO)
        ->and(Carnet::query()->withoutGlobalScopes()->findOrFail($ajeno->id)->estado)->toBe(Carnet::VENCIDO);

    $auditorias = Auditoria::query()->where('accion', 'carnet_vencido')->orderBy('id')->get();

    expect($auditorias)->toHaveCount(2)
        ->and($auditorias[0]->user_id)->toBeNull()
        ->and($auditorias[0]->residencial_id)->toBe($this->residencial->id)
        ->and($auditorias[1]->residencial_id)->toBe($this->otro->id);

    $this->artisan('carnets:vencer')->expectsOutput('Ningún carnet venció hoy.')->assertExitCode(0);
});

test('the expiration task runs every day at five past midnight in Honduras', function () {
    $tarea = collect(app(Schedule::class)->events())->first(fn ($evento) => str_contains((string) $evento->command, 'carnets:vencer'));

    expect($tarea)->not->toBeNull()
        ->and($tarea->expression)->toBe('5 0 * * *')
        ->and((string) $tarea->timezone)->toBe('America/Tegucigalpa');
});

test('a resident deactivated from the registry loses the carnet, but not when leaving only one of the units', function () {
    $casa = ($this->vivienda)('A-01');
    $apartamento = ($this->vivienda)('B-02');
    $pedro = ($this->residente)('Pedro', [$casa, $apartamento]);
    $carnetPedro = ($this->carnet)($pedro, 'C-0001');
    $carnetMaria = ($this->carnet)($this->maria, 'C-0002', Carnet::SUSPENDIDO);

    $this->actingAs($this->secretario)->delete(route('padron.residentes.destroy', [$pedro->id, 'vivienda' => $casa->id]))->assertSessionHasNoErrors();

    expect($carnetPedro->fresh()->estado)->toBe(Carnet::ACTIVO);

    $this->actingAs($this->secretario)->delete(route('padron.residentes.destroy', $this->maria->id))->assertSessionHasNoErrors();

    expect($carnetMaria->fresh())->estado->toBe(Carnet::DADO_DE_BAJA)->motivo->toBe('El residente se dio de baja del padrón.');
});

test('the list shows each active resident with the history of carnets and the actions of the current one', function () {
    ($this->carnet)($this->maria, 'C-0001', Carnet::REEMPLAZADO);
    ($this->carnet)($this->maria, 'C-0005', Carnet::SUSPENDIDO);
    ($this->residente)('Pedro');
    ($this->residente)('Inactivo', [], null, false);

    $this->actingAs($this->secretario)
        ->get(route('carnets.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('residentes.data', 2)
            ->where('residentes.data.0.nombre', 'María López')
            ->where('residentes.data.0.viviendas', ['V-María'])
            ->has('residentes.data.0.carnets', 2)
            ->where('residentes.data.0.carnets.0.numero', 'C-0005')
            ->where('residentes.data.0.carnets.0.acciones', ['reactivar', 'reemplazar', 'dar_de_baja'])
            ->where('residentes.data.0.carnets.1.acciones', [])
            ->has('residentes.data.1.carnets', 0));

    $this->actingAs($this->secretario)
        ->get(route('carnets.index', ['buscar' => 'c-0005']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('residentes.data', 1)->where('residentes.data.0.nombre', 'María López'));
});

test('the president and the guard see the carnets but cannot change them', function (string $rol) {
    $usuario = ($this->usuario)($rol);
    $carnet = ($this->carnet)(($this->residente)('Pedro'), 'C-0001');

    $this->actingAs($usuario)->get(route('carnets.index'))->assertOk();
    ($this->emitir)($this->maria, '2027-11-17', $usuario)->assertForbidden();
    ($this->renovar)($carnet, '2028-01-31', 'Renovación anual del carnet.', $usuario)->assertForbidden();
    ($this->cambiar)($carnet, 'suspender', 'Revisión del comité de seguridad.', $usuario)->assertForbidden();
    ($this->baja)($carnet, 'Se perdió el carnet.', $usuario)->assertForbidden();

    expect(Carnet::query()->count())->toBe(1)
        ->and($carnet->fresh()->estado)->toBe(Carnet::ACTIVO);
})->with(['presidente', 'guardia']);

test('other roles cannot see the carnets', function (string $rol) {
    $this->actingAs(($this->usuario)($rol))->get(route('carnets.index'))->assertForbidden();
})->with(['tesorero', 'vocal', 'cobrador']);

test('the carnets of another residential are denied and audited', function () {
    $ajeno = ($this->usuario)('secretario', $this->otro);
    $carnet = ($this->carnet)($this->maria, 'C-0001');

    $this->actingAs($ajeno)
        ->get(route('carnets.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('residentes.data', 0));

    ($this->emitir)(($this->residente)('Pedro'), '2027-11-17', $ajeno)->assertForbidden();
    ($this->cambiar)($carnet, 'suspender', 'Revisión del comité de seguridad.', $ajeno)->assertForbidden();

    expect($carnet->fresh()->estado)->toBe(Carnet::ACTIVO)
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(2);
});

test('existing residentials get the carnet permissions', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'carnets')->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_17_300001_asignar_permisos_de_carnets.php');
    $migracion->up();
    $migracion->up();

    $permisos = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('residencial_id', $antiguo->id)
        ->where('modulo', 'carnets')
        ->orderBy('roles.codigo')
        ->orderBy('accion')
        ->get(['roles.codigo', 'accion'])
        ->map(fn ($fila) => "{$fila->codigo}.{$fila->accion}")
        ->all();

    expect($permisos)->toBe(['guardia.ver', 'presidente.ver', 'secretario.anular', 'secretario.registrar', 'secretario.ver']);
});
