<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\GeneracionCargosService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    Carbon::setTestNow('2026-11-15 12:00:00');

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);

    $this->crear = fn (string $codigo, ?Residencial $residencial = null) => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => Rol::query()->where('codigo', $codigo)->value('id'),
    ]);

    $this->tesorero = ($this->crear)('tesorero');
    $this->presidente = ($this->crear)(Rol::PRESIDENTE);

    $this->actingAs($this->tesorero);

    $concepto = fn (array $datos) => $this->post(route('finanzas.conceptos.store'), [
        'tipo' => 'recurrente', 'vigente_desde' => '2026-11', ...$datos,
    ]);

    $concepto(['nombre' => 'Agua', 'monto' => '200', 'alcance' => 'todas'])->assertSessionHasNoErrors();
    $concepto(['nombre' => 'Parqueo', 'monto' => '100', 'alcance' => 'algunas'])->assertSessionHasNoErrors();

    $this->agua = ConceptoCobro::query()->where('nombre', 'Agua')->sole();
    $this->parqueo = ConceptoCobro::query()->where('nombre', 'Parqueo')->sole();

    // Registra una vivienda desde el formulario del padrón
    $this->vivienda = function (string $codigo, array $conceptos = []) {
        $this->actingAs($this->presidente)->post(route('padron.viviendas.store'), [
            'codigo' => $codigo, 'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
            'calle_bloque' => 'Bloque A', 'numero' => '1', 'estado_ocupacion' => 'ocupada', 'conceptos' => $conceptos,
        ])->assertSessionHasNoErrors();

        return Vivienda::query()->where('codigo', $codigo)->sole();
    };

    $this->cargos = fn () => Cargo::query()->with('concepto')->get()
        ->map(fn (Cargo $cargo) => Vivienda::query()->find($cargo->vivienda_id)->codigo.' · '.$cargo->concepto->nombre)
        ->sort()->values()->all();
});

afterEach(fn () => Carbon::setTestNow());

test('a concept is charged to all houses or only to some', function () {
    $this->post(route('finanzas.conceptos.store'), ['nombre' => 'Piscina', 'tipo' => 'recurrente', 'alcance' => 'otra', 'monto' => '50', 'vigente_desde' => '2026-11'])
        ->assertSessionHasErrors('alcance');

    // Sin indicarlo se cobra a todas
    $this->post(route('finanzas.conceptos.store'), ['nombre' => 'Basura', 'tipo' => 'recurrente', 'monto' => '50', 'vigente_desde' => '2026-11'])
        ->assertSessionHasNoErrors();

    expect(ConceptoCobro::query()->where('nombre', 'Basura')->sole()->alcance)->toBe('todas')
        ->and(Auditoria::query()->where('accion', 'concepto_creado')->where('entidad_id', $this->parqueo->id)->sole()->valor_nuevo['alcance'])->toBe('algunas');

    ($this->vivienda)('A-01', [$this->parqueo->id]);

    $this->actingAs($this->tesorero)->get(route('finanzas.parametros.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conceptos', fn ($conceptos) => collect($conceptos)->firstWhere('nombre', 'Parqueo')['alcance'] === 'algunas'
                && collect($conceptos)->firstWhere('nombre', 'Parqueo')['viviendas_elegidas'] === 1));

    // Cambiar el alcance queda en la bitácora
    $this->put(route('finanzas.conceptos.update', $this->agua), ['nombre' => 'Agua', 'tipo' => 'recurrente', 'alcance' => 'algunas', 'monto' => '200', 'vigente_desde' => '2026-12'])
        ->assertSessionHasNoErrors();

    $registro = Auditoria::query()->where('accion', 'concepto_editado')->sole();

    expect($registro->valor_anterior)->toBe(['alcance' => 'todas'])
        ->and($registro->valor_nuevo)->toBe(['alcance' => 'algunas']);
});

