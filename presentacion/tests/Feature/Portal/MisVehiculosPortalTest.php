<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\VehiculoConductor;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

// HU-53: el residente consulta los vehículos, stickers y carnets de su vivienda desde el portal

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Storage::fake('local');

    // 9:00 del 20 de noviembre de 2026 en Honduras
    $this->travelTo('2026-11-20 15:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->rol = fn (string $codigo): int => (int) Rol::query()->where('codigo', $codigo)->value('id');

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => ($this->rol)($rol),
    ]);

    $this->vivienda = fn (string $codigo, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);

    $this->casa = ($this->vivienda)('A-01');
    $this->vecina = ($this->vivienda)('A-02');

    // Residente que vive en las viviendas, con o sin cuenta del portal
    $this->persona = function (array $viviendas, array $datos = [], ?User $cuenta = null): Residente {
        $residencialId = $viviendas[0]->residencial_id;

        $residente = Residente::query()->withoutGlobalScopes()->create([
            'residencial_id' => $residencialId,
            'user_id' => $cuenta?->id,
            'nombres' => 'Rosa',
            'apellidos' => 'Vecina',
            'activo' => true,
            ...$datos,
        ]);

        foreach ($viviendas as $vivienda) {
            ViviendaResidente::query()->withoutGlobalScopes()->create([
                'residencial_id' => $residencialId,
                'vivienda_id' => $vivienda->id,
                'residente_id' => $residente->id,
                'tipo' => 'inquilino',
                'es_responsable_pago' => false,
                'fecha_inicio' => '2025-01-01',
            ]);
        }

        return $residente;
    };

    $this->cuentaRosa = ($this->usuario)(Rol::RESIDENTE);
    $this->rosa = ($this->persona)([$this->casa], [], $this->cuentaRosa);

    $this->vehiculo = fn (Vivienda $vivienda, array $datos = []): Vehiculo => Vehiculo::query()->withoutGlobalScopes()->create([
        'residencial_id' => $vivienda->residencial_id,
        'vivienda_id' => $vivienda->id,
        'tipo' => 'carro',
        'placa' => 'HAB1234',
        'marca' => 'Toyota',
        'modelo' => 'Corolla',
        'color' => 'Gris',
        'activo' => true,
        ...$datos,
    ]);

    $this->sinPlaca = fn (Vivienda $vivienda, array $datos = []): Vehiculo => ($this->vehiculo)($vivienda, [
        'placa' => null,
        'comprobante_tipo' => 'permiso_provisional',
        'comprobante_numero' => 'PP2026001',
        'comprobante_vence' => '2026-12-31',
        'comprobante_path' => 'comprobantes-vehiculos/permiso.pdf',
        ...$datos,
    ]);

    $this->sticker = fn (Vehiculo $vehiculo, string $numero = '0457', string $estado = Sticker::ACTIVO): Sticker => Sticker::query()->withoutGlobalScopes()->create([
        'residencial_id' => $vehiculo->residencial_id,
        'vehiculo_id' => $vehiculo->id,
        'numero' => $numero,
        'estado' => $estado,
        'fecha_emision' => '2026-01-15',
        'motivo' => $estado === Sticker::ACTIVO ? null : 'Motivo interno de la junta',
    ]);

    $this->carnet = fn (Residente $residente, string $numero = 'C-0001', string $estado = Carnet::ACTIVO): Carnet => Carnet::query()->withoutGlobalScopes()->create([
        'residencial_id' => $residente->residencial_id,
        'residente_id' => $residente->id,
        'numero' => $numero,
        'fecha_emision' => '2026-01-15',
        'vigente_hasta' => '2027-01-15',
        'estado' => $estado,
        'motivo' => $estado === Carnet::ACTIVO ? null : 'Motivo interno de la junta',
    ]);

    $this->ver = fn (?User $usuario = null) => $this->actingAs($usuario ?? $this->cuentaRosa)->get(route('portal.vehiculos.index'));

    $this->abrir = fn (Vehiculo $vehiculo, ?User $usuario = null) => $this->actingAs($usuario ?? $this->cuentaRosa)
        ->get(route('portal.vehiculos.comprobante', $vehiculo->id));
});

