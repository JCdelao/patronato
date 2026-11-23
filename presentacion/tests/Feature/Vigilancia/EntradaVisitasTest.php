<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\EntregaTurno;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Visita;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 16:00:00', 'UTC'));
    $this->withoutVite();
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);
    $this->usuario = fn (string $rol): User => User::factory()->create([
        'residencial_id' => $this->residencial->id,
        'rol_id' => Rol::query()->where('codigo', $rol)->value('id'),
    ]);
    $this->cuenta = ($this->usuario)(Rol::GUARDIA);
    $this->guardia = Guardia::query()->create([
        'residencial_id' => $this->residencial->id,
        'user_id' => $this->cuenta->id,
        'nombre' => 'Guardia de prueba',
        'identidad' => 'GUARDIA-01',
        'turno' => 'dia',
        'activo' => true,
    ]);
    $this->turno = Turno::query()->create([
        'residencial_id' => $this->residencial->id,
        'guardia_id' => $this->guardia->id,
        'tipo' => 'dia',
        'inicio' => now()->subHour(),
        'fin' => now()->addHour(),
        'inicio_real' => now()->subHour(),
    ]);
    $this->vivienda = fn (Residencial $residencial): Vivienda => Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'codigo' => 'A-01',
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);
    $this->casa = ($this->vivienda)($this->residencial);
    $this->medio = fn (string $nombre, ?Residencial $residencial = null): MedioEntrada => MedioEntrada::query()
        ->withoutGlobalScope('residencial')
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('nombre', $nombre)->firstOrFail();
    $this->datos = fn (array $cambios = []): array => [
        'nombre_visitante' => 'Ana Visitante',
        'identidad' => 'VISITA-123',
        'vivienda_id' => $this->casa->id,
        'motivo' => 'Visita familiar',
        'medio_entrada_id' => ($this->medio)('A pie')->id,
        ...$cambios,
    ];
});

test('records a walk-in visit with server time and the authenticated guard and shift', function () {
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), ($this->datos)([
        'entrada_en' => '2020-01-01 00:00:00',
        'guardia_entrada_id' => 999,
        'turno_entrada_id' => 999,
        'residencial_id' => $this->otro->id,
    ]))->assertRedirect(route('vigilancia.visitas.create'))->assertSessionHasNoErrors();

    $visita = Visita::query()->sole();
    expect(Str::isUuid($visita->identificador))->toBeTrue()
        ->and($visita->entrada_en->format('Y-m-d H:i:s'))->toBe('2026-11-22 16:00:00');
    $this->assertDatabaseHas('visitas', [
        'residencial_id' => $this->residencial->id,
        'guardia_entrada_id' => $this->guardia->id,
        'turno_entrada_id' => $this->turno->id,
        'vivienda_id' => $this->casa->id,
        'nombre_visitante' => 'Ana Visitante',
        'identidad' => 'VISITA-123',
        'motivo' => 'Visita familiar',
        'placa' => null,
    ]);
    $this->assertDatabaseHas('auditoria', ['accion' => 'visita_entrada_registrada']);

    $this->get(route('vigilancia.visitas.create'))->assertInertia(
        fn (AssertableInertia $page) => $page->component('vigilancia/entrada-visitas')->where('identificador', $visita->identificador),
    );
});

test('generates a unique identifier for each entrance', function () {
    $this->actingAs($this->cuenta);
    $this->post(route('vigilancia.visitas.store'), ($this->datos)())->assertSessionHasNoErrors();
    $this->post(route('vigilancia.visitas.store'), ($this->datos)(['identidad' => 'VISITA-456']))->assertSessionHasNoErrors();

    expect(Visita::query()->distinct()->count('identificador'))->toBe(2);
});

test('requires a plate for motorized entry', function (string $nombre) {
    $datos = ($this->datos)(['medio_entrada_id' => ($this->medio)($nombre)->id]);
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), $datos)->assertSessionHasErrors('placa');
    $this->assertDatabaseCount('visitas', 0);

    $this->post(route('vigilancia.visitas.store'), [...$datos, 'placa' => 'HXX 1234'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('visitas', ['placa' => 'HXX 1234']);
})->with(['Moto', 'Carro']);

