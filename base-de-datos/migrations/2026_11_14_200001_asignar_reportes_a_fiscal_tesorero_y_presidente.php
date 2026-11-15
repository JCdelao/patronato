<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Roles que reciben el permiso de ver reportes
     */
    private const ROLES = ['fiscal', 'tesorero', 'presidente'];

    /**
     * Da el permiso de ver reportes en los residenciales existentes
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', self::ROLES)->pluck('id');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach ($roles as $rolId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $rolId,
                    'modulo' => 'reportes',
                    'accion' => 'ver',
                ]);
            }
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', self::ROLES)->pluck('id');

        DB::table('rol_permisos')->whereIn('rol_id', $roles)->where('modulo', 'reportes')->where('accion', 'ver')->delete();
    }
};
