<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $presidente = DB::table('roles')->where('codigo', 'presidente')->value('id');
        if ($presidente === null) {
            return;
        }

        foreach (DB::table('residenciales')->pluck('id') as $residencial) {
            DB::table('rol_permisos')->insertOrIgnore([
                'residencial_id' => $residencial, 'rol_id' => $presidente,
                'modulo' => 'autorizados_frecuentes', 'accion' => 'registrar',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('rol_permisos')->where('modulo', 'autorizados_frecuentes')
            ->where('accion', 'registrar')
            ->whereIn('rol_id', DB::table('roles')->where('codigo', 'presidente')->select('id'))
            ->delete();
    }
};
