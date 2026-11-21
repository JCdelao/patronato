<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permisos de vigilancia en los residenciales existentes: el presidente registra a los guardias
     */
    private const PERMISOS = [
        'presidente' => ['ver', 'registrar'],
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
                        'modulo' => 'vigilancia',
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
        DB::table('rol_permisos')->where('modulo', 'vigilancia')->delete();
    }
};
