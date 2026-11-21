<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    // Miércoles 18 de noviembre de 2026 a las 10:00 en Honduras
    $this->travelTo(CarbonImmutable::parse('2026-11-18 16:00:00', 'UTC'));

    $this->seed(RolesSeeder::class);

    $this->residencial = Residencial::query()->create(['nombre' => 'Las Palmas']);
    $this->otro = Residencial::query()->create(['nombre' => 'Otro residencial']);

    $this->usuario = fn (string $rol, ?Residencial $residencial = null): User => User::factory()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'rol_id' => (int) Rol::query()->where('codigo', $rol)->value('id'),
    ]);

    $this->presidente = ($this->usuario)('presidente');

    $this->guardia = fn (string $nombre = 'Carlos Mejía', ?Residencial $residencial = null, array $datos = []): Guardia => Guardia::query()->create([
        'residencial_id' => ($residencial ?? $this->residencial)->id,
        'nombre' => $nombre,
        'identidad' => fake()->unique()->numerify('0801-####-#####'),
        'telefono' => '9999-0000',
        'turno' => 'noche',
        'activo' => true,
        ...$datos,
    ]);

    $this->carlos = ($this->guardia)();

    $this->programar = fn (array $cambios = [], ?User $usuario = null) => $this->actingAs($usuario ?? $this->presidente)
        ->post(route('vigilancia.turnos.programar'), [
            'guardia_id' => $this->carlos->id,
            'tipo' => 'noche',
            'hora_inicio' => '18:00',
            'horas' => 12,
            'desde' => '2026-11-23',
            'hasta' => '2026-11-29',
            'repetir' => 'semana',
            'dias' => [1, 2, 3],
            ...$cambios,
        ]);

    // Inicio y fin en la hora de Honduras, guardados en UTC
    $this->horas = fn (Turno $turno): array => [
        $turno->inicio->setTimezone('America/Tegucigalpa')->format('Y-m-d H:i'),
        $turno->fin->setTimezone('America/Tegucigalpa')->format('Y-m-d H:i'),
    ];
});

test('the president schedules a shift on each chosen weekday of the range', function () {
    ($this->programar)()->assertSessionHasNoErrors();

    $turnos = Turno::query()->orderBy('inicio')->get();

    // Lunes, martes y miércoles de noche, de 18:00 a 06:00 del día siguiente
    expect($turnos->map($this->horas)->all())->toBe([
        ['2026-11-23 18:00', '2026-11-24 06:00'],
        ['2026-11-24 18:00', '2026-11-25 06:00'],
        ['2026-11-25 18:00', '2026-11-26 06:00'],
    ])
        ->and($turnos->pluck('tipo')->unique()->all())->toBe(['noche'])
        ->and($turnos->pluck('guardia_id')->unique()->all())->toBe([$this->carlos->id])
        ->and($turnos->first()->inicio->format('Y-m-d H:i'))->toBe('2026-11-24 00:00');

    $auditoria = Auditoria::query()->where('accion', 'turnos_programados')->sole();

    expect($auditoria->modulo)->toBe('vigilancia')
        ->and($auditoria->entidad_id)->toBe($this->carlos->id)
        ->and($auditoria->valor_nuevo['fechas'])->toBe(['2026-11-23', '2026-11-24', '2026-11-25']);
});

test('each shift lasts the chosen hours from the chosen start time', function (string $tipo, string $hora, int $horas, string $fin) {
    ($this->programar)(['tipo' => $tipo, 'hora_inicio' => $hora, 'horas' => $horas, 'hasta' => '2026-11-23', 'dias' => [1]])->assertSessionHasNoErrors();

    $turno = Turno::query()->sole();

    expect($turno->tipo)->toBe($tipo)
        ->and(($this->horas)($turno)[1])->toBe($fin);
})->with([
    'día de 8 horas' => ['dia', '06:00', 8, '2026-11-23 14:00'],
    'día de 12 horas' => ['dia', '07:00', 12, '2026-11-23 19:00'],
    'tarde' => ['tarde', '14:00', 8, '2026-11-23 22:00'],
    'noche' => ['noche', '22:00', 8, '2026-11-24 06:00'],
    '24 horas' => ['24_horas', '07:00', 24, '2026-11-24 07:00'],
]);

test('a 12 hour day shift from 7 to 7 can alternate one day of work and one of rest', function () {
    ($this->programar)(['tipo' => 'dia', 'hora_inicio' => '07:00', 'horas' => 12, 'repetir' => 'alternos', 'dias' => null])
        ->assertSessionHasNoErrors();

    expect(Turno::query()->orderBy('inicio')->get()->map($this->horas)->all())->toBe([
        ['2026-11-23 07:00', '2026-11-23 19:00'],
        ['2026-11-25 07:00', '2026-11-25 19:00'],
        ['2026-11-27 07:00', '2026-11-27 19:00'],
        ['2026-11-29 07:00', '2026-11-29 19:00'],
    ]);
});

