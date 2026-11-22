<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Roles que generan el estado de cuenta en PDF por defecto
     */
    private const ROLES = ['presidente', 'secretario', 'tesorero'];

    /**
     * Nombre con el que se anotan en rol_permisos_migrados los permisos que inserta
     */
    private const MIGRACION = '2026_11_22_200001_asignar_estado_cuenta_pdf';

    /**
     * Da el permiso del estado de cuenta en PDF a esos roles en los residenciales existentes
     *
     * Solo anota los que inserta; los que ya existían no se tocan. Los residenciales
     * nuevos lo reciben con sus permisos predeterminados.
     */
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('codigo', self::ROLES)->pluck('id');

        // El permiso y su anotación se guardan juntos o no se guarda ninguno
        DB::transaction(function () use ($roles) {
            foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
                foreach ($roles as $rolId) {
                    $permiso = [
                        'residencial_id' => $residencialId,
                        'rol_id' => $rolId,
                        'modulo' => 'estado_cuenta_pdf',
                        'accion' => 'ver',
                    ];

                    if (DB::table('rol_permisos')->insertOrIgnore($permiso) === 1) {
                        DB::table('rol_permisos_migrados')->insertOrIgnore([...$permiso, 'migracion' => self::MIGRACION, 'created_at' => now()]);
                    }
                }
            }
        });
    }

    /**
     * Quita solo los permisos que insertó esta migración y que nadie volvió a guardar
     *
     * Se conservan los que ya existían y los que el administrador asignó o guardó
     * después, aunque sean del presidente, el secretario o el tesorero.
     */
    public function down(): void
    {
        DB::transaction(function () {
            DB::table('rol_permisos')
                ->whereExists(fn ($consulta) => $consulta
                    ->from('rol_permisos_migrados')
                    ->whereColumn('rol_permisos_migrados.residencial_id', 'rol_permisos.residencial_id')
                    ->whereColumn('rol_permisos_migrados.rol_id', 'rol_permisos.rol_id')
                    ->whereColumn('rol_permisos_migrados.modulo', 'rol_permisos.modulo')
                    ->whereColumn('rol_permisos_migrados.accion', 'rol_permisos.accion')
                    ->where('rol_permisos_migrados.migracion', self::MIGRACION))
                ->delete();

            // La cascada ya quitó las anotaciones; esto limpia cualquier resto
            DB::table('rol_permisos_migrados')->where('migracion', self::MIGRACION)->delete();
        });
    }
};
