<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Notificaciones\SolicitudCorreccionAtendida;
use Patronato\Negocio\Services\SolicitudCorreccionService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\SolicitudCorreccion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

// HU-54: el residente solicita correcciones y la Junta Directiva las atiende desde una bandeja

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Storage::fake(SolicitudCorreccionService::DISCO);
    Notification::fake();

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

    // Residente con cuenta del portal y un vínculo vigente por cada vivienda
    $this->residente = function (array $viviendas, array $datos = [], ?Residencial $residencial = null): User {
        $residencial ??= $this->residencial;
        $cuenta = ($this->usuario)(Rol::RESIDENTE, $residencial);

        $residente = Residente::query()->withoutGlobalScopes()->create([
            'residencial_id' => $residencial->id,
            'user_id' => $cuenta->id,
            'nombres' => 'Rosa',
            'apellidos' => 'Vecina',
            'correo' => 'rosa.padron@example.com',
            'telefono' => '9999-0000',
            'activo' => true,
            ...$datos,
        ]);

        foreach ($viviendas as $vivienda) {
            ViviendaResidente::query()->withoutGlobalScopes()->create([
                'residencial_id' => $residencial->id,
                'vivienda_id' => $vivienda->id,
                'residente_id' => $residente->id,
                'tipo' => 'inquilino',
                'es_responsable_pago' => false,
                'fecha_inicio' => '2025-01-01',
            ]);
        }

        return $cuenta;
    };

    $this->rosa = ($this->residente)([$this->casa]);
    $this->residenteDe = fn (User $cuenta): Residente => Residente::query()->withoutGlobalScopes()->where('user_id', $cuenta->id)->sole();

    $this->presidente = ($this->usuario)(Rol::PRESIDENTE);

    $this->archivo = fn (string $nombre = 'factura.pdf', int $kb = 200, string $tipo = 'application/pdf') => UploadedFile::fake()->create($nombre, $kb, $tipo);

    $this->enviar = fn (array $datos = [], ?User $cuenta = null) => $this->actingAs($cuenta ?? $this->rosa)
        ->post(route('portal.solicitudes.store'), [
            'tipo' => 'vehiculo',
            'vivienda_id' => $this->casa->id,
            'descripcion' => 'La placa de mi carro es HAB1234, no HAB1243.',
            ...$datos,
        ]);

    // Solicitud creada directo en la tabla
    $this->solicitud = fn (array $datos = [], ?User $cuenta = null): SolicitudCorreccion => SolicitudCorreccion::query()->withoutGlobalScopes()->create([
        'residencial_id' => ($this->residenteDe)($cuenta ?? $this->rosa)->residencial_id,
        'residente_id' => ($this->residenteDe)($cuenta ?? $this->rosa)->id,
        'vivienda_id' => $this->casa->id,
        'tipo' => 'datos_personales',
        'descripcion' => 'Mi teléfono cambió a 9999-1111.',
        'estado' => SolicitudCorreccion::RECIBIDA,
        ...$datos,
    ]);

    $this->accion = fn (string $accion, SolicitudCorreccion $solicitud, array $datos = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route("solicitudes.{$accion}", $solicitud->id), $datos);
});

// Solicitud desde el portal

test('the resident sends a request with type, description and an attached file', function () {
    ($this->enviar)(['adjunto' => ($this->archivo)()])
        ->assertRedirect(route('portal.solicitudes.index'))
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudCorreccion::query()->sole();

    expect($solicitud->tipo)->toBe('vehiculo')
        ->and($solicitud->vivienda_id)->toBe($this->casa->id)
        ->and($solicitud->residente_id)->toBe(($this->residenteDe)($this->rosa)->id)
        ->and($solicitud->descripcion)->toBe('La placa de mi carro es HAB1234, no HAB1243.')
        ->and($solicitud->estado)->toBe(SolicitudCorreccion::RECIBIDA)
        ->and($solicitud->respuesta)->toBeNull();

    Storage::disk(SolicitudCorreccionService::DISCO)->assertExists((string) $solicitud->adjunto_path);
    expect($solicitud->adjunto_path)->toStartWith("solicitudes-correccion/{$this->residencial->id}/");

    $auditoria = Auditoria::query()->where('accion', 'solicitud_correccion_creada')->sole();
    expect($auditoria->user_id)->toBe($this->rosa->id)
        ->and($auditoria->modulo)->toBe('portal');
});

test('the attached file is optional', function () {
    ($this->enviar)()->assertSessionHasNoErrors();

    expect(SolicitudCorreccion::query()->sole()->adjunto_path)->toBeNull();
});

