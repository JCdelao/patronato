<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\AutorizadoFrecuente;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
    $this->r = Residencial::query()->create(['nombre' => 'Residencial HU-45']);
    $otra = Residencial::query()->create(['nombre' => 'Otro residencial']);
    $cuenta = fn (string $rol): User => User::factory()->create([
        'residencial_id' => $this->r->id, 'rol_id' => Rol::query()->where('codigo', $rol)->value('id'),
    ]);
    $this->presidente = $cuenta(Rol::PRESIDENTE);
    $this->guardia = $cuenta(Rol::GUARDIA);
    $this->adminTecnico = $cuenta(Rol::ADMIN_TECNICO);
    $this->residente = $cuenta(Rol::RESIDENTE);
    $this->guardiaVinculado = Guardia::query()->create([
        'residencial_id' => $this->r->id, 'user_id' => $this->guardia->id,
        'nombre' => 'Guardia ficticio', 'identidad' => 'G-45', 'turno' => 'dia',
    ]);
    $vivienda = fn (Residencial $r, string $codigo, bool $activa = true): Vivienda => Vivienda::query()->create([
        'residencial_id' => $r->id, 'codigo' => $codigo, 'activa' => $activa, 'tipo_vivienda_id' => tipoVivienda($r->id),
    ]);
    $this->casa = $vivienda($this->r, 'A-01');
    $this->segunda = $vivienda($this->r, 'A-02');
    $this->sinAutorizacion = $vivienda($this->r, 'A-03');
    $this->inactiva = $vivienda($this->r, 'A-04', false);
    $this->ajena = $vivienda($otra, 'A-01');
    $this->datos = [
        'nombre' => 'Servicio de jardín', 'tipo' => 'Jardinería', 'dias' => [5, 1, 5],
        'hora_inicio' => '08:00', 'hora_fin' => '16:00', 'viviendas' => [$this->segunda->id, $this->casa->id, $this->segunda->id],
    ];
    $this->alta = route('vigilancia.autorizados-frecuentes.store');
    $this->consulta = fn (Vivienda $v) => route('vigilancia.autorizados-frecuentes.index', $v);
});