test('the resident sees the vehicles, stickers and carnets of their house', function () {
    $carro = ($this->vehiculo)($this->casa);
    ($this->sticker)($carro);
    VehiculoConductor::query()->create(['residencial_id' => $this->residencial->id, 'vehiculo_id' => $carro->id, 'residente_id' => $this->rosa->id]);
    VehiculoConductor::query()->create(['residencial_id' => $this->residencial->id, 'vehiculo_id' => $carro->id, 'nombre' => 'Chofer Externo']);
    ($this->carnet)($this->rosa);

    ($this->ver)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/mis-vehiculos')
            ->has('viviendas', 1)
            ->where('viviendas.0.codigo', 'A-01')
            ->has('viviendas.0.vehiculos', 1)
            ->where('viviendas.0.vehiculos.0.placa', 'HAB1234')
            ->where('viviendas.0.vehiculos.0.marca', 'Toyota')
            ->where('viviendas.0.vehiculos.0.comprobante', null)
            ->where('viviendas.0.vehiculos.0.sticker.numero', '0457')
            ->where('viviendas.0.vehiculos.0.sticker.estado', Sticker::ACTIVO)
            ->where('viviendas.0.vehiculos.0.conductores', ['Rosa Vecina', 'Chofer Externo'])
            ->has('viviendas.0.residentes', 1)
            ->where('viviendas.0.residentes.0.nombre', 'Rosa Vecina')
            ->where('viviendas.0.residentes.0.carnet.numero', 'C-0001')
            ->where('viviendas.0.residentes.0.carnet.vigente_hasta', '2027-01-15'));
});

test('the resident sees the carnets of everyone who lives in the house today', function () {
    $hijo = ($this->persona)([$this->casa], ['nombres' => 'Ana', 'apellidos' => 'Vecina']);
    ($this->carnet)($hijo, 'C-0002');
    ($this->persona)([$this->casa], ['nombres' => 'Beto', 'apellidos' => 'Sin Carnet']);
    ($this->persona)([$this->casa], ['nombres' => 'Carlos', 'apellidos' => 'Inactivo', 'activo' => false]);

    $exvecino = ($this->persona)([$this->casa], ['nombres' => 'Dora', 'apellidos' => 'Se Mudó']);
    ViviendaResidente::query()->where('residente_id', $exvecino->id)->update(['fecha_fin' => '2026-10-01']);

    ($this->persona)([$this->vecina], ['nombres' => 'Elsa', 'apellidos' => 'Otra Casa']);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('viviendas.0.residentes', fn ($residentes) => collect($residentes)->pluck('nombre')->all() === ['Ana Vecina', 'Beto Sin Carnet', 'Rosa Vecina'])
        ->where('viviendas.0.residentes.0.carnet.numero', 'C-0002')
        ->where('viviendas.0.residentes.1.carnet', null));
});

test('only active vehicles and current stickers and carnets are shown', function () {
    $carro = ($this->vehiculo)($this->casa);
    ($this->sticker)($carro, '0001', Sticker::REEMPLAZADO);
    ($this->sticker)($carro, '0002', Sticker::SUSPENDIDO);

    $baja = ($this->vehiculo)($this->casa, ['placa' => 'BAJA999', 'activo' => false]);
    ($this->sticker)($baja, '0003', Sticker::DADO_DE_BAJA);

    $sinSticker = ($this->vehiculo)($this->casa, ['placa' => 'MOTO111', 'tipo' => 'moto']);
    ($this->sticker)($sinSticker, '0004', Sticker::DADO_DE_BAJA);

    ($this->carnet)($this->rosa, 'C-0001', Carnet::REEMPLAZADO);
    ($this->carnet)($this->rosa, 'C-0002', Carnet::VENCIDO);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('viviendas.0.vehiculos', 2)
        ->where('viviendas.0.vehiculos.0.placa', 'HAB1234')
        ->where('viviendas.0.vehiculos.0.sticker.numero', '0002')
        ->where('viviendas.0.vehiculos.0.sticker.estado', Sticker::SUSPENDIDO)
        ->where('viviendas.0.vehiculos.1.placa', 'MOTO111')
        ->where('viviendas.0.vehiculos.1.sticker', null)
        ->where('viviendas.0.residentes.0.carnet.numero', 'C-0002')
        ->where('viviendas.0.residentes.0.carnet.estado', Carnet::VENCIDO));
});