test('every type of request can be sent', function (string $tipo) {
    ($this->enviar)(['tipo' => $tipo])->assertSessionHasNoErrors();

    expect(SolicitudCorreccion::query()->sole()->tipo)->toBe($tipo);
})->with(array_keys(SolicitudCorreccion::TIPOS));

test('the request is validated', function (array $datos, string $campo) {
    ($this->enviar)($datos)->assertSessionHasErrors($campo);

    expect(SolicitudCorreccion::query()->count())->toBe(0);
})->with([
    'sin tipo' => [['tipo' => ''], 'tipo'],
    'tipo desconocido' => [['tipo' => 'saldo'], 'tipo'],
    'sin vivienda' => [['vivienda_id' => ''], 'vivienda_id'],
    'sin descripción' => [['descripcion' => '   '], 'descripcion'],
    'descripción corta' => [['descripcion' => 'Corregir'], 'descripcion'],
    'descripción larga' => [['descripcion' => str_repeat('a', 2001)], 'descripcion'],
    'archivo de otro tipo' => [['adjunto' => UploadedFile::fake()->create('datos.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')], 'adjunto'],
    'archivo muy grande' => [['adjunto' => UploadedFile::fake()->create('factura.pdf', 5121, 'application/pdf')], 'adjunto'],
]);

test('a failed request does not keep the attached file', function () {
    ($this->enviar)(['vivienda_id' => $this->vecina->id, 'adjunto' => ($this->archivo)()])->assertSessionHasErrors('vivienda_id');

    expect(Storage::disk(SolicitudCorreccionService::DISCO)->allFiles())->toBe([]);
});

