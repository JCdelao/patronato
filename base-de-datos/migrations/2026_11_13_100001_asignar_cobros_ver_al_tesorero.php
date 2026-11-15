<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Da al tesorero el permiso de ver los cobros en los residenciales existentes
     */
    public function up(): void
    {
        $tesorero = DB::table('roles')->where('codigo', 'tesorero')->value('id');

        if ($tesorero === null) {
            return;
        }

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            DB::table('rol_permisos')->insertOrIgnore([
                'residencial_id' => $residencialId,
                'rol_id' => $tesorero,
                'modulo' => 'cobros',
                'accion' => 'ver',
            ]);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        $tesorero = DB::table('roles')->where('codigo', 'tesorero')->value('id');

        DB::table('rol_permisos')
            ->where('rol_id', $tesorero)
            ->where('modulo', 'cobros')
            ->where('accion', 'ver')
            ->delete();
    }
};
