<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El residente usa el portal en los residenciales existentes
     *
     * Con el portal reporta sus transferencias (HU-25); además del permiso,
     * necesita una cuenta vinculada a un residente activo.
     */
    public function up(): void
    {
        $residente = DB::table('roles')->where('codigo', 'residente')->value('id');

        if ($residente === null) {
            return;
        }

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (['ver', 'registrar'] as $accion) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $residente,
                    'modulo' => 'portal',
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
        DB::table('rol_permisos')->where('modulo', 'portal')->whereIn('accion', ['ver', 'registrar'])->delete();
    }
};
