<?php

use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Patronato\Negocio\Notificaciones\IncidenciaUrgente;
use Patronato\Negocio\Services\TipoIncidenciaService;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Incidencia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\TipoIncidencia;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;

beforeEach(function () {
    // Miércoles 18 de noviembre de 2026 a las 10:00 en Honduras
    $this->travelTo(CarbonImmutable::parse('2026-11-18 16:00:00', 'UTC'));

    Storage::fake('local');
    Notification::fake();

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
            'turno' => 'dia',
            'activo' => true,
        ]);
    };

    $this->presidente = ($this->usuario)('presidente');
    $this->carlos = ($this->guardia)('Carlos Mejía');

    // Sin el filtro del residencial de la sesión, para buscar el de cualquier residencial
    $this->tipo = fn (string $nombre, ?Residencial $residencial = null): TipoIncidencia => TipoIncidencia::query()
        ->withoutGlobalScopes()
        ->where('residencial_id', ($residencial ?? $this->residencial)->id)
        ->where('nombre', $nombre)
        ->firstOrFail();

    $this->registrar = fn (array $cambios = [], ?Guardia $guardia = null) => $this->actingAs(($guardia ?? $this->carlos)->cuenta)
        ->post(route('vigilancia.incidencias.store'), [
            'tipo_incidencia_id' => ($this->tipo)('Persona sospechosa')->id,
            'descripcion' => 'Un hombre revisaba los carros del bloque C.',
            'ocurrida_en' => '2026-11-18T09:40',
            ...$cambios,
        ]);
});

test('every residential starts with the default incident types', function () {
    expect(TipoIncidencia::query()->where('residencial_id', $this->residencial->id)->orderBy('id')->pluck('nombre')->all())
        ->toBe(TipoIncidenciaService::PREDETERMINADOS);
});

test('a guard registers an incident linked to the shift in progress', function () {
    $turno = Turno::query()->create([
        'residencial_id' => $this->residencial->id,
        'guardia_id' => $this->carlos->id,
        'tipo' => 'dia',
        'inicio' => CarbonImmutable::parse('2026-11-18 12:00', 'UTC'),
        'fin' => CarbonImmutable::parse('2026-11-19 00:00', 'UTC'),
        'inicio_real' => CarbonImmutable::parse('2026-11-18 12:00', 'UTC'),
    ]);

    ($this->registrar)()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Incidencia registrada.');

    $incidencia = Incidencia::query()->sole();

    expect($incidencia->guardia_id)->toBe($this->carlos->id)
        ->and($incidencia->turno_id)->toBe($turno->id)
        ->and($incidencia->ocurrida_en->toDateTimeString())->toBe('2026-11-18 15:40:00')
        ->and($incidencia->urgente)->toBeFalse()
        ->and($incidencia->foto_path)->toBeNull()
        ->and(Auditoria::query()->where('accion', 'incidencia_registrada')->count())->toBe(1);

    Notification::assertNothingSent();
});

test('without a shift in progress the incident has no shift', function () {
    ($this->registrar)()->assertSessionHasNoErrors();

    expect(Incidencia::query()->sole()->turno_id)->toBeNull();
});

test('the photo is optional and is kept on the private disk', function () {
    ($this->registrar)(['foto' => UploadedFile::fake()->image('ventana.jpg')])->assertSessionHasNoErrors();

    $incidencia = Incidencia::query()->sole();

    expect($incidencia->foto_path)->toStartWith("incidencias/{$this->residencial->id}/");
    Storage::disk('local')->assertExists($incidencia->foto_path);
});

