<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El tesorero y el presidente verifican transferencias en los residenciales existentes (HU-26)
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', ['tesorero', 'presidente'])->pluck('id');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach ($roles as $rolId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $rolId,
                    'modulo' => 'transferencias',
                    'accion' => 'verificar',
                ]);
            }
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'transferencias')->where('accion', 'verificar')->delete();
    }
};
