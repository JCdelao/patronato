<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada concepto se cobra a todas las viviendas o solo a las que se eligen
     */
    public function up(): void
    {
        Schema::table('conceptos_cobro', function (Blueprint $table) {
            $table->string('alcance', 10)->default('todas')->after('tipo');
        });

        // Viviendas que pagan un concepto que se cobra solo a algunas
        Schema::create('concepto_viviendas', function (Blueprint $table) {
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->foreignId('concepto_id')->constrained('conceptos_cobro')->cascadeOnDelete();
            $table->foreignId('vivienda_id')->constrained('viviendas')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['concepto_id', 'vivienda_id']);
            $table->index('vivienda_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE conceptos_cobro ADD CONSTRAINT conceptos_alcance_check CHECK (alcance IN ('todas', 'algunas'))");
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('concepto_viviendas');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE conceptos_cobro DROP CONSTRAINT IF EXISTS conceptos_alcance_check');
        }

        Schema::table('conceptos_cobro', function (Blueprint $table) {
            $table->dropColumn('alcance');
        });
    }
};
