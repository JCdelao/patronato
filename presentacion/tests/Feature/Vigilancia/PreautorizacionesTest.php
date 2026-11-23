<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 06:10:00', 'UTC'));
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
    $this->residencial = Residencial::query()->create(['nombre' => 'HU-44']);
    $this->otro = Residencial::query()->create(['nombre' => 'HU-44 ajeno']);
    $this->cuenta = fn (string $rol): User => User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', $rol)->value('id'),
    ]);
    $this->usuario = ($this->cuenta)(Rol::RESIDENTE);
    $this->guardia = ($this->cuenta)(Rol::GUARDIA);
    Guardia::query()->create([
        'residencial_id' => $this->residencial->id, 'user_id' => $this->guardia->id,
        'nombre' => 'Guardia de pruebas', 'identidad' => 'G-44', 'turno' => 'dia',
    ]);
    $this->residente = Residente::query()->create([
        'residencial_id' => $this->residencial->id, 'user_id' => $this->usuario->id,
        'nombres' => 'Residente', 'apellidos' => 'Ficticio',
    ]);
    $this->casa = fn (Residencial $r): Vivienda => Vivienda::query()->withoutGlobalScope('residencial')->create([
        'residencial_id' => $r->id, 'codigo' => 'P-'.Str::random(5), 'tipo_vivienda_id' => tipoVivienda($r->id),
    ]);
    $this->vivienda = ($this->casa)($this->residencial);
    $this->sinVinculo = ($this->casa)($this->residencial);
    $this->ajena = ($this->casa)($this->otro);
    $this->vinculo = ViviendaResidente::query()->create([
        'residencial_id' => $this->residencial->id, 'residente_id' => $this->residente->id,
        'vivienda_id' => $this->vivienda->id, 'tipo' => 'familiar',
    ]);
    $this->medio = fn (Residencial $r, string $nombre): MedioEntrada => MedioEntrada::query()->withoutGlobalScope('residencial')
        ->where('residencial_id', $r->id)->where('nombre', $nombre)->firstOrFail();
    $this->aPie = ($this->medio)($this->residencial, 'A pie');
    $this->datos = [
        'vivienda_id' => $this->vivienda->id, 'medio_entrada_id' => $this->aPie->id,
        'nombre_visitante' => 'Visitante ficticio', 'inicio' => '2026-11-22T23:55', 'fin' => '2026-11-23T00:20',
    ];
    $this->alta = route('portal.preautorizaciones.store');
    $this->consulta = route('vigilancia.preautorizaciones.index', $this->vivienda);
});

test('creates a preauthorization in UTC from an authorized resident and ignores supplied ownership and QR data', function () {
    $this->actingAs($this->usuario)->get(route('portal.preautorizaciones.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('portal/preautorizar-visita')
            ->has('viviendas', 1)->where('viviendas.0.id', $this->vivienda->id)->has('medios', 3));
    $this->post($this->alta, [...$this->datos, 'residencial_id' => $this->otro->id, 'residente_id' => 999, 'qr_token' => Str::uuid()])
        ->assertRedirect(route('portal.preautorizaciones.create'))->assertSessionHasNoErrors();
    $this->post($this->alta, $this->datos)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('preautorizaciones', 1);
    $this->assertDatabaseHas('preautorizaciones', [
        'residencial_id' => $this->residencial->id, 'residente_id' => $this->residente->id,
        'inicio' => '2026-11-23 05:55:00', 'fin' => '2026-11-23 06:20:00', 'qr_token' => null,
    ]);
    expect(DB::table('auditoria')->where('accion', 'preautorizacion_creada')->count())->toBe(1);
    $this->assertDatabaseCount('visitas', 0);
});

test('rejects unauthorized residents, housing, means and invalid periods', function () {
    $this->actingAs($this->guardia)->post($this->alta, $this->datos)->assertForbidden();
    $this->actingAs($this->usuario);
    foreach ([$this->sinVinculo, $this->ajena] as $vivienda) {
        $this->post($this->alta, [...$this->datos, 'vivienda_id' => $vivienda->id])->assertSessionHasErrors('vivienda_id');
    }
    $inactivo = ($this->medio)($this->residencial, 'Moto');
    $inactivo->update(['activo' => false]);
    foreach ([$inactivo, ($this->medio)($this->otro, 'A pie')] as $medio) {
        $this->post($this->alta, [...$this->datos, 'medio_entrada_id' => $medio->id])->assertSessionHasErrors('medio_entrada_id');
    }
    foreach ([$this->datos['inicio'], '2026-11-22T23:54', '2026-11-23T00:20Z'] as $fin) {
        $this->post($this->alta, [...$this->datos, 'fin' => $fin])->assertSessionHasErrors('fin');
    }
    $this->vinculo->update(['fecha_fin' => now()->toDateString()]);
    $this->post($this->alta, $this->datos)->assertSessionHasErrors('vivienda_id');
    $this->residente->update(['activo' => false]);
    $this->post($this->alta, $this->datos)->assertForbidden();
    $this->assertDatabaseCount('preautorizaciones', 0);
});

test('queries the selected housing and local day with exact boundaries and no resident data', function () {
    $this->actingAs($this->usuario);
    foreach ([
        'Cruza medianoche' => ['2026-11-22T23:55', '2026-11-23T00:20'],
        'Termina ahora' => ['2026-11-23T00:00', '2026-11-23T00:10'],
        'Inicia ahora' => ['2026-11-23T00:10', '2026-11-23T00:15'],
        'Más tarde' => ['2026-11-23T00:11', '2026-11-23T00:30'],
        'Fuera del día' => ['2026-11-24T00:00', '2026-11-24T00:30'],
        'Terminó al empezar el día' => ['2026-11-22T22:00', '2026-11-23T00:00'],
    ] as $nombre => [$inicio, $fin]) {
        $this->post($this->alta, [...$this->datos, 'nombre_visitante' => $nombre, 'inicio' => $inicio, 'fin' => $fin])
            ->assertSessionHasNoErrors();
    }
    $this->getJson($this->consulta)->assertForbidden();
    $this->actingAs($this->guardia)->getJson($this->consulta)->assertOk()->assertJsonCount(4)
        ->assertJsonPath('0.estado', 'Vigente')->assertJsonPath('0.medio', 'A pie')
        ->assertJsonPath('1.estado', 'Vencida')->assertJsonPath('2.estado', 'Vigente')->assertJsonPath('3.estado', 'Futura')
        ->assertJsonMissingPath('0.residente_id')->assertJsonMissingPath('0.qr_token');
    $this->getJson($this->consulta.'?fecha=2026-11-24')->assertOk()->assertJsonCount(1)->assertJsonPath('0.visitante', 'Fuera del día');
    $this->getJson(route('vigilancia.preautorizaciones.index', $this->sinVinculo))->assertExactJson([]);
    $this->getJson(route('vigilancia.preautorizaciones.index', $this->ajena))->assertNotFound();
    $this->getJson($this->consulta.'?fecha=2026-02-30')->assertUnprocessable();
    $this->vinculo->update(['fecha_fin' => now()->toDateString()]);
    $this->getJson($this->consulta)->assertOk()->assertJsonPath('0.estado', 'Sin autorización vigente')
        ->assertJsonPath('0.vinculo_vigente', false);
    $this->assertDatabaseCount('preautorizaciones', 6);
});