test('the house form chooses the concepts it pays apart', function () {
    $casa = ($this->vivienda)('A-01', [$this->parqueo->id]);

    expect($casa->conceptos()->pluck('nombre')->all())->toBe(['Parqueo'])
        ->and(Auditoria::query()->where('accion', 'vivienda_registrada')->sole()->valor_nuevo['conceptos'])->toBe('Parqueo');

    $this->get(route('padron.viviendas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viviendas.data.0.conceptos', [['id' => $this->parqueo->id, 'nombre' => 'Parqueo']])
            ->where('conceptosOpcionales', [['id' => $this->parqueo->id, 'nombre' => 'Parqueo', 'monto' => '100.00']]));

    // Al quitarlo queda en la bitácora
    $this->put(route('padron.viviendas.update', $casa), [
        'codigo' => 'A-01', 'tipo_vivienda_id' => $casa->tipo_vivienda_id, 'calle_bloque' => 'Bloque A', 'numero' => '1', 'estado_ocupacion' => 'ocupada',
    ])->assertSessionHasNoErrors();

    $registro = Auditoria::query()->where('accion', 'vivienda_editada')->sole();

    expect($casa->conceptos()->count())->toBe(0)
        ->and($registro->valor_anterior)->toBe(['conceptos' => 'Parqueo'])
        ->and($registro->valor_nuevo)->toBe(['conceptos' => null]);
});

test('only concepts for some houses of the residential can be chosen', function () {
    $otro = Residencial::query()->create(['nombre' => 'Otro']);
    $ajeno = ConceptoCobro::query()->create(['residencial_id' => $otro->id, 'nombre' => 'Parqueo', 'tipo' => 'recurrente', 'alcance' => 'algunas']);

    $datos = fn (int $conceptoId) => [
        'codigo' => 'A-01', 'tipo_vivienda_id' => tipoVivienda($this->residencial->id),
        'calle_bloque' => 'Bloque A', 'numero' => '1', 'estado_ocupacion' => 'ocupada', 'conceptos' => [$conceptoId],
    ];

    $this->actingAs($this->presidente);

    $this->post(route('padron.viviendas.store'), $datos($this->agua->id))->assertSessionHasErrors('conceptos.0');
    $this->post(route('padron.viviendas.store'), $datos($ajeno->id))->assertSessionHasErrors('conceptos.0');

    $this->actingAs($this->tesorero)->post(route('finanzas.conceptos.desactivar', $this->parqueo));
    $this->actingAs($this->presidente)->post(route('padron.viviendas.store'), $datos($this->parqueo->id))->assertSessionHasErrors('conceptos.0');

    expect(Vivienda::query()->count())->toBe(0);
});

test('the charges of a concept for some houses only reach the chosen ones', function () {
    ($this->vivienda)('A-01', [$this->parqueo->id]);
    ($this->vivienda)('A-02');

    app(GeneracionCargosService::class)->generarAhora($this->tesorero);

    expect(($this->cargos)())->toBe(['A-01 · Agua', 'A-01 · Parqueo', 'A-02 · Agua']);
});

test('a deactivated concept keeps its houses when a house is edited', function () {
    $casa = ($this->vivienda)('A-01', [$this->parqueo->id]);

    $this->actingAs($this->tesorero)->post(route('finanzas.conceptos.desactivar', $this->parqueo));

    // El formulario ya no muestra Parqueo, pero editar la vivienda no lo quita
    $this->actingAs($this->presidente)->put(route('padron.viviendas.update', $casa), [
        'codigo' => 'A-01', 'tipo_vivienda_id' => $casa->tipo_vivienda_id, 'calle_bloque' => 'Bloque B', 'numero' => '1', 'estado_ocupacion' => 'ocupada',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->tesorero)->post(route('finanzas.conceptos.reactivar', $this->parqueo));

    expect($casa->conceptos()->pluck('nombre')->all())->toBe(['Parqueo']);
});
