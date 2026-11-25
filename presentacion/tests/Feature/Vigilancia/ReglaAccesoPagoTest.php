<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Negocio\Services\QrResidenteService;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->crearContexto = function (string $nombre): array {
        $r = Residencial::query()->create(['nombre' => $nombre]);
        $crear = fn (string $rol) => User::factory()->create([
            'activo' => true, 'residencial_id' => $r->id, 'rol_id' => Rol::query()->where('codigo', $rol)->value('id'),
        ]);
        $presidente = $crear(Rol::PRESIDENTE);
        $guardia = $crear(Rol::GUARDIA);
        $cuenta = $crear(Rol::RESIDENTE);
        $ficha = Guardia::query()->create(['residencial_id' => $r->id, 'user_id' => $guardia->id,
            'identidad' => 'G-49', 'nombre' => 'Guardia ficticio', 'turno' => 'dia']);
        Turno::query()->create(['residencial_id' => $r->id, 'guardia_id' => $ficha->id, 'tipo' => 'dia',
            'inicio' => now()->subHour(), 'fin' => now()->addHour(), 'inicio_real' => now()->subHour()]);
        $residente = Residente::query()->create(['residencial_id' => $r->id, 'user_id' => $cuenta->id,
            'nombres' => 'Rosa', 'apellidos' => 'Ficticia']);
        $vivienda = Vivienda::query()->create(['residencial_id' => $r->id, 'codigo' => 'A-49', 'tipo_vivienda_id' => tipoVivienda($r->id)]);
        ViviendaResidente::query()->create(['residencial_id' => $r->id, 'residente_id' => $residente->id, 'vivienda_id' => $vivienda->id, 'tipo' => 'propietario']);
        $hoy = app(PeriodoService::class)->hoy();
        $carnet = Carnet::query()->create(['residencial_id' => $r->id, 'residente_id' => $residente->id, 'numero' => 'C-49',
            'estado' => Carnet::ACTIVO, 'fecha_emision' => $hoy->toDateString(), 'vigente_hasta' => $hoy->addYear()->toDateString()]);
        $token = app(QrResidenteService::class)->emitir($cuenta);

        return [$r, $presidente, $guardia, $vivienda, $token, $carnet];
    };

    [$this->residencial, $this->presidente, $this->guardia, $this->vivienda, $this->token, $this->carnet] = ($this->crearContexto)('Las Palmas');
    $this->hoy = app(PeriodoService::class)->hoy();
    $this->regla = fn (bool $activa, int $meses = 0, bool $pendiente = false) => $this->actingAs($this->presidente)
        ->put(route('finanzas.regla-acceso-pagos.update'), [
            'acceso_depende_pago' => $activa, 'meses_tolerancia_acceso' => $meses, 'pendiente_cuenta_al_dia' => $pendiente,
        ]);
    $this->escanear = fn () => $this->actingAs($this->guardia)
        ->postJson(route('vigilancia.qr.store'), ['codigo' => $this->token])->assertCreated();
    $this->cargo = function (int $mesesAtras, string $monto = '432.17'): int {
        $residencial = $this->residencial->id;
        $concepto = DB::table('conceptos_cobro')->where('residencial_id', $residencial)->where('nombre', 'Cuota ficticia')->value('id')
            ?? DB::table('conceptos_cobro')->insertGetId(['residencial_id' => $residencial, 'nombre' => 'Cuota ficticia', 'tipo' => 'recurrente']);

        return DB::table('cargos')->insertGetId(['residencial_id' => $residencial, 'vivienda_id' => $this->vivienda->id,
            'concepto_id' => $concepto, 'periodo' => $this->hoy->startOfMonth()->subMonths($mesesAtras)->toDateString(), 'monto' => $monto, 'origen' => 'sistema']);
    };
    $this->pago = fn (string $monto, string $estado, ?Vivienda $vivienda = null) => Pago::query()->forceCreate([
        'residencial_id' => ($vivienda ?? $this->vivienda)->residencial_id, 'vivienda_id' => ($vivienda ?? $this->vivienda)->id,
        'forma_pago' => Pago::TRANSFERENCIA, 'estado' => $estado, 'monto' => $monto, 'fecha_pago' => $this->hoy->toDateString(),
        'banco' => 'Banco ficticio', 'numero_referencia' => (string) Str::uuid(),
    ]);
});

test('the policy is off by default and disabling it restores the identity-only scan', function () {
    ($this->cargo)(1);
    ($this->escanear)()->assertJsonPath('resultado', 'permitido')->assertJsonMissingPath('indicacion');
    ($this->regla)(true)->assertSessionHasNoErrors();
    ($this->escanear)()->assertJsonPath('resultado', 'denegado');
    ($this->regla)(false)->assertSessionHasNoErrors();
    ($this->escanear)()->assertJsonPath('resultado', 'permitido')->assertJsonMissingPath('indicacion');
});

