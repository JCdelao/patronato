<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Patronato\Negocio\Services\PermisoService;

return new class extends Migration
{
    /**
     * Asigna los permisos iniciales a los residenciales existentes
     */
    public function up(): void
    {
        $permisos = app(PermisoService::class);

        foreach (DB::table('residenciales')->pluck('id') as $id) {
            $permisos->asignarPredeterminados((int) $id);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->delete();
    }
};
