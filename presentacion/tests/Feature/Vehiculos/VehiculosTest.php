<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\VehiculoConductor;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->secretario = ($this->usuario)('secretario');

    $this->vivienda = fn (string $codigo, ?Residencial $residencial = null, bool $activa = true): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => $activa,
    ]);

    // Residente que vive en la vivienda; con $hasta deja de vivir ahí
    $this->residente = function (Vivienda $vivienda, string $nombres, ?string $hasta = null): Residente {
        $residente = Residente::query()->create([
            'residencial_id' => $vivienda->residencial_id,
            'nombres' => $nombres,
            'apellidos' => 'Pérez',
        ]);

        DB::table('vivienda_residentes')->insert([
            'residencial_id' => $vivienda->residencial_id,
            'vivienda_id' => $vivienda->id,
            'residente_id' => $residente->id,
            'tipo' => 'propietario',
            'fecha_inicio' => '2024-01-01',
            'fecha_fin' => $hasta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $residente;
    };

    $this->casa = ($this->vivienda)('A-01');
    $this->ana = ($this->residente)($this->casa, 'Ana');
    $this->luis = ($this->residente)($this->casa, 'Luis');

    $this->datos = fn (array $cambios = []): array => [
        'vivienda_id' => $this->casa->id,
        'tipo' => 'carro',
        'placa' => '  haa   1234 ',
        'marca' => 'Toyota',
        'modelo' => 'Corolla',
        'color' => 'Gris',
        'conductores' => [$this->ana->id, $this->luis->id],
        ...$cambios,
    ];

    $this->registrar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.store'), ($this->datos)($cambios));

    $this->vehiculo = fn (array $datos = [], ?Vivienda $vivienda = null): Vehiculo => Vehiculo::query()->create([
        'residencial_id' => ($vivienda ?? $this->casa)->residencial_id,
        'vivienda_id' => ($vivienda ?? $this->casa)->id,
        'tipo' => 'moto',
        'placa' => 'MOT777',
        'activo' => true,
        ...$datos,
    ]);

    $this->editar = fn (Vehiculo $vehiculo, array $datos, ?User $usuario = null) => $this->actingAs($usuario ?? $this->secretario)
        ->put(route('vehiculos.update', $vehiculo), ['tipo' => 'moto', 'placa' => $vehiculo->placa, ...$datos]);

    $this->baja = fn (Vehiculo $vehiculo, ?User $usuario = null, string $motivo = 'Se vendió el vehículo.') => $this->actingAs($usuario ?? $this->secretario)
        ->post(route('vehiculos.baja', $vehiculo), ['motivo' => $motivo]);
});

test('the secretary registers a vehicle with its plate, details and authorized drivers', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    $vehiculo = Vehiculo::query()->sole();

    expect($vehiculo->vivienda_id)->toBe($this->casa->id)
        ->and($vehiculo->residencial_id)->toBe($this->residencial->id)
        ->and($vehiculo->tipo)->toBe('carro')
        ->and($vehiculo->placa)->toBe('HAA1234')
        ->and($vehiculo->marca)->toBe('Toyota')
        ->and($vehiculo->modelo)->toBe('Corolla')
        ->and($vehiculo->color)->toBe('Gris')
        ->and($vehiculo->activo)->toBeTrue()
        ->and(VehiculoConductor::query()->orderBy('residente_id')->pluck('residente_id')->all())->toBe([$this->ana->id, $this->luis->id]);

    $auditoria = Auditoria::query()->where('accion', 'vehiculo_registrado')->sole();

    expect($auditoria->modulo)->toBe('vehiculos')
        ->and($auditoria->user_id)->toBe($this->secretario->id)
        ->and($auditoria->entidad_id)->toBe($vehiculo->id)
        ->and($auditoria->valor_nuevo['placa'])->toBe('HAA1234')
        ->and($auditoria->valor_nuevo['conductores'])->toBe([$this->ana->id, $this->luis->id]);
});

test('brand, model, color and drivers are optional', function () {
    ($this->registrar)(['marca' => '', 'modelo' => '', 'color' => '', 'conductores' => []])->assertSessionHasNoErrors();

    $vehiculo = Vehiculo::query()->sole();

    expect($vehiculo->marca)->toBeNull()
        ->and($vehiculo->color)->toBeNull()
        ->and(VehiculoConductor::query()->count())->toBe(0);
});

