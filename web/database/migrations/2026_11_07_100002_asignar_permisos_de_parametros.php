<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permisos iniciales de los parámetros de cobro
     */
    private const PERMISOS = [
        'presidente' => ['ver'],
        'tesorero' => ['ver', 'registrar'],
        'fiscal' => ['ver'],
    ];

    /**
     * Asigna los permisos de parámetros a los residenciales existentes
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', array_keys(self::PERMISOS))->pluck('id', 'codigo');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (self::PERMISOS as $codigo => $acciones) {
                if (! $roles->has($codigo)) {
                    continue;
                }

                foreach ($acciones as $accion) {
                    DB::table('rol_permisos')->insertOrIgnore([
                        'residencial_id' => $residencialId,
                        'rol_id' => $roles[$codigo],
                        'modulo' => 'parametros',
                        'accion' => $accion,
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
        DB::table('rol_permisos')->where('modulo', 'parametros')->delete();
    }
};
