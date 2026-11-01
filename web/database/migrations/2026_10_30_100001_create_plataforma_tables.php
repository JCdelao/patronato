<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aplica la migración
     */
    public function up(): void
    {
        Schema::create('residenciales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->string('logo_path')->nullable();
            $table->enum('estado', ['activo', 'suspendido'])->default('activo');
            $table->timestamps();
        });

        Schema::create('configuracion_residencial', function (Blueprint $table) {
            $table->foreignId('residencial_id')->primary()->constrained('residenciales');
            $table->unsignedSmallInteger('dias_gracia')->default(0);
            $table->unsignedSmallInteger('meses_tolerancia')->default(0);
            $table->boolean('permite_abono_parcial')->default(false);
            $table->boolean('permite_saldo_favor')->default(false);
            $table->boolean('doble_control_transferencias')->default(false);
            $table->boolean('acceso_depende_pago')->default(false);
            $table->boolean('pendiente_cuenta_al_dia')->default(false);
            $table->unsignedSmallInteger('max_intentos_login')->default(5);
            $table->unsignedSmallInteger('minutos_bloqueo_login')->default(15);
            $table->unsignedSmallInteger('minutos_inactividad_sesion')->default(30);
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion_residencial');
        Schema::dropIfExists('residenciales');
    }
};