test('the housing unit, type and plate are required and validated', function (array $cambios, string $campo) {
    ($this->registrar)($cambios)->assertSessionHasErrors($campo);

    expect(Vehiculo::query()->count())->toBe(0);
})->with([
    'no housing unit' => [['vivienda_id' => ''], 'vivienda_id'],
    'no type' => [['tipo' => ''], 'tipo'],
    'unknown type' => [['tipo' => 'camion'], 'tipo'],
    'no plate' => [['placa' => ''], 'placa'],
    'plate with symbols' => [['placa' => 'HAA#12'], 'placa'],
    'plate too long' => [['placa' => str_repeat('A', 21)], 'placa'],
]);

test('the vehicle must belong to an active housing unit of the same residential', function (string $caso) {
    $vivienda = $caso === 'inactive' ? ($this->vivienda)('Z-99', null, false) : ($this->vivienda)('B-01', $this->otro);

    ($this->registrar)(['vivienda_id' => $vivienda->id, 'conductores' => []])->assertSessionHasErrors('vivienda_id');

    expect(Vehiculo::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['inactive', 'another residential']);

test('a plate cannot repeat among the active vehicles of the residential, however it is written', function (string $escrita) {
    ($this->registrar)(['placa' => 'HAA-1234', 'conductores' => []])->assertSessionHasNoErrors();
    $otraCasa = ($this->vivienda)('A-02');

    ($this->registrar)(['vivienda_id' => $otraCasa->id, 'placa' => $escrita, 'conductores' => []])
        ->assertSessionHasErrors(['placa' => 'Ya hay un vehículo activo con esa placa en el residencial.']);

    expect(Vehiculo::query()->count())->toBe(1);
})->with(['HAA-1234', 'HAA 1234', 'haa1234', ' h a a - 1 2 3 4 ']);

test('the plate is stored in its single form: only letters and numbers, in uppercase', function (string $escrita) {
    ($this->registrar)(['placa' => $escrita, 'conductores' => []])->assertSessionHasNoErrors();

    expect(Vehiculo::query()->sole()->placa)->toBe('HAA1234');
})->with(['HAA-1234', 'HAA 1234', 'haa1234', '  haa   1234 ']);

test('another residential may use the same plate', function () {
    ($this->vehiculo)(['placa' => 'HAA1234']);
    $ajena = ($this->vivienda)('B-01', $this->otro);

    ($this->registrar)(['vivienda_id' => $ajena->id, 'conductores' => []], ($this->usuario)('secretario', $this->otro))->assertSessionHasNoErrors();

    expect(Vehiculo::query()->withoutGlobalScopes()->where('placa', 'HAA1234')->count())->toBe(2);
});

test('the database also rejects two active vehicles with the same plate', function () {
    ($this->vehiculo)(['placa' => 'HAA1234']);

    expect(fn () => ($this->vehiculo)(['placa' => 'haa1234']))->toThrow(UniqueConstraintViolationException::class);
});

test('only current residents of the vehicle housing unit can be authorized drivers', function (string $caso) {
    $vecino = ($this->residente)(($this->vivienda)('A-02'), 'Vecino');
    $exResidente = ($this->residente)($this->casa, 'Antiguo', '2025-01-31');

    ($this->registrar)(['conductores' => [$this->ana->id, $caso === 'neighbor' ? $vecino->id : $exResidente->id]])
        ->assertSessionHasErrors(['conductores' => 'Solo se pueden autorizar residentes que viven en la vivienda del vehículo.']);

    expect(Vehiculo::query()->count())->toBe(0);
})->with(['neighbor', 'former resident']);

test('the driver options are the current residents of the housing unit', function () {
    ($this->residente)($this->casa, 'Antiguo', '2025-01-31');
    ($this->residente)(($this->vivienda)('A-02'), 'Vecino');

    $this->actingAs($this->secretario)
        ->getJson(route('vehiculos.conductores', $this->casa))
        ->assertOk()
        ->assertExactJson([
            ['id' => $this->ana->id, 'nombre' => 'Ana Pérez'],
            ['id' => $this->luis->id, 'nombre' => 'Luis Pérez'],
        ]);
});

test('the secretary edits the details and drivers, and only the changes are audited', function () {
    $vehiculo = ($this->vehiculo)(['marca' => 'Honda']);
    VehiculoConductor::query()->create(['residencial_id' => $this->residencial->id, 'vehiculo_id' => $vehiculo->id, 'residente_id' => $this->ana->id]);
    $otraCasa = ($this->vivienda)('A-02');

    ($this->editar)($vehiculo, ['marca' => 'Honda', 'color' => 'Rojo', 'conductores' => [$this->luis->id], 'vivienda_id' => $otraCasa->id])->assertSessionHasNoErrors();

    $vehiculo->refresh();

    expect($vehiculo->color)->toBe('Rojo')
        ->and($vehiculo->vivienda_id)->toBe($this->casa->id)
        ->and(VehiculoConductor::query()->pluck('residente_id')->all())->toBe([$this->luis->id]);

    $auditoria = Auditoria::query()->where('accion', 'vehiculo_editado')->sole();

    expect($auditoria->valor_anterior)->toBe(['color' => null, 'conductores' => [$this->ana->id]])
        ->and($auditoria->valor_nuevo)->toBe(['color' => 'Rojo', 'conductores' => [$this->luis->id]]);
});

test('saving a vehicle without changes is not audited and its own plate is accepted', function () {
    $vehiculo = ($this->vehiculo)();

    ($this->editar)($vehiculo, ['placa' => 'mot-777'])->assertSessionHasNoErrors();

    expect(Auditoria::query()->where('accion', 'vehiculo_editado')->count())->toBe(0);
});

test('the secretary deactivates a vehicle with a reason and its plate becomes free', function () {
    $vehiculo = ($this->vehiculo)(['placa' => 'HAA1234']);

    ($this->baja)($vehiculo)->assertSessionHasNoErrors();

    expect($vehiculo->fresh()->activo)->toBeFalse();

    $auditoria = Auditoria::query()->where('accion', 'vehiculo_dado_de_baja')->sole();

    expect($auditoria->motivo)->toBe('Se vendió el vehículo.')
        ->and($auditoria->valor_nuevo)->toBe(['activo' => false]);

    ($this->registrar)(['conductores' => []])->assertSessionHasNoErrors();

    expect(Vehiculo::query()->where('placa', 'HAA1234')->count())->toBe(2);
});

test('a vehicle cannot be deactivated twice, without a reason or edited after it, and cannot be deleted', function () {
    $vehiculo = ($this->vehiculo)();

    ($this->baja)($vehiculo, null, '')->assertSessionHasErrors('motivo');
    ($this->baja)($vehiculo, null, 'corto')->assertSessionHasErrors('motivo');
    ($this->baja)($vehiculo)->assertSessionHasNoErrors();
    ($this->baja)($vehiculo, null, 'Otro motivo para la baja.')->assertSessionHasErrors(['motivo' => 'El vehículo ya está dado de baja.']);
    ($this->editar)($vehiculo, ['color' => 'Azul'])->assertNotFound();

    $this->actingAs($this->secretario)->delete(route('vehiculos.update', $vehiculo))->assertMethodNotAllowed();

    expect(Vehiculo::query()->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'vehiculo_dado_de_baja')->count())->toBe(1);
});

test('the list shows active or deactivated vehicles and searches by plate, brand or housing unit', function () {
    ($this->vehiculo)(['placa' => 'HAA1234', 'marca' => 'Toyota']);
    ($this->vehiculo)(['placa' => 'BCD999', 'marca' => 'Nissan', 'activo' => false]);
    ($this->vehiculo)(['placa' => 'ZZZ111'], ($this->vivienda)('C-07'));
    ($this->vehiculo)(['placa' => 'OTR000'], ($this->vivienda)('B-01', $this->otro));

    $this->actingAs($this->secretario)
        ->get(route('vehiculos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('vehiculos/index')
            ->has('vehiculos.data', 2)
            ->where('vehiculos.data.0.placa', 'HAA1234')
            ->where('vehiculos.data.0.vivienda', 'A-01')
            ->where('tipos', Vehiculo::TIPOS));

    $this->actingAs($this->secretario)
        ->get(route('vehiculos.index', ['estado' => 'bajas']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('vehiculos.data', 1)->where('vehiculos.data.0.placa', 'BCD999'));

    foreach (['haa' => 'HAA1234', 'HAA-12' => 'HAA1234', 'haa 1234' => 'HAA1234', 'toyota' => 'HAA1234', 'c-07' => 'ZZZ111'] as $buscar => $placa) {
        $this->actingAs($this->secretario)
            ->get(route('vehiculos.index', ['buscar' => $buscar]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('vehiculos.data', 1)->where('vehiculos.data.0.placa', $placa));
    }
});

test('coming from a housing unit opens the form with it selected', function () {
    $this->actingAs($this->secretario)
        ->get(route('vehiculos.index', ['vivienda' => $this->casa->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('nuevoPara', $this->casa->id));
});

test('the plate of an active vehicle finds its housing unit in the registry search', function () {
    ($this->vehiculo)(['placa' => 'HAA1234']);
    ($this->vehiculo)(['placa' => 'VIE555', 'activo' => false], ($this->vivienda)('A-02'));

    foreach (['haa 12', 'HAA-1234', 'haa1234'] as $buscar) {
        $this->actingAs($this->secretario)
            ->get(route('padron.viviendas.index', ['buscar' => $buscar]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('viviendas.data', 1)->where('viviendas.data.0.codigo', 'A-01'));
    }

    $this->actingAs($this->secretario)
        ->get(route('padron.viviendas.index', ['buscar' => 'VIE-555']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('viviendas.data', 0));
});

test('the president and the guard see the vehicles but cannot register, edit or deactivate them', function (string $rol) {
    $vehiculo = ($this->vehiculo)();
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('vehiculos.index'))->assertOk();
    $this->actingAs($usuario)->getJson(route('vehiculos.conductores', $this->casa))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();
    ($this->editar)($vehiculo, ['color' => 'Azul'], $usuario)->assertForbidden();
    ($this->baja)($vehiculo, $usuario)->assertForbidden();

    expect(Vehiculo::query()->count())->toBe(1)
        ->and($vehiculo->fresh()->activo)->toBeTrue();
})->with(['presidente', 'guardia']);

test('other roles cannot see the vehicles', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('vehiculos.index'))->assertForbidden();
    ($this->registrar)([], $usuario)->assertForbidden();
})->with(['tesorero', 'vocal', 'cobrador']);

test('the vehicles of another residential are denied and audited', function () {
    $vehiculo = ($this->vehiculo)();
    $ajeno = ($this->usuario)('secretario', $this->otro);

    $this->actingAs($ajeno)
        ->get(route('vehiculos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('vehiculos.data', 0)->has('viviendas', 0));

    ($this->editar)($vehiculo, ['color' => 'Azul'], $ajeno)->assertForbidden();
    ($this->baja)($vehiculo, $ajeno)->assertForbidden();
    $this->actingAs($ajeno)->getJson(route('vehiculos.conductores', $this->casa))->assertForbidden();

    expect($vehiculo->fresh()->activo)->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->where('user_id', $ajeno->id)->count())->toBe(3);
});

test('existing residentials get the vehicle permissions', function () {
    $antiguo = Residencial::query()->create(['nombre' => 'Residencial antiguo']);
    DB::table('rol_permisos')->where('residencial_id', $antiguo->id)->where('modulo', 'vehiculos')->delete();

    $migracion = require base_path('../base-de-datos/migrations/2026_11_17_200001_asignar_permisos_de_vehiculos.php');
    $migracion->up();
    $migracion->up();

    $permisos = DB::table('rol_permisos')
        ->join('roles', 'roles.id', '=', 'rol_permisos.rol_id')
        ->where('residencial_id', $antiguo->id)
        ->where('modulo', 'vehiculos')
        ->orderBy('roles.codigo')
        ->orderBy('accion')
        ->get(['roles.codigo', 'accion'])
        ->map(fn ($fila) => "{$fila->codigo}.{$fila->accion}")
        ->all();

    expect($permisos)->toBe(['guardia.ver', 'presidente.ver', 'secretario.anular', 'secretario.registrar', 'secretario.ver']);
});

test('existing plates are rewritten in their single form', function () {
    $conGuion = ($this->vehiculo)(['placa' => 'hcd-4321']);
    $conEspacio = ($this->vehiculo)(['placa' => 'HCD 4321', 'activo' => false], ($this->vivienda)('A-02'));
    $repetida = ($this->vehiculo)(['placa' => 'HCD4321'], ($this->vivienda)('A-03'));
    $ajena = ($this->vehiculo)(['placa' => 'hcd 4321'], ($this->vivienda)('B-01', $this->otro));

    $migracion = require base_path('../base-de-datos/migrations/2026_11_17_200002_normalizar_placas.php');
    $migracion->up();
    $migracion->up();

    $placa = fn (Vehiculo $vehiculo): string => Vehiculo::query()->withoutGlobalScopes()->findOrFail($vehiculo->id)->placa;

    // La activa que ya estaba en su forma única se conserva; la otra activa igual queda sin cambiar para revisarla
    expect($placa($repetida))->toBe('HCD4321')
        ->and($placa($conGuion))->toBe('hcd-4321')
        ->and($placa($conEspacio))->toBe('HCD4321')
        ->and($placa($ajena))->toBe('HCD4321');
});
