<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Patronato\Modules\Identidad\Services\PermisoService;

return new class extends Migration
{
    /**
     * Da la matriz inicial a los residenciales que ya existían antes de la HU-07.
     */
    public function up(): void
    {
        $permisos = app(PermisoService::class);

        foreach (DB::table('residenciales')->pluck('id') as $id) {
            $permisos->asignarPredeterminados((int) $id);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rol_permisos')->delete();
    }
};