test('president creates a normalized authorization with multiple homes and one audit', function () {
    $this->actingAs($this->presidente)->get(route('vigilancia.autorizados-frecuentes.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/autorizados-frecuentes')->has('viviendas', 3));
    $this->post($this->alta, [...$this->datos, 'created_by' => $this->guardia->id, 'residencial_id' => $this->ajena->residencial_id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post($this->alta, [...$this->datos, 'nombre' => 'SERVICIO  DE JARDÍN', 'dias' => [1, 5], 'viviendas' => [$this->casa->id, $this->segunda->id]])
        ->assertSessionHasNoErrors();

    $creado = AutorizadoFrecuente::query()->sole();
    expect($creado->dias_semana)->toBe([1, 5]);
    $this->assertDatabaseHas('autorizados_frecuentes', ['id' => $creado->id, 'created_by' => $this->presidente->id, 'residencial_id' => $this->r->id]);
    $this->assertDatabaseCount('autorizado_frecuente_viviendas', 2);
    foreach ([$this->casa, $this->segunda] as $casa) {
        $this->assertDatabaseHas('autorizado_frecuente_viviendas', ['autorizado_id' => $creado->id, 'vivienda_id' => $casa->id, 'residencial_id' => $this->r->id]);
    }
    expect(DB::table('auditoria')->where('accion', 'autorizado_frecuente_creado')->count())->toBe(1);

    $creado->update(['activo' => false]);
    $this->post($this->alta, $this->datos)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('autorizados_frecuentes', 2);
    $this->assertDatabaseHas('autorizados_frecuentes', ['id' => $creado->id, 'activo' => false]);
});

test('rejects invalid destinations and periods, and honors the existing permission model', function () {
    foreach ([$this->guardia, $this->residente] as $cuenta) {
        $this->actingAs($cuenta)->post($this->alta, $this->datos)->assertForbidden();
    }
    $this->actingAs($this->presidente);
    foreach ([$this->ajena, $this->inactiva] as $casa) {
        $this->post($this->alta, [...$this->datos, 'viviendas' => [$this->casa->id, $casa->id]])->assertSessionHasErrors('viviendas');
    }
    $this->post($this->alta, [...$this->datos, 'viviendas' => []])->assertSessionHasErrors('viviendas');
    $this->post($this->alta, [...$this->datos, 'dias' => []])->assertSessionHasErrors('dias');
    $this->post($this->alta, [...$this->datos, 'dias' => [8]])->assertSessionHasErrors('dias.0');
    $this->post($this->alta, [...$this->datos, 'hora_inicio' => '24:01'])->assertSessionHasErrors('hora_inicio');
    $this->post($this->alta, [...$this->datos, 'hora_fin' => '08:00'])->assertSessionHasErrors('hora_fin');
    DB::table('users')->where('id', $this->presidente->id)->update(['activo' => false]);
    $this->actingAs($this->presidente->refresh())->post($this->alta, $this->datos)
        ->assertRedirect(route('login'))->assertSessionHas('status', User::MENSAJE_CUENTA_INACTIVA);
    $this->assertDatabaseCount('autorizados_frecuentes', 0);
    $this->assertDatabaseCount('autorizado_frecuente_viviendas', 0);
    $this->actingAs($this->adminTecnico)->post($this->alta, $this->datos)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('autorizados_frecuentes', 1);
});

test('guard reads only the chosen housing and evaluates daily and overnight bounds in Honduras', function () {
    $this->actingAs($this->presidente)->post($this->alta, [...$this->datos, 'dias' => [1]])->assertSessionHasNoErrors();
    $this->post($this->alta, [...$this->datos, 'dias' => [7], 'hora_inicio' => '22:00', 'hora_fin' => '02:00', 'nombre' => 'Trabajo nocturno'])->assertSessionHasNoErrors();
    $this->getJson(($this->consulta)($this->casa))->assertForbidden();
    $this->actingAs($this->guardia);
    $this->getJson(($this->consulta)($this->ajena))->assertNotFound();
    $this->getJson(($this->consulta)($this->sinAutorizacion))->assertOk()->assertExactJson([]);
    foreach ([
        ['2026-11-22 21:59:59', false, false],
        ['2026-11-22 22:00:00', false, true],
        ['2026-11-23 01:59:59', false, true],
        ['2026-11-23 02:00:00', false, false],
        ['2026-11-23 07:59:59', false, false],
        ['2026-11-23 08:00:00', true, false],
        ['2026-11-23 15:59:59', true, false],
        ['2026-11-23 16:00:00', false, false],
        ['2026-11-24 09:00:00', false, false],
    ] as [$hora, $diurno, $nocturno]) {
        $this->travelTo(CarbonImmutable::parse($hora, 'America/Tegucigalpa')->utc());
        $this->withSession(['ultima_actividad' => now()->getTimestamp()])
            ->getJson(($this->consulta)($this->segunda).'?fecha=2099-01-01&hora=08:00')->assertOk()
            ->assertJsonPath('0.estado', $diurno ? 'Autorizado en este horario' : 'No autorizado en este horario')
            ->assertJsonPath('1.estado', $nocturno ? 'Autorizado en este horario' : 'No autorizado en este horario')
            ->assertJsonMissingPath('0.identidad')->assertJsonMissingPath('0.created_by');
    }

    AutorizadoFrecuente::query()->where('nombre', 'Servicio de jardín')->update(['activo' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 08:30', 'America/Tegucigalpa')->utc());
    $this->withSession(['ultima_actividad' => now()->getTimestamp()])
        ->getJson(($this->consulta)($this->casa))->assertJsonPath('0.estado', 'Autorización inactiva');
    $this->guardiaVinculado->update(['activo' => false]);
    $this->getJson(($this->consulta)($this->casa))->assertForbidden();
    $this->assertDatabaseCount('visitas', 0);
});

test('permission migration assigns existing presidents idempotently without granting other roles', function () {
    $permisos = DB::table('rol_permisos')->where('modulo', 'autorizados_frecuentes');
    $permisos->delete();
    $migracion = require database_path('migrations/2026_11_23_180001_asignar_autorizados_frecuentes_al_presidente.php');
    $migracion->up();
    $migracion->up();
    expect($permisos->where('residencial_id', $this->r->id)->pluck('rol_id')->all())->toBe([$this->presidente->rol_id]);
});
