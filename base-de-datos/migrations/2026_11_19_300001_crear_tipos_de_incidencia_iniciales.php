<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Patronato\Negocio\Services\TipoIncidenciaService;

return new class extends Migration
{
    /**
     * Aplica la migración
     */
    public function up(): void
    {
        // Los residenciales que ya existen reciben el catálogo inicial; los nuevos lo reciben al crearse
        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            DB::table('tipos_incidencia')->insertOrIgnore(array_map(fn (string $nombre): array => [
                'residencial_id' => $residencialId,
                'nombre' => $nombre,
                'activo' => true,
            ], TipoIncidenciaService::PREDETERMINADOS));
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        // Solo se quitan los tipos iniciales que ninguna incidencia usa
        DB::table('tipos_incidencia')
            ->whereIn('nombre', TipoIncidenciaService::PREDETERMINADOS)
            ->whereNotIn('id', DB::table('incidencias')->select('tipo_incidencia_id'))
            ->delete();
    }
};
