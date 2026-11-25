<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El presidente de cada residencial consulta la bitácora de accesos
     */
    public function up(): void
    {
        $presidente = DB::table('roles')->where('codigo', 'presidente')->value('id');

        if ($presidente === null) {
            return;
        }

        foreach (DB::table('residenciales')->pluck('id') as $residencial) {
            DB::table('rol_permisos')->insertOrIgnore([
                'residencial_id' => $residencial, 'rol_id' => $presidente,
                'modulo' => 'bitacora', 'accion' => 'ver',
            ]);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'bitacora')->delete();
    }
};
