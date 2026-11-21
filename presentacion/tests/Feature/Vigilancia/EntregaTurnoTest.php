<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\EntregaTurno;
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

    // Guardia activo con su cuenta
    $this->guardia = function (string $nombre, ?Residencial $residencial = null): Guardia {
        $residencial ??= $this->residencial;

        return Guardia::query()->create([
            'residencial_id' => $residencial->id,
            'user_id' => ($this->usuario)('guardia', $residencial)->id,
            'nombre' => $nombre,
            'identidad' => fake()->unique()->numerify('0801-####-#####'),
            'telefono' => '9999-0000',
            'turno' => '24_horas',
            'activo' => true,
        ]);
    };

    // Turno con inicio en la hora de Honduras
    $this->turno = fn (Guardia $guardia, string $inicio, int $horas = 12, array $datos = []): Turno => Turno::query()->create([
        'residencial_id' => $guardia->residencial_id,
        'guardia_id' => $guardia->id,
        'tipo' => 'dia',
        'inicio' => CarbonImmutable::parse($inicio, 'America/Tegucigalpa')->utc(),
        'fin' => CarbonImmutable::parse($inicio, 'America/Tegucigalpa')->addHours($horas)->utc(),
        ...$datos,
    ]);

    $this->carlos = ($this->guardia)('Carlos Mejía');
    $this->ana = ($this->guardia)('Ana López');

    // Carlos está en turno desde anoche y Ana entra a las 10:00
    $this->deCarlos = ($this->turno)($this->carlos, '2026-11-17 22:00', 12, ['inicio_real' => CarbonImmutable::parse('2026-11-18 03:55', 'UTC')]);
    $this->deAna = ($this->turno)($this->ana, '2026-11-18 10:00');

    $this->entregar = fn (array $cambios = [], ?Turno $turno = null, ?Guardia $guardia = null) => $this->actingAs(($guardia ?? $this->carlos)->cuenta)
        ->post(route('vigilancia.turnos.entregar', $turno ?? $this->deCarlos), [
            'guardia_recibe_id' => $this->ana->id,
            'novedades' => 'El portón 2 se quedó sin luz.',
            ...$cambios,
        ]);
});

test('the guard on duty sees the shift to hand over and who comes next', function () {
    $this->actingAs($this->carlos->cuenta)->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/mis-turnos')
            ->where('actual.id', $this->deCarlos->id)
            ->where('actual.inicio_real', '2026-11-17 21:55')
            ->where('pendiente', null)
            ->where('sugerido', $this->ana->id)
            ->where('receptores', [['id' => $this->ana->id, 'nombre' => 'Ana López']]));
});

test('a guard hands over the shift with the news and the other guard confirms it', function () {
    ($this->entregar)()->assertSessionHasNoErrors();

    $entrega = EntregaTurno::query()->sole();

    expect($entrega->turno_id)->toBe($this->deCarlos->id)
        ->and($entrega->guardia_entrega_id)->toBe($this->carlos->id)
        ->and($entrega->guardia_recibe_id)->toBe($this->ana->id)
        ->and($entrega->novedades)->toBe('El portón 2 se quedó sin luz.')
        ->and($entrega->recibido_en)->toBeNull();

    $this->actingAs($this->ana->cuenta)->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pendiente.id', $entrega->id)
            ->where('pendiente.entrega', 'Carlos Mejía')
            ->where('pendiente.entregado_en', '2026-11-18 10:00')
            ->where('pendiente.novedades', 'El portón 2 se quedó sin luz.')
            ->where('actual.id', $this->deAna->id)
            ->where('actual.inicio_real', null));

    $this->travel(5)->minutes();

    $this->post(route('vigilancia.entregas.recibir', $entrega))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Turno recibido e iniciado.');

    expect($entrega->fresh()->recibido_en->toDateTimeString())->toBe('2026-11-18 16:05:00')
        ->and($this->deAna->fresh()->inicio_real->toDateTimeString())->toBe('2026-11-18 16:05:00')
        ->and(Auditoria::query()->pluck('accion')->all())->toBe(['turno_entregado', 'turno_recibido', 'turno_iniciado']);
});

