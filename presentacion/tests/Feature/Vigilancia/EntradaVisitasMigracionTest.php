<?php

use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Vivienda;

test('the partial visit index and historical rows survive up, down and up', function () {
    $residencial = Residencial::query()->create(['nombre' => 'Histórico']);
    $vivienda = Vivienda::query()->create([
        'residencial_id' => $residencial->id,
        'tipo_vivienda_id' => tipoVivienda($residencial->id),
        'codigo' => 'A-01',
    ]);
    $guardia = Guardia::query()->create([
        'residencial_id' => $residencial->id, 'nombre' => 'Guardia ficticio',
        'identidad' => 'G-01', 'turno' => 'dia',
    ]);
    $medio = MedioEntrada::query()->where('residencial_id', $residencial->id)->where('nombre', 'A pie')->firstOrFail();
    $id = DB::table('visitas')->insertGetId([
        'residencial_id' => $residencial->id, 'vivienda_id' => $vivienda->id,
        'guardia_entrada_id' => $guardia->id, 'medio_entrada_id' => $medio->id,
        'identificador' => '226e83e2-8113-4b69-82d9-25736c098688',
        'nombre_visitante' => 'Visitante ficticio', 'entrada_en' => '2026-11-22 15:00:00',
    ]);
    $historica = (array) DB::table('visitas')->find($id);
    unset($historica['turno_entrada_id']);
    $migracion = require app()->databasePath('migrations/2026_11_22_210001_preparar_registro_de_entradas.php');

    $verificar = function () use ($id, $historica) {
        $sql = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('name', 'visitas_dentro_index')->value('sql')
            : DB::table('pg_indexes')->where('schemaname', 'public')->where('indexname', 'visitas_dentro_index')->value('indexdef');
        expect($sql)->toMatch('/WHERE\s+\(?\s*"?salida_en"?\s+IS\s+NULL/i');

        $actual = (array) DB::table('visitas')->find($id);
        expect($actual['turno_entrada_id'] ?? null)->toBeNull();
        unset($actual['turno_entrada_id']);
        expect($actual)->toEqual($historica);
    };

    $verificar();
    $migracion->down();
    $verificar();
    $migracion->up();
    $verificar();
});