test('the authorized president configures only their residential and changes are audited', function () {
    $otro = Residencial::query()->create(['nombre' => 'El Bosque']);
    ($this->regla)(true, 2, true)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('configuracion_residencial', ['residencial_id' => $this->residencial->id,
        'acceso_depende_pago' => true, 'meses_tolerancia_acceso' => 2, 'pendiente_cuenta_al_dia' => true, 'meses_tolerancia' => 0]);
    $this->assertDatabaseMissing('configuracion_residencial', ['residencial_id' => $otro->id, 'acceso_depende_pago' => true]);
    $this->assertDatabaseHas('auditoria', ['residencial_id' => $this->residencial->id, 'user_id' => $this->presidente->id, 'accion' => 'regla_acceso_pago_cambiada']);
    ($this->regla)(true, 13, true)->assertSessionHasErrors('meses_tolerancia_acceso');
    $this->withoutVite()->get(route('finanzas.parametros.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reglaAcceso', ['acceso_depende_pago' => true, 'meses_tolerancia_acceso' => 2, 'pendiente_cuenta_al_dia' => true]));
});

test('a treasurer and guard cannot configure the policy even if they have another financial permission', function () {
    $tesorero = User::factory()->create(['activo' => true, 'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', 'tesorero')->value('id')]);
    foreach ([$tesorero, $this->guardia] as $cuenta) {
        $this->actingAs($cuenta)->putJson(route('finanzas.regla-acceso-pagos.update'), [
            'acceso_depende_pago' => true, 'meses_tolerancia_acceso' => 0, 'pendiente_cuenta_al_dia' => true,
        ])->assertForbidden();
    }
    $this->actingAs($tesorero)->withoutVite()->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reglaAcceso', null));
});

test('tolerance applies at the month boundary and denying reveals only the direction to the board', function () {
    ($this->cargo)(1);
    ($this->regla)(true, 1);
    $primero = ($this->escanear)()->assertJsonPath('resultado', 'permitido')->json('id');
    ($this->cargo)(2);
    $r = ($this->escanear)();
    $id = $r->json('id');
    $r->assertExactJson(['id' => $id, 'hora' => $r->json('hora'), 'resultado' => 'denegado', 'decision' => null,
        'nombre' => null, 'foto' => false, 'viviendas' => [], 'indicacion' => 'Dirija al residente a la Junta Directiva.']);
    $this->assertDatabaseHas('escaneos_qr', ['id' => $id, 'resultado' => 'denegado', 'qr_id' => null]);
    $this->get(route('vigilancia.qr.foto', $id))->assertNotFound();
    $this->postJson(route('vigilancia.qr.decidir', $primero), ['decision' => 'ingreso'])->assertConflict()
        ->assertJsonPath('message', 'Dirija al residente a la Junta Directiva.');
    $this->postJson(route('vigilancia.qr.decidir', $id), ['decision' => 'ingreso'])->assertConflict();
    $this->postJson(route('vigilancia.qr.decidir', $id), ['decision' => 'no_ingreso'])->assertNoContent();
});

test('only the enabled pending projection can cover complete overdue months', function (string $estado) {
    ($this->cargo)(2);
    ($this->cargo)(1);
    ($this->pago)('432.16', $estado);
    ($this->regla)(true, 1, true);
    ($this->escanear)()->assertJsonPath('resultado', 'denegado');
    ($this->pago)('0.01', $estado);
    ($this->escanear)()->assertJsonPath('resultado', 'permitido')->assertJsonMissingPath('indicacion');
    ($this->regla)(true, 1, false);
    ($this->escanear)()->assertJsonPath('resultado', 'denegado');
})->with([Pago::PENDIENTE_VERIFICACION, Pago::REPORTADO_POR_VECINO]);

test('missing or inconsistent financial data requires review and confirmed applied payments count normally', function () {
    ($this->regla)(true);
    $id = ($this->escanear)()->assertJsonPath('resultado', 'revisar')
        ->assertJsonPath('indicacion', 'Solicite revisión a la Junta Directiva.')->json('id');
    $this->postJson(route('vigilancia.qr.decidir', $id), ['decision' => 'ingreso'])->assertConflict();
    $cargo = ($this->cargo)(1);
    $pago = ($this->pago)('432.17', Pago::CONFIRMADO);
    ($this->escanear)()->assertJsonPath('resultado', 'revisar');
    PagoAplicacion::query()->create(['residencial_id' => $this->residencial->id, 'pago_id' => $pago->id,
        'cargo_id' => $cargo, 'monto' => '432.17']);
    ($this->escanear)()->assertJsonPath('resultado', 'permitido');
});

test('another residential cannot see the decision or cover the charge and an invalid card still denies anonymously', function () {
    [, , $otroGuardia, $otraVivienda] = ($this->crearContexto)('Otro residencial');
    ($this->pago)('900.00', Pago::PENDIENTE_VERIFICACION, $otraVivienda);
    ($this->cargo)(1);
    ($this->regla)(true, 0, true);
    ($this->escanear)()->assertJsonPath('resultado', 'denegado')->assertJsonStructure(['indicacion']);
    $this->actingAs($otroGuardia)->postJson(route('vigilancia.qr.store'), ['codigo' => $this->token])
        ->assertCreated()->assertJsonPath('resultado', 'denegado')->assertJsonPath('nombre', null)->assertJsonMissingPath('indicacion');
    $this->actingAs($this->presidente);
    $this->carnet->update(['estado' => Carnet::SUSPENDIDO]);
    ($this->escanear)()->assertJsonPath('resultado', 'denegado')->assertJsonMissingPath('indicacion');
});