test('a vehicle without plate shows its proof and whether it expired', function () {
    ($this->sinPlaca)($this->casa);
    ($this->sinPlaca)($this->casa, ['comprobante_numero' => 'PP2026002', 'comprobante_vence' => '2026-11-19', 'comprobante_path' => null]);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('viviendas.0.vehiculos', 2)
        ->where('viviendas.0.vehiculos.0.placa', null)
        ->where('viviendas.0.vehiculos.0.comprobante.tipo', 'Permiso provisional de circulación')
        ->where('viviendas.0.vehiculos.0.comprobante.numero', 'PP2026001')
        ->where('viviendas.0.vehiculos.0.comprobante.vence', '2026-12-31')
        ->where('viviendas.0.vehiculos.0.comprobante.tiene_archivo', true)
        ->where('viviendas.0.vehiculos.0.comprobante_vencido', false)
        ->where('viviendas.0.vehiculos.1.comprobante.tiene_archivo', false)
        ->where('viviendas.0.vehiculos.1.comprobante_vencido', true));
});

test('the proof kept as history of a plated vehicle is not shown', function () {
    ($this->sinPlaca)($this->casa, ['placa' => 'HAB1234']);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('viviendas.0.vehiculos.0.placa', 'HAB1234')
        ->where('viviendas.0.vehiculos.0.comprobante', null)
        ->where('viviendas.0.vehiculos.0.comprobante_vencido', false));
});

test('the portal sends only what the resident needs, without internal reasons', function () {
    $carro = ($this->vehiculo)($this->casa);
    ($this->sticker)($carro, '0002', Sticker::SUSPENDIDO);
    ($this->carnet)($this->rosa, 'C-0001', Carnet::SUSPENDIDO);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('viviendas.0.vehiculos.0.sticker', fn ($sticker) => array_keys(collect($sticker)->all()) === ['numero', 'estado', 'fecha_emision'])
        ->where('viviendas.0.residentes.0.carnet', fn ($carnet) => array_keys(collect($carnet)->all()) === ['numero', 'estado', 'fecha_emision', 'vigente_hasta'])
        ->where('viviendas.0.vehiculos.0', fn ($vehiculo) => ! collect($vehiculo)->has(['motivo', 'comprobante_path', 'residencial_id'])));
});

test('a resident with several houses sees each one with its own vehicles', function () {
    $otraCasa = ($this->vivienda)('C-02');
    ViviendaResidente::query()->create([
        'residencial_id' => $this->residencial->id,
        'vivienda_id' => $otraCasa->id,
        'residente_id' => $this->rosa->id,
        'tipo' => 'propietario',
        'es_responsable_pago' => true,
        'fecha_inicio' => '2025-01-01',
    ]);
    ($this->vehiculo)($this->casa);
    ($this->vehiculo)($otraCasa, ['placa' => 'OTR5678']);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('viviendas', 2)
        ->where('viviendas.0.codigo', 'A-01')
        ->where('viviendas.0.vehiculos.0.placa', 'HAB1234')
        ->where('viviendas.1.codigo', 'C-02')
        ->where('viviendas.1.vehiculos.0.placa', 'OTR5678'));
});

test('the resident does not see vehicles of other houses nor of other residenciales', function () {
    ($this->vehiculo)($this->vecina, ['placa' => 'VEC0001']);

    $ajena = ($this->vivienda)('A-01', $this->otro);
    ($this->vehiculo)($ajena, ['placa' => 'AJE0001']);
    ($this->persona)([$ajena], ['nombres' => 'Ajena']);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('viviendas', 1)
        ->where('viviendas.0.vehiculos', [])
        ->has('viviendas.0.residentes', 1));
});

