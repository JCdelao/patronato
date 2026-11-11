<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\PerteneceAResidencial;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\Tarifa;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;

beforeEach(function () {
    $this->seed(RolesSeeder::class);

    $this->propio = Residencial::query()->create(['nombre' => 'Propio']);
    $this->ajeno = Residencial::query()->create(['nombre' => 'Ajeno']);

    $rol = Rol::query()->where('codigo', Rol::SECRETARIO)->value('id');

    $this->usuario = User::factory()->create(['residencial_id' => $this->propio->id, 'rol_id' => $rol]);
    $this->usuarioAjeno = User::factory()->create(['residencial_id' => $this->ajeno->id, 'rol_id' => $rol]);

    $crear = function (Residencial $residencial, string $codigo): array {
        $vivienda = Vivienda::query()->create(['residencial_id' => $residencial->id, 'codigo' => $codigo, 'tipo' => 'casa']);
        $residente = Residente::query()->create(['residencial_id' => $residencial->id, 'nombres' => $codigo, 'apellidos' => 'X']);
        $concepto = ConceptoCobro::query()->create(['residencial_id' => $residencial->id, 'nombre' => 'Cuota', 'tipo' => 'recurrente']);

        ViviendaResidente::query()->create([
            'residencial_id' => $residencial->id, 'vivienda_id' => $vivienda->id, 'residente_id' => $residente->id, 'tipo' => 'propietario',
        ]);
        Tarifa::query()->create([
            'residencial_id' => $residencial->id, 'concepto_id' => $concepto->id, 'monto' => 100, 'vigente_desde' => '2026-01-01',
        ]);

        return [$vivienda, $residente];
    };

    [$this->viviendaPropia, $this->residentePropio] = $crear($this->propio, 'PROPIA');
    [$this->viviendaAjena, $this->residenteAjeno] = $crear($this->ajeno, 'AJENA');
});

test('every model with a residencial column is isolated', function () {
    $modelos = collect(File::glob(dirname(base_path()).'/persistencia/Models/*.php'))
        ->map(fn (string $ruta) => 'Patronato\\Persistencia\\Models\\'.basename($ruta, '.php'))
        // Las cuentas se filtran por residencial en cada consulta
        ->filter(fn (string $clase) => is_subclass_of($clase, Model::class) && $clase !== User::class)
        ->filter(function (string $clase) {
            /** @var Model $modelo */
            $modelo = new $clase;

            return Schema::hasColumn($modelo->getTable(), 'residencial_id');
        });

    expect($modelos)->not->toBeEmpty();

    // La bitácora se protege por permisos
    $sinAislar = $modelos
        ->reject(fn (string $clase) => $clase === Auditoria::class)
        ->reject(fn (string $clase) => in_array(PerteneceAResidencial::class, class_uses_recursive($clase), true));

    expect($sinAislar->values()->all())->toBe([]);
});

test('queries only return data from the user residencial', function (string $modelo) {
    $this->actingAs($this->usuario);

    $residenciales = $modelo::query()->pluck('residencial_id')->unique()->values()->all();

    expect($residenciales)->toBe([$this->propio->id]);
})->with([
    'viviendas' => Vivienda::class,
    'residentes' => Residente::class,
    'vinculos' => ViviendaResidente::class,
    'conceptos' => ConceptoCobro::class,
    'tarifas' => Tarifa::class,
]);

test('a user without residencial sees nothing and the platform admin sees everything', function () {
    $this->actingAs(User::factory()->create());

    expect(Vivienda::query()->count())->toBe(0);

    $this->actingAs(User::factory()->create([
        'rol_id' => Rol::query()->where('codigo', Rol::ADMIN_PLATAFORMA)->value('id'),
    ]));

    expect(Vivienda::query()->count())->toBe(2);
});

test('new records take the residencial of the user', function () {
    $this->actingAs($this->usuario);

    $vivienda = Vivienda::query()->create(['codigo' => 'NUEVA', 'tipo' => 'casa']);

    expect($vivienda->residencial_id)->toBe($this->propio->id);
});

test('opening another residencial record is denied and audited', function (Closure $peticion, string $tabla, string $registro) {
    $id = $this->{$registro}->id;

    $peticion($this->actingAs($this->usuario), $id)
        ->assertForbidden()
        ->assertSee('Acceso denegado');

    $auditoria = Auditoria::query()->sole();

    expect($auditoria->user_id)->toBe($this->usuario->id)
        ->and($auditoria->residencial_id)->toBe($this->propio->id)
        ->and($auditoria->accion)->toBe('acceso_denegado')
        ->and($auditoria->entidad)->toBe($tabla)
        ->and($auditoria->entidad_id)->toBe($id);
})->with([
    'editar vivienda' => [fn ($t, int $id) => $t->put(route('padron.viviendas.update', $id), ['codigo' => 'X', 'tipo' => 'casa', 'calle_bloque' => 'x', 'numero' => '1', 'estado_ocupacion' => 'ocupada', 'cuota' => '1']), 'viviendas', 'viviendaAjena'],
    'editar vivienda con datos invalidos' => [fn ($t, int $id) => $t->put(route('padron.viviendas.update', $id), []), 'viviendas', 'viviendaAjena'],
    'eliminar vivienda' => [fn ($t, int $id) => $t->delete(route('padron.viviendas.destroy', $id)), 'viviendas', 'viviendaAjena'],
    'reactivar vivienda' => [fn ($t, int $id) => $t->post(route('padron.viviendas.reactivar', $id)), 'viviendas', 'viviendaAjena'],
    'editar residente' => [fn ($t, int $id) => $t->put(route('padron.residentes.update', $id), []), 'residentes', 'residenteAjeno'],
    'dar de baja residente' => [fn ($t, int $id) => $t->delete(route('padron.residentes.destroy', $id)), 'residentes', 'residenteAjeno'],
    'agregar vivienda a residente' => [fn ($t, int $id) => $t->post(route('padron.residentes.agregar-vivienda', $id), []), 'residentes', 'residenteAjeno'],
]);

test('denied requests change nothing', function () {
    $this->actingAs($this->usuario);

    $this->delete(route('padron.viviendas.destroy', $this->viviendaAjena->id));
    $this->delete(route('padron.residentes.destroy', $this->residenteAjeno->id));

    $this->actingAs($this->usuarioAjeno);

    expect($this->viviendaAjena->refresh()->activa)->toBeTrue()
        ->and($this->residenteAjeno->refresh()->activo)->toBeTrue();
});

test('a record that does not exist is not audited', function () {
    $this->actingAs($this->usuario)
        ->delete(route('padron.viviendas.destroy', 999999))
        ->assertNotFound();

    expect(Auditoria::query()->count())->toBe(0);
});

test('own records keep working', function () {
    $this->actingAs($this->usuario)
        ->put(route('padron.residentes.update', $this->residentePropio->id), [
            'vivienda_id' => $this->viviendaPropia->id, 'tipo' => 'inquilino', 'nombres' => 'Nuevo', 'apellidos' => 'X',
        ])
        ->assertSessionHasNoErrors();

    expect($this->residentePropio->refresh()->nombres)->toBe('Nuevo')
        ->and(Auditoria::query()->where('accion', 'acceso_denegado')->count())->toBe(0);
});

test('the registered residenciales are only available to platform administrators', function () {
    $this->actingAs($this->usuario)->get(route('plataforma.residenciales.index'))->assertForbidden();
});
