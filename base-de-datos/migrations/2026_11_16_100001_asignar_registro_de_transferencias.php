<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El tesorero registra transferencias en los residenciales existentes
     *
     * Es un módulo propio para que no dé permiso de cobrar en efectivo.
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
                'modulo' => 'transferencias',
                'accion' => 'registrar',
            ]);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'transferencias')->where('accion', 'registrar')->delete();
    }
};
