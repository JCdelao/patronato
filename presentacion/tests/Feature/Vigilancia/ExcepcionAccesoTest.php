<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Negocio\Services\QrResidenteService;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;
use Tests\TestCase;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 03:00:00', 'UTC'));
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

        DB::table('configuracion_residencial')->updateOrInsert(['residencial_id' => $r->id], ['acceso_depende_pago' => true]);
        $concepto = DB::table('conceptos_cobro')->insertGetId(['residencial_id' => $r->id, 'nombre' => 'Cuota ficticia', 'tipo' => 'recurrente']);
        DB::table('cargos')->insert(['residencial_id' => $r->id, 'vivienda_id' => $vivienda->id,
            'concepto_id' => $concepto, 'periodo' => '2026-01-01', 'monto' => '100.00', 'origen' => 'sistema']);

        return [$r, $presidente, $guardia, $vivienda, $token, $carnet];
    };

});

test('an exception permits access until the exact Honduras deadline, including decision revalidation', function () {
    [$r, $presidente, $guardia, $vivienda, $token] = ($this->crearContexto)('Residencial HU-50');
    concederExcepcion($this, $presidente, $vivienda, '2026-11-24T21:02')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('excepciones_acceso', ['residencial_id' => $r->id, 'vivienda_id' => $vivienda->id,
        'motivo' => 'Acuerdo aprobado', 'autorizado_por' => $presidente->id, 'vence_en' => '2026-11-25 03:02:00']);
    $this->assertDatabaseHas('auditoria', ['residencial_id' => $r->id, 'user_id' => $presidente->id, 'accion' => 'excepcion_acceso_concedida']);

    $this->travel(119)->seconds();
    $permitido = $this->actingAs($guardia)->postJson(route('vigilancia.qr.store'), ['codigo' => $token])
        ->assertCreated()->assertJsonPath('resultado', 'permitido')->assertJsonMissingPath('indicacion');
    expect($permitido->getContent())->not->toContain('Acuerdo aprobado');
    $this->travel(1)->second();
    $this->postJson(route('vigilancia.qr.decidir', $permitido->json('id')), ['decision' => 'ingreso'])->assertConflict();
    $this->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()
        ->assertJsonPath('resultado', 'denegado')->assertJsonPath('indicacion', 'Dirija al residente a la Junta Directiva.');
    $this->assertDatabaseCount('visitas', 0);
});

test('permission, same residential, deadline and one active exception are enforced on the server', function () {
    [$r, $presidente, $guardia, $vivienda] = ($this->crearContexto)('Residencial principal');
    [, , , $otra] = ($this->crearContexto)('Otro residencial');
    concederExcepcion($this, $guardia, $vivienda, '2026-11-24T21:30')->assertForbidden();
    concederExcepcion($this, $presidente, $otra, '2026-11-24T21:30')->assertSessionHasErrors('vivienda_id');
    concederExcepcion($this, $presidente, $vivienda, '2026-11-24T21:00')->assertSessionHasErrors('vence_en');
    concederExcepcion($this, $presidente, $vivienda, '2026-11-31T21:00')->assertSessionHasErrors('vence_en');
    concederExcepcion($this, $presidente, $vivienda, '2026-11-24T21:30')->assertSessionHasNoErrors();
    concederExcepcion($this, $presidente, $vivienda, '2026-11-24T21:31')->assertSessionHasErrors('vivienda_id');
    $this->assertDatabaseCount('excepciones_acceso', 1);
});

test('the exception cannot override invalid credentials or a different residential', function () {
    [$r, $presidente, $guardia, $vivienda, $token, $carnet] = ($this->crearContexto)('Principal');
    [, , $otroGuardia] = ($this->crearContexto)('Otro');
    $vence = now('America/Tegucigalpa')->addHour()->format('Y-m-d\TH:i');
    concederExcepcion($this, $presidente, $vivienda, $vence)->assertSessionHasNoErrors();
    $this->actingAs($otroGuardia)->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()->assertJsonPath('resultado', 'denegado');
    $this->actingAs($guardia)->postJson(route('vigilancia.qr.store'), ['codigo' => 'invalido'])->assertCreated()->assertJsonPath('resultado', 'denegado');
    $cuenta = Residente::query()->whereKey($carnet->residente_id)->value('user_id');
    User::query()->whereKey($cuenta)->update(['activo' => false]);
    $this->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()->assertJsonPath('resultado', 'denegado');
    User::query()->whereKey($cuenta)->update(['activo' => true]);
    $carnet->update(['estado' => Carnet::SUSPENDIDO]);
    $this->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()->assertJsonPath('resultado', 'denegado');
    $carnet->update(['estado' => Carnet::ACTIVO, 'vigente_hasta' => now()->subDays(2)->toDateString()]);
    $this->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()->assertJsonPath('resultado', 'denegado');
});

test('absent exceptions do not change denial and the disabled policy admits valid credentials', function () {
    [$r, $presidente, $guardia, $vivienda, $token] = ($this->crearContexto)('Residencial de prueba');
    $this->actingAs($guardia)->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()->assertJsonPath('resultado', 'denegado');
    DB::table('configuracion_residencial')->where('residencial_id', $r->id)->update(['acceso_depende_pago' => false]);
    concederExcepcion($this, $presidente, $vivienda, now('America/Tegucigalpa')->addHour()->format('Y-m-d\TH:i'))->assertSessionHasErrors('vivienda_id');
    $this->actingAs($guardia)->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated()->assertJsonPath('resultado', 'permitido');
    $this->assertDatabaseCount('excepciones_acceso', 0);
});

function concederExcepcion(TestCase $prueba, User $usuario, Vivienda $vivienda, string $vence): TestResponse
{
    return $prueba->actingAs($usuario)->post(route('finanzas.excepciones-acceso.store'), [
        'vivienda_id' => $vivienda->id, 'vence_en' => $vence, 'motivo' => 'Acuerdo aprobado', 'autorizado_por' => 999999,
    ]);
}
