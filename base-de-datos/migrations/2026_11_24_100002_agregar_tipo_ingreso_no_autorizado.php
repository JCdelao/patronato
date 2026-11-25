<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NOMBRE = 'Ingreso no autorizado';

    /**
     * Agrega el tipo de incidencia a los residenciales que todavía no lo tienen
     */
    public function up(): void
    {
        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            $existe = DB::table('tipos_incidencia')
                ->where('residencial_id', $residencialId)
                ->whereRaw('lower(nombre) = ?', [mb_strtolower(self::NOMBRE)])
                ->exists();

            if (! $existe) {
                DB::table('tipos_incidencia')->insert(['residencial_id' => $residencialId, 'nombre' => self::NOMBRE, 'activo' => true]);
            }
        }
    }

    /**
     * Revierte la migración; conserva el tipo donde alguna incidencia lo usa
     */
    public function down(): void
    {
        DB::table('tipos_incidencia')
            ->where('nombre', self::NOMBRE)
            ->whereNotIn('id', DB::table('incidencias')->select('tipo_incidencia_id'))
            ->delete();
    }
};