test('a resident whose link ended no longer sees that house', function () {
    ($this->vehiculo)($this->casa);
    ViviendaResidente::query()->where('residente_id', $this->rosa->id)->update(['fecha_fin' => '2026-11-01']);

    ($this->ver)()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('viviendas', []));
});

test('the resident opens the proof of a vehicle of their house', function () {
    Storage::disk('local')->put('comprobantes-vehiculos/permiso.pdf', '%PDF-1.4 permiso');
    $vehiculo = ($this->sinPlaca)($this->casa);

    $respuesta = ($this->abrir)($vehiculo)->assertOk();

    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
});

test('a proof without a file answers not found', function () {
    $vehiculo = ($this->sinPlaca)($this->casa, ['comprobante_path' => null]);

    ($this->abrir)($vehiculo)->assertNotFound();
});

test('the resident cannot open the proof of a vehicle that is not theirs', function (string $caso) {
    Storage::disk('local')->put('comprobantes-vehiculos/permiso.pdf', '%PDF-1.4 permiso');

    $vehiculo = match ($caso) {
        'otra vivienda' => ($this->sinPlaca)($this->vecina),
        'dado de baja' => ($this->sinPlaca)($this->casa, ['activo' => false]),
        'con placa' => ($this->sinPlaca)($this->casa, ['placa' => 'HAB1234']),
    };

    ($this->abrir)($vehiculo)->assertForbidden();
})->with(['otra vivienda', 'dado de baja', 'con placa']);

test('the proof of a vehicle of another residencial cannot be opened', function () {
    Storage::disk('local')->put('comprobantes-vehiculos/permiso.pdf', '%PDF-1.4 permiso');
    $vehiculo = ($this->sinPlaca)(($this->vivienda)('A-01', $this->otro));

    ($this->abrir)($vehiculo)->assertForbidden();
});

test('a resident without a linked active resident cannot use the screen', function (string $caso) {
    $cuenta = ($this->usuario)(Rol::RESIDENTE);

    if ($caso === 'residente inactivo') {
        ($this->persona)([$this->casa], ['activo' => false], $cuenta);
    }

    ($this->ver)($cuenta)->assertForbidden();
    ($this->abrir)(($this->sinPlaca)($this->casa), $cuenta)->assertForbidden();
})->with(['sin residente', 'residente inactivo']);

test('other roles cannot use the portal screen', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    ($this->ver)($usuario)->assertForbidden();
    ($this->abrir)(($this->sinPlaca)($this->casa), $usuario)->assertForbidden();
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero', 'fiscal', 'vocal', 'cobrador', 'guardia']);

test('without the portal permission the resident cannot see the screen', function () {
    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->put(route('usuarios.permisos.update', ($this->rol)(Rol::RESIDENTE)), ['permisos' => []])
        ->assertSessionHasNoErrors();

    ($this->ver)()->assertForbidden();
    ($this->abrir)(($this->sinPlaca)($this->casa))->assertForbidden();
});

test('guests are sent to the login', function () {
    $this->get(route('portal.vehiculos.index'))->assertRedirect(route('login'));
});

test('the screen is read only and changes nothing', function () {
    $carro = ($this->vehiculo)($this->casa);
    ($this->sticker)($carro);
    ($this->carnet)($this->rosa);
    $antes = [Vehiculo::query()->get()->toArray(), Sticker::query()->get()->toArray(), Carnet::query()->get()->toArray(), Auditoria::query()->count()];

    ($this->ver)()->assertOk();

    expect([Vehiculo::query()->get()->toArray(), Sticker::query()->get()->toArray(), Carnet::query()->get()->toArray(), Auditoria::query()->count()])->toBe($antes);

    foreach (['post', 'put', 'patch', 'delete'] as $metodo) {
        $this->actingAs($this->cuentaRosa)->{$metodo}('/portal/mis-vehiculos')->assertMethodNotAllowed();
    }
});