test('a 24 hour shift can alternate with a day of rest', function () {
    ($this->programar)(['tipo' => '24_horas', 'hora_inicio' => '07:00', 'horas' => 24, 'repetir' => 'alternos'])
        ->assertSessionHasNoErrors();

    expect(Turno::query()->orderBy('inicio')->get()->map($this->horas)->all())->toBe([
        ['2026-11-23 07:00', '2026-11-24 07:00'],
        ['2026-11-25 07:00', '2026-11-26 07:00'],
        ['2026-11-27 07:00', '2026-11-28 07:00'],
        ['2026-11-29 07:00', '2026-11-30 07:00'],
    ]);
});

test('every other day ignores the days of the week', function () {
    ($this->programar)(['tipo' => 'tarde', 'hora_inicio' => '14:00', 'horas' => 8, 'repetir' => 'alternos', 'desde' => '2026-11-24', 'hasta' => '2026-11-28', 'dias' => [1]])
        ->assertSessionHasNoErrors();

    expect(Turno::query()->orderBy('inicio')->get()->map(fn (Turno $turno) => ($this->horas)($turno)[0])->all())
        ->toBe(['2026-11-24 14:00', '2026-11-26 14:00', '2026-11-28 14:00']);
});

test('back to back 24 hour shifts do not overlap', function () {
    ($this->programar)(['tipo' => '24_horas', 'hora_inicio' => '06:00', 'horas' => 24, 'dias' => [1, 2, 3, 4, 5, 6, 7]])->assertSessionHasNoErrors();

    expect(Turno::query()->count())->toBe(7);
});

test('a guard cannot have two overlapping shifts and nothing of the batch is saved', function () {
    ($this->programar)(['tipo' => '24_horas', 'hora_inicio' => '06:00', 'horas' => 24, 'hasta' => '2026-11-23', 'dias' => [1]])->assertSessionHasNoErrors();

    // El turno de noche del lunes empieza antes de que termine el turno de 24 horas
    ($this->programar)()->assertSessionHasErrors(['desde' => 'Carlos Mejía ya tiene turnos que se cruzan con estos: 23/11/2026 06:00.']);

    expect(Turno::query()->count())->toBe(1);
});

test('different guards can be on duty at the same time', function () {
    $ana = ($this->guardia)('Ana López');

    ($this->programar)()->assertSessionHasNoErrors();
    ($this->programar)(['guardia_id' => $ana->id])->assertSessionHasNoErrors();

    expect(Turno::query()->count())->toBe(6);
});

test('the schedule data is validated', function (array $cambios, string $campo) {
    ($this->programar)($cambios)->assertSessionHasErrors($campo);

    expect(Turno::query()->count())->toBe(0);
})->with([
    'tipo inválido' => [['tipo' => 'rotativo'], 'tipo'],
    'hora inválida' => [['hora_inicio' => '25:00'], 'hora_inicio'],
    'cero horas' => [['horas' => 0], 'horas'],
    'más de 24 horas' => [['horas' => 25], 'horas'],
    'repetición inválida' => [['repetir' => 'mes'], 'repetir'],
    'sin días' => [['dias' => []], 'dias'],
    'día inválido' => [['dias' => [8]], 'dias.0'],
    'hasta antes de desde' => [['hasta' => '2026-11-20'], 'hasta'],
    'más de 62 días' => [['hasta' => '2027-01-24'], 'hasta'],
    'fecha pasada' => [['desde' => '2026-11-17'], 'desde'],
    'ningún día en el rango' => [['hasta' => '2026-11-23', 'dias' => [5]], 'dias'],
]);