test('the resident can only send requests about a house where they live today', function () {
    $ajena = ($this->vivienda)('A-01', $this->otro);

    ($this->enviar)(['vivienda_id' => $this->vecina->id])->assertSessionHasErrors(['vivienda_id' => 'Solo puedes pedir correcciones de una vivienda donde vives.']);
    ($this->enviar)(['vivienda_id' => $ajena->id])->assertSessionHasErrors('vivienda_id');

    expect(SolicitudCorreccion::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('sending a request changes no data of the resident', function () {
    $antes = ($this->residenteDe)($this->rosa)->toArray();

    ($this->enviar)(['tipo' => 'datos_personales', 'descripcion' => 'Mi teléfono cambió a 9999-1111.'])->assertSessionHasNoErrors();

    expect(($this->residenteDe)($this->rosa)->fresh()?->toArray())->toBe($antes);
});

test('the resident sees their requests with their status and the answer once closed', function () {
    ($this->solicitud)();
    ($this->solicitud)(['estado' => SolicitudCorreccion::EN_REVISION, 'respuesta' => 'Borrador interno']);
    ($this->solicitud)(['estado' => SolicitudCorreccion::RESUELTA, 'respuesta' => 'Ya actualizamos tu teléfono.']);
    ($this->solicitud)(['estado' => SolicitudCorreccion::RECHAZADA, 'respuesta' => 'El pago no llegó a la cuenta.', 'tipo' => 'pago']);

    $this->actingAs($this->rosa)
        ->get(route('portal.solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/solicitudes')
            ->has('solicitudes', 4)
            ->where('solicitudes.0.estado', SolicitudCorreccion::RECHAZADA)
            ->where('solicitudes.0.respuesta', 'El pago no llegó a la cuenta.')
            ->where('solicitudes.0.vivienda', 'A-01')
            ->where('solicitudes.1.estado', SolicitudCorreccion::RESUELTA)
            ->where('solicitudes.1.respuesta', 'Ya actualizamos tu teléfono.')
            ->where('solicitudes.2.estado', SolicitudCorreccion::EN_REVISION)
            ->where('solicitudes.2.respuesta', null)
            ->where('solicitudes.3.estado', SolicitudCorreccion::RECIBIDA)
            ->where('tipos', SolicitudCorreccion::TIPOS)
            ->has('viviendas', 1));
});

test('the resident does not see the requests of other residents', function () {
    $vecino = ($this->residente)([$this->casa], ['nombres' => 'Luis']);
    ($this->solicitud)([], $vecino);

    $this->actingAs($this->rosa)
        ->get(route('portal.solicitudes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('solicitudes', []));
});

test('the form opens with the type and house already chosen', function () {
    $this->actingAs($this->rosa)
        ->get(route('portal.solicitudes.index', ['tipo' => 'pago', 'vivienda' => $this->casa->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inicial.tipo', 'pago')
            ->where('inicial.vivienda_id', $this->casa->id));

    $this->actingAs($this->rosa)
        ->get(route('portal.solicitudes.index', ['tipo' => 'saldo']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inicial.tipo', null)
            ->where('inicial.vivienda_id', null));
});

test('the resident opens the file of their own request only', function () {
    Storage::disk(SolicitudCorreccionService::DISCO)->put('solicitudes-correccion/1/factura.pdf', '%PDF-1.4 factura');
    $propia = ($this->solicitud)(['adjunto_path' => 'solicitudes-correccion/1/factura.pdf']);

    $vecino = ($this->residente)([$this->casa], ['nombres' => 'Luis']);
    $ajena = ($this->solicitud)(['adjunto_path' => 'solicitudes-correccion/1/factura.pdf'], $vecino);

    $this->actingAs($this->rosa)->get(route('portal.solicitudes.adjunto', $propia->id))->assertOk();
    $this->actingAs($this->rosa)->get(route('portal.solicitudes.adjunto', $ajena->id))->assertForbidden();
    $this->actingAs($this->rosa)->get(route('portal.solicitudes.adjunto', ($this->solicitud)()->id))->assertNotFound();
});

test('a resident without a linked active resident cannot use the requests', function (string $caso) {
    $cuenta = match ($caso) {
        'sin residente' => ($this->usuario)(Rol::RESIDENTE),
        'residente inactivo' => ($this->residente)([$this->casa], ['activo' => false]),
    };

    $this->actingAs($cuenta)->get(route('portal.solicitudes.index'))->assertForbidden();
    ($this->enviar)([], $cuenta)->assertForbidden();
})->with(['sin residente', 'residente inactivo']);

test('without the register permission the resident sees the requests but cannot send them', function () {
    $this->actingAs(($this->usuario)(Rol::ADMIN_TECNICO))
        ->put(route('usuarios.permisos.update', ($this->rol)(Rol::RESIDENTE)), ['permisos' => ['portal.ver']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rosa)->get(route('portal.solicitudes.index'))->assertOk();
    ($this->enviar)()->assertForbidden();

    expect(SolicitudCorreccion::query()->count())->toBe(0);
});

test('other roles cannot use the portal requests', function (string $rol) {
    $usuario = ($this->usuario)($rol);

    $this->actingAs($usuario)->get(route('portal.solicitudes.index'))->assertForbidden();
    ($this->enviar)([], $usuario)->assertForbidden();
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero', 'fiscal', 'vocal', 'cobrador', 'guardia']);

test('guests are sent to the login', function () {
    $this->get(route('portal.solicitudes.index'))->assertRedirect(route('login'));
    $this->get(route('solicitudes.index'))->assertRedirect(route('login'));
});

// Bandeja de la Junta Directiva

test('the board sees the open requests from the oldest to the newest', function () {
    ($this->solicitud)(['tipo' => 'pago']);
    ($this->solicitud)(['estado' => SolicitudCorreccion::EN_REVISION]);
    ($this->solicitud)(['estado' => SolicitudCorreccion::RESUELTA, 'respuesta' => 'Listo.']);

    $this->actingAs($this->presidente)
        ->get(route('solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('solicitudes/index')
            ->where('vista', 'abiertas')
            ->has('solicitudes.data', 2)
            ->where('solicitudes.data.0.tipo', 'pago')
            ->where('solicitudes.data.0.residente', 'Rosa Vecina')
            ->where('solicitudes.data.0.vivienda', 'A-01')
            ->where('solicitudes.data.0.puede_tomar', true)
            ->where('solicitudes.data.0.puede_cerrar', true)
            ->where('solicitudes.data.1.estado', SolicitudCorreccion::EN_REVISION)
            ->where('solicitudes.data.1.puede_tomar', false)
            ->where('solicitudes.data.1.puede_cerrar', true));

    $this->actingAs($this->presidente)
        ->get(route('solicitudes.index', ['vista' => 'cerradas']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vista', 'cerradas')
            ->has('solicitudes.data', 1)
            ->where('solicitudes.data.0.respuesta', 'Listo.')
            ->where('solicitudes.data.0.puede_cerrar', false));
});

test('the board does not see requests of another residencial', function () {
    $ajena = ($this->residente)([($this->vivienda)('P-01', $this->otro)], [], $this->otro);
    $solicitud = SolicitudCorreccion::query()->withoutGlobalScopes()->create([
        'residencial_id' => $this->otro->id,
        'residente_id' => ($this->residenteDe)($ajena)->id,
        'tipo' => 'otro',
        'descripcion' => 'Solicitud de otro residencial.',
        'estado' => SolicitudCorreccion::RECIBIDA,
    ]);

    $this->actingAs($this->presidente)
        ->get(route('solicitudes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('solicitudes.data', []));

    ($this->accion)('revisar', $solicitud)->assertForbidden();
    ($this->accion)('resolver', $solicitud, ['respuesta' => 'Corregido.'])->assertForbidden();
    $this->actingAs($this->presidente)->get(route('solicitudes.adjunto', $solicitud->id))->assertForbidden();

    expect($solicitud->fresh()?->estado)->toBe(SolicitudCorreccion::RECIBIDA);
});

test('taking a request puts it in review and records who took it', function () {
    $solicitud = ($this->solicitud)();

    ($this->accion)('revisar', $solicitud)->assertSessionHasNoErrors();

    $solicitud->refresh();
    expect($solicitud->estado)->toBe(SolicitudCorreccion::EN_REVISION)
        ->and($solicitud->atendida_por)->toBe($this->presidente->id);

    expect(Auditoria::query()->where('accion', 'solicitud_correccion_en_revision')->sole()->user_id)->toBe($this->presidente->id);
    Notification::assertNothingSent();
});

test('a request that is no longer received cannot be taken again', function () {
    $solicitud = ($this->solicitud)(['estado' => SolicitudCorreccion::EN_REVISION]);

    ($this->accion)('revisar', $solicitud)->assertSessionHasErrors('solicitud');
});

test('resolving a request saves the answer and emails the resident', function (string $estadoInicial) {
    $solicitud = ($this->solicitud)(['estado' => $estadoInicial]);

    ($this->accion)('resolver', $solicitud, ['respuesta' => '  Actualizamos tu teléfono a 9999-1111.  '])->assertSessionHasNoErrors();

    $solicitud->refresh();
    expect($solicitud->estado)->toBe(SolicitudCorreccion::RESUELTA)
        ->and($solicitud->respuesta)->toBe('Actualizamos tu teléfono a 9999-1111.')
        ->and($solicitud->atendida_por)->toBe($this->presidente->id);

    $auditoria = Auditoria::query()->where('accion', 'solicitud_correccion_resuelta')->sole();
    expect($auditoria->modulo)->toBe('solicitudes');

    Notification::assertSentOnDemand(
        SolicitudCorreccionAtendida::class,
        fn (SolicitudCorreccionAtendida $aviso, array $canales, AnonymousNotifiable $destino): bool => $aviso->resuelta
            && $aviso->respuesta === 'Actualizamos tu teléfono a 9999-1111.'
            && $aviso->vivienda === 'A-01'
            && $aviso->residencial === 'Las Palmas'
            && $aviso->tipo === 'Mis datos personales'
            && $destino->routes['mail'] === [$this->rosa->email, 'rosa.padron@example.com'],
    );
})->with([SolicitudCorreccion::RECIBIDA, SolicitudCorreccion::EN_REVISION]);

test('rejecting a request saves the reason and emails the resident', function () {
    $solicitud = ($this->solicitud)(['tipo' => 'pago']);

    ($this->accion)('rechazar', $solicitud, ['respuesta' => 'El pago no aparece en la cuenta del banco.'])->assertSessionHasNoErrors();

    expect($solicitud->fresh()?->estado)->toBe(SolicitudCorreccion::RECHAZADA);
    expect(Auditoria::query()->where('accion', 'solicitud_correccion_rechazada')->count())->toBe(1);

    Notification::assertSentOnDemand(
        SolicitudCorreccionAtendida::class,
        fn (SolicitudCorreccionAtendida $aviso): bool => ! $aviso->resuelta && $aviso->tipo === 'Pago que no aparece',
    );
});

test('the answer is required to close a request', function (string $accion, string $respuesta) {
    $solicitud = ($this->solicitud)();

    ($this->accion)($accion, $solicitud, ['respuesta' => $respuesta])->assertSessionHasErrors('respuesta');

    expect($solicitud->fresh()?->estado)->toBe(SolicitudCorreccion::RECIBIDA);
    Notification::assertNothingSent();
})->with([
    ['resolver', ''],
    ['resolver', '  ok  '],
    ['rechazar', ''],
    ['rechazar', str_repeat('a', 1001)],
]);

test('a closed request cannot be changed nor reopened', function (string $estado, string $accion) {
    $solicitud = ($this->solicitud)(['estado' => $estado, 'respuesta' => 'Respuesta original.']);

    ($this->accion)($accion, $solicitud, ['respuesta' => 'Otra respuesta distinta.'])->assertSessionHasErrors('solicitud');

    $solicitud->refresh();
    expect($solicitud->estado)->toBe($estado)
        ->and($solicitud->respuesta)->toBe('Respuesta original.');
    Notification::assertNothingSent();
})->with([
    [SolicitudCorreccion::RESUELTA, 'rechazar'],
    [SolicitudCorreccion::RECHAZADA, 'resolver'],
    [SolicitudCorreccion::RESUELTA, 'revisar'],
]);

test('closing a request does not change the data of the resident', function () {
    $antes = ($this->residenteDe)($this->rosa)->toArray();

    ($this->accion)('resolver', ($this->solicitud)(), ['respuesta' => 'Actualizamos tu teléfono.'])->assertSessionHasNoErrors();

    expect(($this->residenteDe)($this->rosa)->fresh()?->toArray())->toBe($antes);
});

test('a failed email does not undo the closing', function () {
    Notification::shouldReceive('route')->andThrow(new RuntimeException('Servidor de correo caído'));
    $solicitud = ($this->solicitud)();

    ($this->accion)('resolver', $solicitud, ['respuesta' => 'Actualizamos tu teléfono.'])->assertSessionHasNoErrors();

    expect($solicitud->fresh()?->estado)->toBe(SolicitudCorreccion::RESUELTA);
});

test('the board opens the attached file', function () {
    Storage::disk(SolicitudCorreccionService::DISCO)->put('solicitudes-correccion/1/factura.pdf', '%PDF-1.4 factura');
    $solicitud = ($this->solicitud)(['adjunto_path' => 'solicitudes-correccion/1/factura.pdf']);

    $this->actingAs(($this->usuario)('fiscal'))->get(route('solicitudes.adjunto', $solicitud->id))->assertOk();
    $this->actingAs($this->presidente)->get(route('solicitudes.adjunto', ($this->solicitud)()->id))->assertNotFound();
});

test('the president, secretary and treasurer attend the requests', function (string $rol) {
    $usuario = ($this->usuario)($rol);
    $solicitud = ($this->solicitud)();

    $this->actingAs($usuario)->get(route('solicitudes.index'))->assertOk();
    ($this->accion)('revisar', $solicitud, [], $usuario)->assertSessionHasNoErrors();
    ($this->accion)('resolver', $solicitud, ['respuesta' => 'Corregido en el padrón.'], $usuario)->assertSessionHasNoErrors();

    expect($solicitud->fresh()?->atendida_por)->toBe($usuario->id);
})->with([Rol::PRESIDENTE, Rol::SECRETARIO, 'tesorero']);

test('the fiscal sees the requests but cannot attend them', function () {
    $fiscal = ($this->usuario)('fiscal');
    $solicitud = ($this->solicitud)();

    $this->actingAs($fiscal)
        ->get(route('solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('solicitudes.data.0.puede_tomar', false)
            ->where('solicitudes.data.0.puede_cerrar', false));

    ($this->accion)('revisar', $solicitud, [], $fiscal)->assertForbidden();
    ($this->accion)('resolver', $solicitud, ['respuesta' => 'Corregido.'], $fiscal)->assertForbidden();
    ($this->accion)('rechazar', $solicitud, ['respuesta' => 'No procede.'], $fiscal)->assertForbidden();

    expect($solicitud->fresh()?->estado)->toBe(SolicitudCorreccion::RECIBIDA);
});

test('other roles cannot see the inbox', function (string $rol) {
    $usuario = ($this->usuario)($rol);
    $solicitud = ($this->solicitud)();

    $this->actingAs($usuario)->get(route('solicitudes.index'))->assertForbidden();
    ($this->accion)('resolver', $solicitud, ['respuesta' => 'Corregido.'], $usuario)->assertForbidden();
})->with(['vocal', 'cobrador', 'guardia', Rol::RESIDENTE]);

test('the menu shows how many requests nobody has taken', function () {
    ($this->solicitud)();
    ($this->solicitud)();
    ($this->solicitud)(['estado' => SolicitudCorreccion::EN_REVISION]);

    $this->actingAs($this->presidente)
        ->get(route('solicitudes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.solicitudesRecibidas', 2));

    $this->actingAs(($this->usuario)('vocal'))
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.solicitudesRecibidas', 0));
});
