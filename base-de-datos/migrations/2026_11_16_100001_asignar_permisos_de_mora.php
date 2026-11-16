<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Roles que pueden cambiar la regla de mora
     */
    private const ROLES = ['presidente', 'tesorero'];

    /**
     * Asigna el permiso de la regla de mora a los residenciales existentes
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', self::ROLES)->pluck('id');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach ($roles as $rolId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $rolId,
                    'modulo' => 'mora',
                    'accion' => 'registrar',
                ]);
            }
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'mora')->delete();
    }
};