test('only active guards of the residencial can be scheduled', function () {
    $inactivo = ($this->guardia)('Pedro Rivera', null, ['activo' => false]);
    $ajeno = ($this->guardia)('Ajeno', $this->otro);

    ($this->programar)(['guardia_id' => $inactivo->id])->assertSessionHasErrors('guardia_id');
    ($this->programar)(['guardia_id' => $ajeno->id])->assertSessionHasErrors('guardia_id');

    expect(Turno::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('the calendar is shown by month with the shifts on the day they start', function () {
    ($this->programar)()->assertSessionHasNoErrors();

    $this->get(route('vigilancia.turnos.index', ['mes' => '2026-11']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/turnos')
            ->where('vista', 'mes')
            ->where('mes', '2026-11')
            ->has('dias', 30)
            ->where('dias.0.fecha', '2026-11-01')
            ->has('dias.22.turnos', 1)
            ->where('dias.22.turnos.0.guardia', 'Carlos Mejía')
            ->where('dias.22.turnos.0.inicio', '2026-11-23 18:00')
            ->where('dias.22.turnos.0.fin', '2026-11-24 06:00')
            ->has('dias.25.turnos', 0));
});

test('the day view includes the night shift that started the day before', function () {
    ($this->programar)()->assertSessionHasNoErrors();

    $this->get(route('vigilancia.turnos.index', ['vista' => 'dia', 'dia' => '2026-11-24']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('vista', 'dia')
            ->where('dia', '2026-11-24')
            ->has('turnosDelDia', 2)
            ->where('turnosDelDia.0.inicio', '2026-11-23 18:00')
            ->where('turnosDelDia.1.inicio', '2026-11-24 18:00'));
});

test('an invalid month or day falls back to the current one', function () {
    $this->actingAs($this->presidente)->get(route('vigilancia.turnos.index', ['mes' => '2026-13']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mes', '2026-11'));

    $this->get(route('vigilancia.turnos.index', ['vista' => 'dia', 'dia' => '2026-02-30']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('dia', '2026-11-18'));
});

test('a shift that has not started can be removed and one that started cannot', function () {
    ($this->programar)(['hasta' => '2026-11-23', 'dias' => [1]])->assertSessionHasNoErrors();
    $turno = Turno::query()->sole();

    $this->delete(route('vigilancia.turnos.quitar', $turno))->assertSessionHasNoErrors();

    expect(Turno::query()->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'turno_quitado')->sole()->valor_anterior['inicio'])->toBe('2026-11-23 18:00');

    ($this->programar)(['hasta' => '2026-11-23', 'dias' => [1]])->assertSessionHasNoErrors();
    $empezado = Turno::query()->sole();

    $this->travelTo(CarbonImmutable::parse('2026-11-24 01:00:00', 'UTC'));

    // Se vuelve a iniciar sesión porque la anterior vence por inactividad
    $this->flushSession()
        ->actingAs($this->presidente)
        ->delete(route('vigilancia.turnos.quitar', $empezado))
        ->assertSessionHasErrors('turno');

    expect(Turno::query()->count())->toBe(1);
});

test('each guard sees the shifts assigned to their account', function () {
    $cuenta = ($this->usuario)('guardia');
    $this->carlos->update(['user_id' => $cuenta->id]);
    $ana = ($this->guardia)('Ana López');

    ($this->programar)()->assertSessionHasNoErrors();
    ($this->programar)(['guardia_id' => $ana->id])->assertSessionHasNoErrors();

    $this->actingAs($cuenta)->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/mis-turnos')
            ->where('guardia', 'Carlos Mejía')
            ->has('turnos', 3)
            ->where('turnos.0.guardia', 'Carlos Mejía')
            ->where('auth.esGuardia', true));
});

test('an account without an active guard does not see shifts', function () {
    $cuenta = ($this->usuario)('guardia');

    $this->actingAs($cuenta)->get(route('vigilancia.turnos.mios'))->assertForbidden();

    $this->carlos->update(['user_id' => $cuenta->id, 'activo' => false]);

    $this->get(route('vigilancia.turnos.mios'))->assertForbidden();
});

test('only roles with the vigilance permission see and schedule shifts', function () {
    $secretario = ($this->usuario)('secretario');

    $this->actingAs($secretario)->get(route('vigilancia.turnos.index'))->assertForbidden();
    ($this->programar)([], $secretario)->assertForbidden();
    ($this->programar)([], ($this->usuario)('guardia'))->assertForbidden();

    expect(Turno::query()->count())->toBe(0);
});

test('shifts of another residencial are not shown or removed', function () {
    $ajeno = ($this->guardia)('Ajeno', $this->otro);
    $turno = Turno::query()->create([
        'residencial_id' => $this->otro->id,
        'guardia_id' => $ajeno->id,
        'tipo' => 'dia',
        'inicio' => '2026-11-23 12:00:00',
        'fin' => '2026-11-24 00:00:00',
    ]);

    $this->actingAs($this->presidente)->get(route('vigilancia.turnos.index', ['vista' => 'dia', 'dia' => '2026-11-23']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('turnosDelDia', 0));

    $this->delete(route('vigilancia.turnos.quitar', $turno))->assertForbidden();

    expect(Turno::query()->withoutGlobalScopes()->count())->toBe(1);
});
