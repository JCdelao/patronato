<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permisos de la anulación en los residenciales existentes:
     * el tesorero la solicita y el presidente o el fiscal la aprueban
     */
    private const PERMISOS = [
        'tesorero' => 'anular',
        'presidente' => 'aprobar',
        'fiscal' => 'aprobar',
    ];

    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', array_keys(self::PERMISOS))->pluck('id', 'codigo');

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (self::PERMISOS as $codigo => $accion) {
                if (! isset($roles[$codigo])) {
                    continue;
                }

                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $roles[$codigo],
                    'modulo' => 'cobros',
                    'accion' => $accion,
                ]);
            }
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'cobros')->whereIn('accion', ['anular', 'aprobar'])->delete();
    }
};
