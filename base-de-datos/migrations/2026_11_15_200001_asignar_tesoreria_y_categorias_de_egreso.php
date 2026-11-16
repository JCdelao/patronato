<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permisos de ingresos y egresos en los residenciales existentes:
     * el tesorero registra y anula; el presidente y el fiscal consultan
     */
    private const PERMISOS = [
        'tesorero' => ['ver', 'registrar', 'anular'],
        'presidente' => ['ver'],
        'fiscal' => ['ver'],
    ];

    /**
     * Categorías de egreso iniciales de cada residencial
     */
    private const CATEGORIAS = [
        'Vigilancia',
        'Mantenimiento y reparaciones',
        'Servicios públicos',
        'Limpieza y jardinería',
        'Papelería y administración',
        'Otros gastos',
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
                        'modulo' => 'tesoreria',
                        'accion' => $accion,
                    ]);
                }
            }

            foreach (self::CATEGORIAS as $nombre) {
                DB::table('categorias_financieras')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'tipo' => 'egreso',
                    'nombre' => $nombre,
                    'activa' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE egresos ADD CONSTRAINT egresos_forma_pago_check CHECK (forma_pago IS NULL OR forma_pago IN ('efectivo', 'transferencia', 'cheque'))");
        }
    }

    /**
     * Revierte la migración; las categorías se conservan si ya tienen egresos
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE egresos DROP CONSTRAINT IF EXISTS egresos_forma_pago_check');
        }

        DB::table('rol_permisos')->where('modulo', 'tesoreria')->delete();

        DB::table('categorias_financieras')
            ->where('tipo', 'egreso')
            ->whereIn('nombre', self::CATEGORIAS)
            ->whereNotIn('id', DB::table('egresos')->select('categoria_id'))
            ->delete();
    }
};
