<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Módulos que reemplazan a cada acción del antiguo permiso de vigilancia
     */
    private const EQUIVALENCIAS = [
        'ver' => [['guardias', 'ver'], ['turnos', 'ver'], ['incidencias', 'ver']],
        'registrar' => [['guardias', 'registrar'], ['turnos', 'registrar'], ['tipos_incidencia', 'registrar']],
    ];

    /**
     * Permisos iniciales del guardia sobre sus turnos y sus incidencias
     */
    private const GUARDIA = [['entrega_turnos', 'registrar'], ['incidencias', 'registrar']];

    /**
     * Separa el permiso de vigilancia en guardias, turnos, incidencias y lo propio del guardia
     */
    public function up(): void
    {
        $guardiaId = DB::table('roles')->where('codigo', 'guardia')->value('id');

        foreach (DB::table('rol_permisos')->where('modulo', 'vigilancia')->get() as $permiso) {
            // El guardia no administra la vigilancia: sus permisos van abajo
            if ((int) $permiso->rol_id === (int) $guardiaId) {
                continue;
            }

            foreach (self::EQUIVALENCIAS[$permiso->accion] ?? [] as [$modulo, $accion]) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $permiso->residencial_id,
                    'rol_id' => $permiso->rol_id,
                    'modulo' => $modulo,
                    'accion' => $accion,
                ]);
            }
        }

        DB::table('rol_permisos')->where('modulo', 'vigilancia')->delete();

        if ($guardiaId === null) {
            return;
        }

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (self::GUARDIA as [$modulo, $accion]) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'rol_id' => $guardiaId,
                    'modulo' => $modulo,
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
        $guardiaId = DB::table('roles')->where('codigo', 'guardia')->value('id');

        foreach (DB::table('rol_permisos')->whereIn('modulo', ['guardias', 'turnos', 'tipos_incidencia', 'incidencias'])->get() as $permiso) {
            if ((int) $permiso->rol_id === (int) $guardiaId) {
                continue;
            }

            DB::table('rol_permisos')->insertOrIgnore([
                'residencial_id' => $permiso->residencial_id,
                'rol_id' => $permiso->rol_id,
                'modulo' => 'vigilancia',
                'accion' => $permiso->accion,
            ]);
        }

        DB::table('rol_permisos')->whereIn('modulo', ['guardias', 'turnos', 'tipos_incidencia', 'entrega_turnos', 'incidencias'])->delete();
    }
};
