<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Únicos roles que pueden registrar correcciones al saldo de una vivienda
     */
    private const ROLES = ['presidente', 'tesorero'];

    /**
     * Da el permiso de ajustes al presidente y al tesorero de los residenciales existentes
     *
     * Los residenciales nuevos lo reciben con sus permisos predeterminados.
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', self::ROLES)->pluck('id');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach ($roles as $rolId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $rolId,
                    'modulo' => 'ajustes',
                    'accion' => 'aprobar',
                ]);
            }
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'ajustes')->delete();
    }
};