test('only an authorized account linked to an active guard can register', function () {
    $this->post(route('vigilancia.visitas.store'), ($this->datos)())->assertRedirect(route('login'));
    $this->actingAs(($this->usuario)(Rol::RESIDENTE))->post(route('vigilancia.visitas.store'), ($this->datos)())->assertForbidden();
    $this->actingAs(($this->usuario)(Rol::GUARDIA))->post(route('vigilancia.visitas.store'), ($this->datos)())->assertForbidden();
    $this->guardia->update(['activo' => false]);
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), ($this->datos)())->assertForbidden();
    $this->assertDatabaseCount('visitas', 0);
});

test('lists and accepts only housing units and entry media of the guard residential', function () {
    $casaAjena = ($this->vivienda)($this->otro);
    $otroMedio = ($this->medio)('A pie', $this->otro);
    $this->actingAs($this->cuenta)->get(route('vigilancia.visitas.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('viviendas', 1)
            ->where('viviendas.0.id', $this->casa->id)
            ->has('medios', 3));
    $this->post(route('vigilancia.visitas.store'), ($this->datos)(['vivienda_id' => $casaAjena->id]))->assertSessionHasErrors('vivienda_id');
    $this->post(route('vigilancia.visitas.store'), ($this->datos)(['medio_entrada_id' => $otroMedio->id]))->assertSessionHasErrors('medio_entrada_id');
    $this->assertDatabaseCount('visitas', 0);
});

test('rejects a scheduled but unstarted shift and a handed-over shift', function () {
    $this->turno->update(['inicio_real' => null]);
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), ($this->datos)())->assertSessionHasErrors('turno');

    $this->turno->update(['inicio_real' => now()->subHour()]);
    EntregaTurno::query()->create([
        'residencial_id' => $this->residencial->id,
        'turno_id' => $this->turno->id,
        'guardia_entrega_id' => $this->guardia->id,
        'entregado_en' => now(),
    ]);
    $this->post(route('vigilancia.visitas.store'), ($this->datos)())->assertSessionHasErrors('turno');
    $this->assertDatabaseCount('visitas', 0);
});

test('authorized catalog changes determine which entry media and plates are accepted', function () {
    $datos = ['nombre' => 'Microbús', 'requiere_placa' => true, 'activo' => true];
    $this->actingAs($this->cuenta)->post(route('vigilancia.medios-entrada.store'), $datos)->assertForbidden();

    $presidente = ($this->usuario)(Rol::PRESIDENTE);
    $this->actingAs($presidente)->post(route('vigilancia.medios-entrada.store'), $datos)->assertSessionHasNoErrors();
    $medio = ($this->medio)('Microbús');
    $visita = ($this->datos)(['medio_entrada_id' => $medio->id]);
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), $visita)->assertSessionHasErrors('placa');
    $this->post(route('vigilancia.visitas.store'), [...$visita, 'placa' => 'BUS-123'])->assertSessionHasNoErrors();

    $ajeno = ($this->medio)('Moto', $this->otro);
    $this->actingAs($presidente)->put(route('vigilancia.medios-entrada.update', $ajeno), $datos)->assertForbidden();
    $this->put(route('vigilancia.medios-entrada.update', $medio), [...$datos, 'activo' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), $visita)->assertSessionHasErrors('medio_entrada_id');
    $this->assertDatabaseCount('visitas', 1);
});

test('rejects malformed visitor details', function () {
    $this->actingAs($this->cuenta)->post(route('vigilancia.visitas.store'), ($this->datos)([
        'nombre_visitante' => ' ',
        'identidad' => str_repeat('A', 31),
        'motivo' => '',
        'vivienda_id' => 'ajena',
        'medio_entrada_id' => 'inexistente',
        'placa' => str_repeat('P', 21),
    ]))->assertSessionHasErrors(['nombre_visitante', 'identidad', 'motivo', 'vivienda_id', 'medio_entrada_id', 'placa']);
    $this->assertDatabaseCount('visitas', 0);
});
