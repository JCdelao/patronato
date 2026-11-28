<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Acciones de la bandeja de solicitudes de corrección por rol (HU-54)
     */
    private const PERMISOS = [
        'presidente' => ['ver', 'registrar'],
        'secretario' => ['ver', 'registrar'],
        'tesorero' => ['ver', 'registrar'],
        'fiscal' => ['ver'],
    ];

    /**
     * Da los permisos de la bandeja en los residenciales existentes
     *
     * Los residenciales nuevos los reciben con sus permisos predeterminados.
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', array_keys(self::PERMISOS))->pluck('id', 'codigo');

        foreach (DB::table('residenciales')->pluck('id') as $residencial) {
            foreach ($roles as $codigo => $rolId) {
                foreach (self::PERMISOS[$codigo] as $accion) {
                    DB::table('rol_permisos')->insertOrIgnore([
                        'residencial_id' => $residencial, 'rol_id' => $rolId,
                        'modulo' => 'solicitudes', 'accion' => $accion,
                    ]);
                }
            }
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'solicitudes')->delete();
    }
};