test('the news are optional', function () {
    ($this->entregar)(['novedades' => '   '])->assertSessionHasNoErrors();

    expect(EntregaTurno::query()->sole()->novedades)->toBeNull();
});

test('after handing over the shift it is no longer in progress', function () {
    ($this->entregar)();

    $this->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('actual', null)
            ->where('turnos.0.entrega.recibe', 'Ana López')
            ->where('turnos.0.entrega.recibido_en', null));
});

test('a shift is handed over only once', function () {
    ($this->entregar)()->assertSessionHasNoErrors();
    ($this->entregar)(['guardia_recibe_id' => ($this->guardia)('Luis Rivera')->id])
        ->assertSessionHasErrors(['turno' => 'Este turno ya se entregó.']);

    expect(EntregaTurno::query()->count())->toBe(1);
});

test('a shift that has not started cannot be handed over', function () {
    ($this->entregar)(['guardia_recibe_id' => $this->carlos->id], $this->deAna, $this->ana)
        ->assertSessionHasErrors(['turno' => 'Inicia el turno antes de entregarlo.']);

    expect(EntregaTurno::query()->count())->toBe(0);
});

test('a guard ends the shift without a relief guard', function (?string $recibe) {
    ($this->entregar)(['guardia_recibe_id' => $recibe, 'novedades' => 'Todo tranquilo.'])->assertSessionHasNoErrors();

    $entrega = EntregaTurno::query()->sole();

    expect($entrega->guardia_recibe_id)->toBeNull()
        ->and($entrega->recibido_en)->toBeNull();

    // Nadie tiene una entrega por confirmar
    $this->actingAs($this->ana->cuenta)->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pendiente', null));

    $this->actingAs($this->carlos->cuenta)->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('actual', null)
            ->where('turnos.0.entrega.recibe', null)
            ->where('turnos.0.entrega.novedades', 'Todo tranquilo.'));
})->with(['vacío' => [''], 'nulo' => [null]]);

test('the receiving guard is validated', function (Closure $recibe, string $mensaje) {
    ($this->entregar)(['guardia_recibe_id' => $recibe($this)])->assertSessionHasErrors(['guardia_recibe_id' => $mensaje]);

    expect(EntregaTurno::query()->count())->toBe(0);
})->with([
    'él mismo' => [fn ($prueba) => $prueba->carlos->id, 'Elige al guardia que recibe el turno, no a ti.'],
    'inactivo' => [fn ($prueba) => tap(($prueba->guardia)('Luis Rivera'))->update(['activo' => false])->id, 'Elige un guardia activo del residencial.'],
    'de otro residencial' => [fn ($prueba) => ($prueba->guardia)('Luis Rivera', $prueba->otro)->id, 'Elige un guardia activo del residencial.'],
]);

test('the news cannot be too long', function () {
    ($this->entregar)(['novedades' => str_repeat('a', 2001)])->assertSessionHasErrors('novedades');
});

