<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turno habitual de cada guardia; las fechas de cada turno se programan aparte
     */
    public function up(): void
    {
        Schema::table('guardias', function (Blueprint $table) {
            $table->string('turno', 20)->default('dia')->after('telefono');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE guardias ADD CONSTRAINT guardias_turno_check CHECK (turno IN ('dia', 'tarde', 'noche', '24_horas', 'rotativo'))");
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE guardias DROP CONSTRAINT IF EXISTS guardias_turno_check');
        }

        Schema::table('guardias', function (Blueprint $table) {
            $table->dropColumn('turno');
        });
    }
};
