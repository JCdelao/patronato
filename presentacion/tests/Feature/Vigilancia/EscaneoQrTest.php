<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Patronato\Negocio\Services\QrResidenteService;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\QrResidente;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->crearGuardia = function (Residencial $residencial): array {
        $cuenta = User::factory()->create(['activo' => true, 'residencial_id' => $residencial->id, 'rol_id' => Rol::query()->where('codigo', Rol::GUARDIA)->value('id')]);
        $guardia = Guardia::query()->create(['residencial_id' => $residencial->id, 'user_id' => $cuenta->id, 'identidad' => 'G-'.Str::random(8), 'nombre' => 'Guardia de prueba', 'turno' => 'dia']);
        Turno::query()->create(['residencial_id' => $residencial->id, 'guardia_id' => $guardia->id, 'tipo' => 'dia',
            'inicio' => now()->subHour(), 'fin' => now()->addHour(), 'inicio_real' => now()->subHour()]);

        return [$cuenta, $guardia];
    };
    [$this->cuenta, $this->guardia] = ($this->crearGuardia)($this->residencial);
    $this->cuentaResidente = User::factory()->create(['activo' => true, 'residencial_id' => $this->residencial->id, 'rol_id' => Rol::query()->where('codigo', Rol::RESIDENTE)->value('id')]);
    $this->residente = Residente::query()->create(['residencial_id' => $this->residencial->id, 'user_id' => $this->cuentaResidente->id,
        'nombres' => 'Rosa', 'apellidos' => 'Vecina', 'identidad' => 'PRIVADA']);
    $this->carnet = Carnet::query()->create(['residencial_id' => $this->residencial->id, 'residente_id' => $this->residente->id,
        'numero' => 'C-01', 'estado' => Carnet::ACTIVO, 'fecha_emision' => '2025-01-01', 'vigente_hasta' => '2099-01-01']);
    $vivienda = Vivienda::query()->create(['residencial_id' => $this->residencial->id,
        'tipo_vivienda_id' => TipoVivienda::query()->where('residencial_id', $this->residencial->id)->firstOrFail()->id, 'codigo' => 'A-01']);
    $this->vinculo = ViviendaResidente::query()->create(['residencial_id' => $this->residencial->id,
        'residente_id' => $this->residente->id, 'vivienda_id' => $vivienda->id, 'tipo' => 'propietario']);
    $this->token = app(QrResidenteService::class)->emitir($this->cuentaResidente);
});

test('only authorized guards on duty can submit scans', function () {
    $this->postJson(route('vigilancia.qr.store'))->assertUnauthorized();
    $this->actingAs($this->cuentaResidente)->postJson(route('vigilancia.qr.store'))->assertForbidden();
    Turno::query()->where('guardia_id', $this->guardia->id)->delete();
    $this->actingAs($this->cuenta)->postJson(route('vigilancia.qr.store'))->assertUnprocessable();
    $this->assertDatabaseCount('escaneos_qr', 0);
});

test('valid scan exposes only necessary data and records the separate final decision', function () {
    $this->freezeTime();
    $respuesta = $this->actingAs($this->cuenta)->postJson(route('vigilancia.qr.store'), ['codigo' => $this->token])->assertCreated();
    $id = $respuesta->json('id');
    $respuesta->assertExactJson(['id' => $id, 'hora' => now()->toIso8601String(), 'resultado' => 'permitido',
        'decision' => null, 'nombre' => 'Rosa Vecina', 'viviendas' => ['A-01'], 'foto' => false]);
    $this->assertDatabaseHas('escaneos_qr', ['id' => $id, 'guardia_id' => $this->guardia->id,
        'escaneado_en' => now()->format('Y-m-d H:i:s'), 'resultado' => 'permitido', 'decision_final' => null]);
    $url = route('vigilancia.qr.decidir', $id);
    $this->postJson($url, ['decision' => 'ingreso'])->assertNoContent();
    $this->postJson($url, ['decision' => 'ingreso'])->assertNoContent();
    $this->postJson($url, ['decision' => 'no_ingreso'])->assertConflict();
    $this->assertDatabaseHas('escaneos_qr', ['id' => $id, 'decision_final' => 'ingreso']);
    $this->assertDatabaseCount('visitas', 0);
});

