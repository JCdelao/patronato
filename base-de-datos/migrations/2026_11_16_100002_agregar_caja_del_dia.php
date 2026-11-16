<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada caja es de un día y el presidente también puede cerrarlas
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->date('fecha')->nullable()->after('estado');
        });

        // Las cajas existentes toman el día en que se abrieron, en la hora de Honduras
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE cajas SET fecha = (abierta_en AT TIME ZONE 'UTC' AT TIME ZONE 'America/Tegucigalpa')::date");
            DB::statement('ALTER TABLE cajas ALTER COLUMN fecha SET NOT NULL');
        }

        // Una caja abierta por cobrador y día; las de días anteriores esperan su cierre
        DB::statement('DROP INDEX IF EXISTS cajas_una_abierta_por_cobrador_unique');
        DB::statement("CREATE UNIQUE INDEX cajas_una_abierta_por_dia_unique ON cajas (residencial_id, cobrador_id, fecha) WHERE estado = 'abierta'");

        $presidente = DB::table('roles')->where('codigo', 'presidente')->value('id');

        if ($presidente === null) {
            return;
        }

        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            DB::table('rol_permisos')->insertOrIgnore([
                'residencial_id' => $residencialId,
                'rol_id' => $presidente,
                'modulo' => 'cajas',
                'accion' => 'registrar',
            ]);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        $presidente = DB::table('roles')->where('codigo', 'presidente')->value('id');

        DB::table('rol_permisos')->where('rol_id', $presidente)->where('modulo', 'cajas')->delete();

        DB::statement('DROP INDEX IF EXISTS cajas_una_abierta_por_dia_unique');
        DB::statement("CREATE UNIQUE INDEX cajas_una_abierta_por_cobrador_unique ON cajas (cobrador_id) WHERE estado = 'abierta'");

        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn('fecha');
        });
    }
};
