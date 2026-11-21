<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipo de cada turno programado: día, tarde, noche o de 24 horas
     */
    public function up(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->string('tipo', 20)->default('24_horas')->after('guardia_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE turnos ADD CONSTRAINT turnos_tipo_check CHECK (tipo IN ('dia', 'tarde', 'noche', '24_horas'))");
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_tipo_check');
        }

        Schema::table('turnos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