test('a guard starts the shift up to one hour before', function () {
    $luis = ($this->guardia)('Luis Rivera');
    $temprano = ($this->turno)($luis, '2026-11-18 10:45');
    $tarde = ($this->turno)($luis, '2026-11-19 11:30');

    $this->actingAs($luis->cuenta)->get(route('vigilancia.turnos.mios'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('actual.id', $temprano->id));

    $this->post(route('vigilancia.turnos.iniciar', $tarde))
        ->assertSessionHasErrors(['turno' => 'El turno se puede iniciar desde una hora antes.']);

    $this->post(route('vigilancia.turnos.iniciar', $temprano))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Turno iniciado.');

    expect($temprano->fresh()->inicio_real->toDateTimeString())->toBe('2026-11-18 16:00:00')
        ->and($tarde->fresh()->inicio_real)->toBeNull()
        ->and(Auditoria::query()->where('accion', 'turno_iniciado')->count())->toBe(1);

    $this->post(route('vigilancia.turnos.iniciar', $temprano))->assertSessionHasErrors(['turno' => 'Este turno ya empezó.']);
});

test('a shift that already ended cannot be started', function () {
    $luis = ($this->guardia)('Luis Rivera');
    $pasado = ($this->turno)($luis, '2026-11-17 08:00');

    $this->actingAs($luis->cuenta)->post(route('vigilancia.turnos.iniciar', $pasado))
        ->assertSessionHasErrors(['turno' => 'Este turno ya terminó.']);
});

test('a guard must hand over the shift in progress before starting another', function () {
    $siguiente = ($this->turno)($this->carlos, '2026-11-18 10:30', 1);

    $this->actingAs($this->carlos->cuenta)->post(route('vigilancia.turnos.iniciar', $siguiente))
        ->assertSessionHasErrors(['turno' => 'Entrega primero el turno que tienes en curso.']);
});

test('receiving without a shift about to start only confirms the handover', function () {
    $this->deAna->update(['inicio_real' => CarbonImmutable::now()]);
    ($this->entregar)();

    $this->actingAs($this->ana->cuenta)->post(route('vigilancia.entregas.recibir', EntregaTurno::query()->sole()))
        ->assertInertiaFlash('toast.message', 'Turno recibido.');

    expect(Auditoria::query()->where('accion', 'turno_iniciado')->count())->toBe(0);
});

test('a handover is confirmed only once and only by the guard who receives it', function () {
    ($this->entregar)();
    $entrega = EntregaTurno::query()->sole();

    $this->actingAs($this->carlos->cuenta)->post(route('vigilancia.entregas.recibir', $entrega))->assertForbidden();

    $this->actingAs($this->ana->cuenta)->post(route('vigilancia.entregas.recibir', $entrega))->assertSessionHasNoErrors();
    $this->post(route('vigilancia.entregas.recibir', $entrega))
        ->assertSessionHasErrors(['entrega' => 'Ya confirmaste que recibiste este turno.']);
});

test('a guard cannot start or hand over the shift of another guard', function () {
    $this->actingAs($this->ana->cuenta)->post(route('vigilancia.turnos.iniciar', $this->deCarlos))->assertForbidden();
    ($this->entregar)([], $this->deCarlos, $this->ana)->assertForbidden();

    expect(EntregaTurno::query()->count())->toBe(0);
});

test('only accounts of an active guard start and hand over shifts', function () {
    $this->actingAs(($this->usuario)('presidente'))->post(route('vigilancia.turnos.iniciar', $this->deAna))->assertForbidden();

    $this->ana->update(['activo' => false]);

    $this->actingAs($this->ana->cuenta)->post(route('vigilancia.turnos.iniciar', $this->deAna))->assertForbidden();

    expect($this->deAna->fresh()->inicio_real)->toBeNull();
});

test('without the permission the guard account does not start or hand over shifts', function () {
    DB::table('rol_permisos')->where('modulo', 'entrega_turnos')->delete();

    $this->actingAs($this->ana->cuenta)->get(route('vigilancia.turnos.mios'))->assertForbidden();
    $this->post(route('vigilancia.turnos.iniciar', $this->deAna))->assertForbidden();

    expect($this->deAna->fresh()->inicio_real)->toBeNull();
});

test('a shift of another residential is denied', function () {
    $ajeno = ($this->guardia)('Luis Rivera', $this->otro);
    $turno = ($this->turno)($ajeno, '2026-11-18 10:00');

    $this->actingAs($this->carlos->cuenta)->post(route('vigilancia.turnos.iniciar', $turno))->assertForbidden();

    expect(Auditoria::query()->where('accion', 'acceso_denegado')->count())->toBe(1);
});

test('the president sees in the calendar who handed over each shift and the news', function () {
    ($this->entregar)();

    $this->actingAs(($this->usuario)('presidente'))
        ->get(route('vigilancia.turnos.index', ['vista' => 'dia', 'dia' => '2026-11-18']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('turnosDelDia.0.inicio_real', '2026-11-17 21:55')
            ->where('turnosDelDia.0.entrega.recibe', 'Ana López')
            ->where('turnosDelDia.0.entrega.entregado_en', '2026-11-18 10:00')
            ->where('turnosDelDia.0.entrega.novedades', 'El portón 2 se quedó sin luz.')
            ->where('turnosDelDia.1.entrega', null));
});
