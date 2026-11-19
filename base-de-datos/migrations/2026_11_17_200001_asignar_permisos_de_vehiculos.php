<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permisos de vehículos en los residenciales existentes:
     * el secretario registra y da de baja; el presidente y el guardia consultan
     */
    private const PERMISOS = [
        'secretario' => ['ver', 'registrar', 'anular'],
        'presidente' => ['ver'],
        'guardia' => ['ver'],
    ];

    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', array_keys(self::PERMISOS))->pluck('id', 'codigo');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (self::PERMISOS as $codigo => $acciones) {
                if (! isset($roles[$codigo])) {
                    continue;
                }

                foreach ($acciones as $accion) {
                    DB::table('rol_permisos')->insertOrIgnore([
                        'residencial_id' => $residencialId,
                        'rol_id' => $roles[$codigo],
                        'modulo' => 'vehiculos',
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
        DB::table('rol_permisos')->where('modulo', 'vehiculos')->delete();
    }
};