test('a valid resident without a current home is sent for review', function () {
    $this->vinculo->update(['fecha_fin' => now()->subDay()]);
    $id = $this->actingAs($this->cuenta)->postJson(route('vigilancia.qr.store'), ['codigo' => $this->token])
        ->assertCreated()->assertJsonPath('resultado', 'revisar')->assertJsonPath('viviendas', [])->json('id');
    $this->postJson(route('vigilancia.qr.decidir', $id), ['decision' => 'ingreso'])->assertConflict();
    $this->postJson(route('vigilancia.qr.decidir', $id), ['decision' => 'no_ingreso'])->assertNoContent();
});

test('ineligible codes are denied without disclosing identity', function (string $caso) {
    $token = $this->token;
    match ($caso) {
        'malformado' => $token = 'texto arbitrario',
        'desconocido' => $token = (string) Str::uuid(),
        'regenerado' => app(QrResidenteService::class)->emitir($this->cuentaResidente, true),
        'revocado' => QrResidente::query()->where('residente_id', $this->residente->id)->update(['activo' => false]),
        'cuenta' => $this->cuentaResidente->forceFill(['activo' => false])->save(),
        'residente' => $this->residente->update(['activo' => false]),
        'carnet' => $this->carnet->update(['estado' => Carnet::SUSPENDIDO]),
        'vencido' => $this->carnet->update(['vigente_hasta' => now()->subDays(2)->toDateString()]),
        'otro residencial' => [$this->cuenta] = ($this->crearGuardia)(Residencial::query()->create(['nombre' => 'Otro residencial'])),
    };
    $r = $this->actingAs($this->cuenta)->postJson(route('vigilancia.qr.store'), ['codigo' => $token])->assertCreated();
    $r->assertExactJson(['id' => $r->json('id'), 'hora' => $r->json('hora'), 'resultado' => 'denegado',
        'decision' => null, 'nombre' => null, 'viviendas' => [], 'foto' => false]);
    $this->assertDatabaseHas('escaneos_qr', ['id' => $r->json('id'), 'qr_id' => null]);
})->with(['malformado', 'desconocido', 'regenerado', 'revocado', 'cuenta', 'residente', 'carnet', 'vencido', 'otro residencial']);

test('repeat scans are audited separately and regeneration blocks a pending admission', function () {
    $this->actingAs($this->cuenta);
    $datos = ['codigo' => $this->token];
    $primero = $this->postJson(route('vigilancia.qr.store'), $datos)->assertCreated()->json('id');
    $segundo = $this->postJson(route('vigilancia.qr.store'), $datos)->assertCreated()->json('id');
    expect($segundo)->not->toBe($primero);
    $this->assertDatabaseCount('escaneos_qr', 2);
    app(QrResidenteService::class)->emitir($this->cuentaResidente, true);
    $this->postJson(route('vigilancia.qr.decidir', $primero), ['decision' => 'ingreso'])->assertConflict();
    [$otro] = ($this->crearGuardia)($this->residencial);
    $this->actingAs($otro)->postJson(route('vigilancia.qr.decidir', $primero), ['decision' => 'no_ingreso'])->assertNotFound();
});

test('resident photo is served privately to the guard who scanned the code', function () {
    Storage::fake('local')->put('residentes/foto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='));
    $this->residente->forceFill(['foto_path' => 'residentes/foto.png'])->save();
    $id = $this->actingAs($this->cuenta)->postJson(route('vigilancia.qr.store'), ['codigo' => $this->token])
        ->assertCreated()->assertJsonPath('foto', true)->json('id');
    $this->get(route('vigilancia.qr.foto', $id))->assertOk()->assertHeader('Content-Type', 'image/png');
    [$otro] = ($this->crearGuardia)($this->residencial);
    $this->actingAs($otro)->get(route('vigilancia.qr.foto', $id))->assertNotFound();
});
