<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dinero que tenía el patronato al empezar a registrar en el sistema
     *
     * Entra al informe financiero como un movimiento más en su fecha.
     */
    public function up(): void
    {
        Schema::table('configuracion_residencial', function (Blueprint $table) {
            $table->decimal('saldo_apertura', 12, 2)->nullable();
            $table->date('fecha_apertura')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE configuracion_residencial ADD CONSTRAINT configuracion_apertura_check CHECK ((saldo_apertura IS NULL) = (fecha_apertura IS NULL))');
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE configuracion_residencial DROP CONSTRAINT IF EXISTS configuracion_apertura_check');
        }

        Schema::table('configuracion_residencial', function (Blueprint $table) {
            $table->dropColumn(['saldo_apertura', 'fecha_apertura']);
        });
    }
};
