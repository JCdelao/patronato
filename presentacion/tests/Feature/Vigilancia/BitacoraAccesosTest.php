<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Services\BitacoraAccesosService;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoIncidencia;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;

beforeEach(function () {
    // Domingo 22 de noviembre de 2026 a las 10:00 en Honduras
    $this->travelTo(CarbonImmutable::parse('2026-11-22 16:00:00', 'UTC'));
    $this->withoutVite();
    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => Rol::query()->where('codigo', $rol)->value('id'),
    ]);
    $this->presidente = ($this->usuario)(Rol::PRESIDENTE);

    $this->guardia = fn (string $nombre, ?Residencial $residencial = null): Guardia => Guardia::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'nombre' => $nombre,
        'identidad' => fake()->unique()->numerify('0801-####-#####'),
        'turno' => 'dia',
        'activo' => true,
    ]);
    $this->carlos = ($this->guardia)('Carlos Mejía');
    $this->ana = ($this->guardia)('Ana López');

    // Horas en UTC: la noche de Ana va de 22:00 a 06:00 y el día de Carlos de 06:00 a 14:00 en Honduras
    $this->turno = fn (Guardia $guardia, string $tipo, string $inicio, string $fin) => Turno::query()->create([
        'residencial_id' => $guardia->residencial_id,
        'guardia_id' => $guardia->id,
        'tipo' => $tipo,
        'inicio' => $inicio,
        'fin' => $fin,
    ]);
    ($this->turno)($this->ana, 'noche', '2026-11-22 04:00:00', '2026-11-22 12:00:00');
    ($this->turno)($this->carlos, 'dia', '2026-11-22 12:00:00', '2026-11-22 20:00:00');

    $this->vivienda = fn (string $codigo, ?Residencial $residencial = null): Vivienda => Vivienda::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'codigo' => $codigo,
        'tipo_vivienda_id' => tipoVivienda(($residencial ?? $this->residencial)->id),
        'estado_ocupacion' => 'ocupada',
        'activa' => true,
    ]);
    $this->a01 = ($this->vivienda)('A-01');
    $this->b02 = ($this->vivienda)('B-02');

    $this->medio = fn (string $nombre, ?Residencial $residencial = null): MedioEntrada => MedioEntrada::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('nombre', $nombre)
        ->firstOrFail();

    $this->visita = fn (array $datos): int => DB::table('visitas')->insertGetId([
        'residencial_id' => $this->residencial->id,
        'identificador' => (string) Str::uuid(),
        'nombre_visitante' => 'Visitante',
        'medio_entrada_id' => ($this->medio)('A pie')->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$datos,
    ]);

    $this->incidencia = fn (Guardia $guardia, string $tipo, string $ocurrida, string $descripcion, bool $urgente = false): int => DB::table('incidencias')->insertGetId([
        'residencial_id' => $guardia->residencial_id,
        'tipo_incidencia_id' => TipoIncidencia::query()->withoutGlobalScopes()
            ->where('residencial_id', $guardia->residencial_id)->where('nombre', $tipo)->value('id'),
        'guardia_id' => $guardia->id,
        'descripcion' => $descripcion,
        'ocurrida_en' => $ocurrida,
        'urgente' => $urgente,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Ana deja entrar una visita de noche y Carlos registra su salida de día
    ($this->visita)([
        'nombre_visitante' => 'Pedro Pérez',
        'vivienda_id' => $this->a01->id,
        'motivo' => 'Visita familiar',
        'entrada_en' => '2026-11-22 05:00:00',
        'guardia_entrada_id' => $this->ana->id,
        'salida_en' => '2026-11-22 13:00:00',
        'guardia_salida_id' => $this->carlos->id,
    ]);
    // Carlos deja entrar un carro que sigue dentro
    ($this->visita)([
        'nombre_visitante' => 'Marta Díaz',
        'vivienda_id' => $this->b02->id,
        'medio_entrada_id' => ($this->medio)('Carro')->id,
        'placa' => 'HAA1234',
        'motivo' => 'Entrega',
        'entrada_en' => '2026-11-22 14:00:00',
        'guardia_entrada_id' => $this->carlos->id,
    ]);
    ($this->incidencia)($this->carlos, 'Ruido', '2026-11-22 15:00:00', 'Fiesta con música alta en la casa B-02', urgente: true);
    ($this->incidencia)($this->ana, 'Robo', '2026-11-22 06:00:00', 'Robo de una bicicleta frente al parque');
    // Fuera de la última semana y sin turno programado
    ($this->visita)([
        'nombre_visitante' => 'Visita antigua',
        'vivienda_id' => $this->a01->id,
        'entrada_en' => '2026-11-01 15:00:00',
        'guardia_entrada_id' => $this->carlos->id,
    ]);

    $this->ver = fn (array $filtros = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->get(route('vigilancia.bitacora.index', $filtros));

    // Tipo y visitante o tipo de incidencia de cada fila, en orden
    $this->filas = fn ($respuesta): array => collect($respuesta->viewData('page')['props']['eventos']['data'])
        ->map(fn (array $evento): string => $evento['tipo'].' '.($evento['visitante'] ?? $evento['categoria']))
        ->all();
});

test('the president sees the entries, exits and incidents of the last week, newest first', function () {
    $respuesta = ($this->ver)()->assertOk();

    expect(($this->filas)($respuesta))->toBe([
        'incidencia Ruido',
        'entrada Marta Díaz',
        'salida Pedro Pérez',
        'incidencia Robo',
        'entrada Pedro Pérez',
    ]);

    $respuesta->assertInertia(fn (AssertableInertia $page) => $page
        ->component('vigilancia/bitacora-accesos')
        ->where('filtros.desde', '2026-11-16')
        ->where('filtros.hasta', '2026-11-22')
        ->where('totales', ['entrada' => 2, 'salida' => 1, 'incidencia' => 2])
        // Hora de Honduras, guardia y turno de cada evento
        ->where('eventos.data.0.fecha', '2026-11-22 09:00')
        ->where('eventos.data.0.guardia', 'Carlos Mejía')
        ->where('eventos.data.0.turno', 'dia')
        ->where('eventos.data.0.urgente', true)
        ->where('eventos.data.1.vivienda', 'B-02')
        ->where('eventos.data.1.medio', 'Carro')
        ->where('eventos.data.1.placa', 'HAA1234')
        ->where('eventos.data.4.fecha', '2026-11-21 23:00')
        ->where('eventos.data.4.guardia', 'Ana López')
        ->where('eventos.data.4.turno', 'noche'));
});

test('the shift of each event is the one its guard had scheduled at that time', function () {
    expect(($this->filas)(($this->ver)(['turno' => 'noche'])))->toBe(['incidencia Robo', 'entrada Pedro Pérez'])
        ->and(($this->filas)(($this->ver)(['turno' => 'dia'])))->toBe(['incidencia Ruido', 'entrada Marta Díaz', 'salida Pedro Pérez'])
        ->and(($this->filas)(($this->ver)(['turno' => '24_horas'])))->toBe([]);
});

test('all the filters can be combined', function () {
    expect(($this->filas)(($this->ver)(['guardia' => $this->carlos->id])))->toBe(['incidencia Ruido', 'entrada Marta Díaz', 'salida Pedro Pérez'])
        ->and(($this->filas)(($this->ver)(['guardia' => $this->carlos->id, 'tipo' => 'entrada'])))->toBe(['entrada Marta Díaz'])
        ->and(($this->filas)(($this->ver)(['tipo' => 'salida', 'turno' => 'dia', 'vivienda' => $this->a01->id])))->toBe(['salida Pedro Pérez'])
        ->and(($this->filas)(($this->ver)(['tipo' => 'incidencia', 'guardia' => $this->ana->id, 'turno' => 'noche'])))->toBe(['incidencia Robo'])
        ->and(($this->filas)(($this->ver)(['guardia' => $this->ana->id, 'turno' => 'dia'])))->toBe([]);
});

test('the house filter also finds incidents that mention it in what happened', function () {
    expect(($this->filas)(($this->ver)(['vivienda' => $this->b02->id])))->toBe(['incidencia Ruido', 'entrada Marta Díaz'])
        ->and(($this->filas)(($this->ver)(['vivienda' => $this->a01->id])))->toBe(['salida Pedro Pérez', 'entrada Pedro Pérez']);
});

test('the entry method filter only shows visits', function () {
    expect(($this->filas)(($this->ver)(['medio' => ($this->medio)('Carro')->id])))->toBe(['entrada Marta Díaz'])
        ->and(($this->filas)(($this->ver)(['medio' => ($this->medio)('A pie')->id])))->toBe(['salida Pedro Pérez', 'entrada Pedro Pérez']);
});

test('the dates filter by the day in Honduras and an event without a scheduled shift is shown without one', function () {
    $respuesta = ($this->ver)(['desde' => '2026-11-01', 'hasta' => '2026-11-01']);

    expect(($this->filas)($respuesta))->toBe(['entrada Visita antigua']);
    $respuesta->assertInertia(fn (AssertableInertia $page) => $page->where('eventos.data.0.turno', null));

    // La entrada de las 23:00 del 21 en Honduras ya es el 22 en UTC
    expect(($this->filas)(($this->ver)(['desde' => '2026-11-21', 'hasta' => '2026-11-21'])))->toBe(['entrada Pedro Pérez']);
});

test('invalid filters are rejected', function (array $filtros, string $campo) {
    ($this->ver)($filtros)->assertSessionHasErrors($campo);
})->with([
    'hasta antes de desde' => [['desde' => '2026-11-20', 'hasta' => '2026-11-19'], 'hasta'],
    'fecha inválida' => [['desde' => '20-11-2026'], 'desde'],
    'tipo desconocido' => [['tipo' => 'visita'], 'tipo'],
    'turno desconocido' => [['turno' => 'rotativo'], 'turno'],
]);

test('the result is exported to Excel with the same filters', function () {
    $respuesta = $this->actingAs($this->presidente)
        ->get(route('vigilancia.bitacora.exportar', ['formato' => 'excel', 'vivienda' => $this->b02->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="bitacora-de-accesos-2026-11-16_2026-11-22.xlsx"');

    $archivo = tempnam(sys_get_temp_dir(), 'bitacora');
    file_put_contents($archivo, $respuesta->getContent());
    $zip = new ZipArchive;
    $zip->open($archivo);
    $texto = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $texto .= $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($archivo);

    expect($texto)->toContain('Fiesta con música alta en la casa B-02')
        ->toContain('Marta Díaz')
        ->toContain('HAA1234')
        ->not->toContain('Pedro Pérez')
        ->not->toContain('bicicleta');
});

test('the result is exported to PDF', function () {
    $respuesta = $this->actingAs($this->presidente)
        ->get(route('vigilancia.bitacora.exportar', ['formato' => 'pdf', 'turno' => 'noche']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');
});

test('an export with too many records asks to narrow the filters', function () {
    $tipo = TipoIncidencia::query()->withoutGlobalScopes()->where('residencial_id', $this->residencial->id)->value('id');

    foreach (array_chunk(range(1, BitacoraAccesosService::MAXIMO_EXPORTACION), 500) as $bloque) {
        DB::table('incidencias')->insert(array_map(fn (int $numero): array => [
            'residencial_id' => $this->residencial->id,
            'tipo_incidencia_id' => $tipo,
            'guardia_id' => $this->carlos->id,
            'descripcion' => "Ronda {$numero}",
            'ocurrida_en' => '2026-11-20 12:00:00',
            'urgente' => false,
        ], $bloque));
    }

    $this->actingAs($this->presidente)
        ->from(route('vigilancia.bitacora.index'))
        ->get(route('vigilancia.bitacora.exportar', ['formato' => 'excel']))
        ->assertRedirect(route('vigilancia.bitacora.index'))
        ->assertSessionHasErrors('exportar');
});

test('only roles with the permission see and export the log', function () {
    foreach ([Rol::GUARDIA, Rol::SECRETARIO, 'tesorero'] as $rol) {
        $usuario = ($this->usuario)($rol);

        ($this->ver)([], $usuario)->assertForbidden();
        $this->get(route('vigilancia.bitacora.exportar', ['formato' => 'pdf']))->assertForbidden();
    }

    $this->actingAs($this->presidente)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permisos', fn ($permisos) => collect($permisos)->contains('bitacora.ver')));
});

test('the log of another residential is never shown or filtered', function () {
    $ajeno = ($this->guardia)('Luis Rivera', $this->otro);
    $casa = ($this->vivienda)('A-01', $this->otro);

    DB::table('visitas')->insert([
        'residencial_id' => $this->otro->id,
        'identificador' => (string) Str::uuid(),
        'nombre_visitante' => 'Visita ajena',
        'vivienda_id' => $casa->id,
        'medio_entrada_id' => ($this->medio)('A pie', $this->otro)->id,
        'entrada_en' => '2026-11-22 14:30:00',
        'guardia_entrada_id' => $ajeno->id,
    ]);

    expect(($this->filas)(($this->ver)()))->not->toContain('entrada Visita ajena');

    ($this->ver)(['guardia' => $ajeno->id])->assertSessionHasErrors('guardia');
    ($this->ver)(['vivienda' => $casa->id])->assertSessionHasErrors('vivienda');
    ($this->ver)(['medio' => ($this->medio)('A pie', $this->otro)->id])->assertSessionHasErrors('medio');
});

test('the filter options include inactive guards', function () {
    $this->carlos->update(['activo' => false]);

    ($this->ver)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('opciones.guardias', [
            ['id' => $this->ana->id, 'nombre' => 'Ana López'],
            ['id' => $this->carlos->id, 'nombre' => 'Carlos Mejía'],
        ])
        ->where('opciones.viviendas.0.nombre', 'A-01')
        ->has('opciones.medios', 3));
});

test('new residentials include the unauthorized entry incident type', function () {
    expect(TipoIncidencia::query()->withoutGlobalScopes()
        ->where('residencial_id', $this->otro->id)
        ->where('nombre', 'Ingreso no autorizado')
        ->exists())->toBeTrue();
});
