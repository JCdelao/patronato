<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el día de generación de cargos y el registro de cada generación
     */
    public function up(): void
    {
        Schema::table('configuracion_residencial', function (Blueprint $table) {
            // Del 1 al 28 para que exista en todos los meses
            $table->unsignedSmallInteger('dia_generacion_cargos')->default(1)->after('pendiente_cuenta_al_dia');
        });

        Schema::create('generaciones_cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->date('periodo');
            $table->unsignedInteger('cargos');
            $table->boolean('automatica');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->index(['residencial_id', 'periodo']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE configuracion_residencial ADD CONSTRAINT configuracion_dia_generacion_check CHECK (dia_generacion_cargos BETWEEN 1 AND 28)');
            DB::statement('ALTER TABLE generaciones_cargos ADD CONSTRAINT generaciones_periodo_check CHECK (EXTRACT(DAY FROM periodo) = 1)');
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('generaciones_cargos');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE configuracion_residencial DROP CONSTRAINT IF EXISTS configuracion_dia_generacion_check');
        }

        Schema::table('configuracion_residencial', function (Blueprint $table) {
            $table->dropColumn('dia_generacion_cargos');
        });
    }
};