test('an urgent incident is sent by email to who manages the vigilance', function () {
    $secretario = ($this->usuario)('secretario');
    $inactivo = ($this->usuario)('presidente');
    $inactivo->forceFill(['activo' => false])->save();
    $deOtro = ($this->usuario)('presidente', $this->otro);

    ($this->registrar)(['urgente' => '1'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Incidencia registrada. Se avisó al presidente por correo.');

    expect(Incidencia::query()->sole()->urgente)->toBeTrue();

    Notification::assertSentTo($this->presidente, IncidenciaUrgente::class, fn (IncidenciaUrgente $aviso): bool => $aviso->tipo === 'Persona sospechosa'
        && $aviso->guardia === 'Carlos Mejía'
        && $aviso->ocurridaEn === '18/11/2026 a las 09:40'
        && ! $aviso->conFoto);
    Notification::assertNotSentTo([$secretario, $inactivo, $deOtro, $this->carlos->cuenta], IncidenciaUrgente::class);
});

test('the incident is kept even if the urgent email fails', function () {
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Sin servidor de correo'));

    ($this->registrar)(['urgente' => '1'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect(Incidencia::query()->count())->toBe(1);
});

test('the incident data is validated', function (Closure $cambios, string $campo, string $mensaje) {
    ($this->registrar)($cambios($this))->assertSessionHasErrors([$campo => $mensaje]);

    expect(Incidencia::query()->count())->toBe(0);
})->with([
    'sin tipo' => [fn () => ['tipo_incidencia_id' => null], 'tipo_incidencia_id', 'Elige el tipo de incidencia.'],
    'tipo inactivo' => [fn ($prueba) => ['tipo_incidencia_id' => tap(($prueba->tipo)('Ruido'))->update(['activo' => false])->id], 'tipo_incidencia_id', 'Elige un tipo de incidencia activo.'],
    'tipo de otro residencial' => [fn ($prueba) => ['tipo_incidencia_id' => ($prueba->tipo)('Ruido', $prueba->otro)->id], 'tipo_incidencia_id', 'Elige un tipo de incidencia activo.'],
    'sin descripción' => [fn () => ['descripcion' => ''], 'descripcion', 'Describe lo que pasó.'],
    'descripción larga' => [fn () => ['descripcion' => str_repeat('a', 2001)], 'descripcion', 'La descripción no puede pasar de 2000 caracteres.'],
    'fecha mal escrita' => [fn () => ['ocurrida_en' => '18/11/2026'], 'ocurrida_en', 'Escribe la fecha y la hora en que ocurrió.'],
    'en el futuro' => [fn () => ['ocurrida_en' => '2026-11-18T10:30'], 'ocurrida_en', 'La incidencia no puede haber ocurrido en el futuro.'],
    'hace más de una semana' => [fn () => ['ocurrida_en' => '2026-11-11T09:00'], 'ocurrida_en', 'Solo se registran incidencias de la última semana.'],
    'foto en PDF' => [fn () => ['foto' => UploadedFile::fake()->create('foto.pdf', 100, 'application/pdf')], 'foto', 'La foto debe ser una imagen JPG o PNG.'],
    'foto muy grande' => [fn () => ['foto' => UploadedFile::fake()->image('foto.jpg')->size(6000)], 'foto', 'La foto no puede pasar de 5 MB.'],
]);

test('a few minutes ahead are accepted for the phone clock', function () {
    ($this->registrar)(['ocurrida_en' => '2026-11-18T10:04'])->assertSessionHasNoErrors();
});

test('the guard sees the active types and their incidents of the last week', function () {
    ($this->tipo)('Ruido')->update(['activo' => false]);
    ($this->registrar)();
    ($this->registrar)([], ($this->guardia)('Ana López'));
    Incidencia::query()->create([
        'residencial_id' => $this->residencial->id,
        'tipo_incidencia_id' => ($this->tipo)('Robo')->id,
        'guardia_id' => $this->carlos->id,
        'descripcion' => 'Antigua',
        'ocurrida_en' => CarbonImmutable::parse('2026-11-01 12:00', 'UTC'),
    ]);

    $this->actingAs($this->carlos->cuenta)->get(route('vigilancia.incidencias.mias'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/mis-incidencias')
            ->where('guardia', 'Carlos Mejía')
            ->has('incidencias', 1)
            ->where('incidencias.0.tipo', 'Persona sospechosa')
            ->where('incidencias.0.ocurrida_en', '2026-11-18 09:40')
            ->has('tipos', 5)
            ->where('tipos', fn ($tipos) => ! collect($tipos)->contains('nombre', 'Ruido')));
});

test('only accounts of an active guard register incidents', function () {
    $this->actingAs($this->presidente)->get(route('vigilancia.incidencias.mias'))->assertForbidden();
    $this->post(route('vigilancia.incidencias.store'), [])->assertForbidden();

    $this->carlos->update(['activo' => false]);
    ($this->registrar)()->assertForbidden();

    expect(Incidencia::query()->count())->toBe(0);
});

test('without the permission the guard account does not report incidents', function () {
    DB::table('rol_permisos')->where('modulo', 'incidencias')->where('accion', 'registrar')->delete();

    $this->actingAs($this->carlos->cuenta)->get(route('vigilancia.incidencias.mias'))->assertForbidden();
    ($this->registrar)()->assertForbidden();

    expect(Incidencia::query()->count())->toBe(0);
});

test('the president lists the incidents filtered by type and urgency', function () {
    ($this->registrar)(['urgente' => '1']);
    ($this->registrar)(['tipo_incidencia_id' => ($this->tipo)('Ruido')->id, 'ocurrida_en' => '2026-11-18T08:00']);
    ($this->registrar)([], ($this->guardia)('Luis Rivera', $this->otro));

    $this->actingAs($this->presidente)->get(route('vigilancia.incidencias.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('vigilancia/incidencias')
            ->has('incidencias.data', 2)
            ->where('incidencias.data.0.tipo', 'Persona sospechosa')
            ->where('incidencias.data.0.urgente', true)
            ->where('incidencias.data.0.guardia', 'Carlos Mejía')
            ->where('incidencias.data.1.tipo', 'Ruido')
            ->has('tipos', 6));

    $this->get(route('vigilancia.incidencias.index', ['urgentes' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('urgentes', true)->has('incidencias.data', 1));

    $this->get(route('vigilancia.incidencias.index', ['tipo' => ($this->tipo)('Ruido')->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('incidencias.data', 1)->where('incidencias.data.0.tipo', 'Ruido'));
});

test('only roles with the vigilance permission list the incidents', function () {
    $this->actingAs(($this->usuario)('secretario'))->get(route('vigilancia.incidencias.index'))->assertForbidden();
    $this->actingAs($this->carlos->cuenta)->get(route('vigilancia.incidencias.index'))->assertForbidden();
});

test('the photo is seen by the president and by the guard who registered it', function () {
    ($this->registrar)(['foto' => UploadedFile::fake()->image('ventana.png')]);
    $incidencia = Incidencia::query()->sole();

    $this->actingAs($this->carlos->cuenta)->get(route('vigilancia.incidencias.foto', $incidencia))->assertOk();
    $this->actingAs($this->presidente)->get(route('vigilancia.incidencias.foto', $incidencia))->assertOk();

    $this->actingAs(($this->guardia)('Ana López')->cuenta)->get(route('vigilancia.incidencias.foto', $incidencia))->assertForbidden();
    $this->actingAs(($this->usuario)('presidente', $this->otro))->get(route('vigilancia.incidencias.foto', $incidencia))->assertForbidden();
});

test('an incident without photo has nothing to show', function () {
    ($this->registrar)();

    $this->actingAs($this->presidente)->get(route('vigilancia.incidencias.foto', Incidencia::query()->sole()))->assertNotFound();
});

test('the president manages the catalog of incident types', function () {
    $this->actingAs($this->presidente)->post(route('vigilancia.tipos-incidencia.store'), ['nombre' => ' Fuga de agua '])
        ->assertSessionHasNoErrors();

    $fuga = ($this->tipo)('Fuga de agua');

    $this->post(route('vigilancia.tipos-incidencia.store'), ['nombre' => 'ROBO'])
        ->assertSessionHasErrors(['nombre' => 'Ya existe un tipo de incidencia con ese nombre.']);

    $this->put(route('vigilancia.tipos-incidencia.update', $fuga), ['nombre' => 'Fuga de agua o gas'])->assertSessionHasNoErrors();
    $this->put(route('vigilancia.tipos-incidencia.update', $fuga), ['nombre' => 'Ruido'])
        ->assertSessionHasErrors(['nombre' => 'Ya existe un tipo de incidencia con ese nombre.']);

    $this->post(route('vigilancia.tipos-incidencia.desactivar', $fuga))->assertSessionHasNoErrors();
    expect($fuga->fresh()->activo)->toBeFalse();

    $this->post(route('vigilancia.tipos-incidencia.activar', $fuga))->assertSessionHasNoErrors();
    expect($fuga->fresh())->nombre->toBe('Fuga de agua o gas')->activo->toBeTrue()
        ->and(Auditoria::query()->where('modulo', 'vigilancia')->pluck('accion')->all())
        ->toBe(['tipo_incidencia_creado', 'tipo_incidencia_actualizado', 'tipo_incidencia_desactivado', 'tipo_incidencia_activado']);
});

test('only roles that register vigilance manage the catalog', function () {
    $robo = ($this->tipo)('Robo');

    $this->actingAs($this->carlos->cuenta)->post(route('vigilancia.tipos-incidencia.store'), ['nombre' => 'Otro más'])->assertForbidden();
    $this->post(route('vigilancia.tipos-incidencia.desactivar', $robo))->assertForbidden();

    $this->actingAs(($this->usuario)('presidente', $this->otro))->post(route('vigilancia.tipos-incidencia.desactivar', $robo))->assertForbidden();

    expect($robo->fresh()->activo)->toBeTrue();
});
