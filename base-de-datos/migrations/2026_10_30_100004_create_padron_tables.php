<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aplica la migración
     */
    public function up(): void
    {
        Schema::create('viviendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->string('codigo', 30);
            $table->enum('tipo', ['casa', 'apartamento']);
            $table->string('edificio_torre', 60)->nullable();
            $table->string('calle_bloque', 60)->nullable();
            $table->string('numero', 20)->nullable();
            $table->enum('estado_ocupacion', ['ocupada', 'desocupada'])->default('ocupada');
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['residencial_id', 'codigo']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('residentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users');
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('identidad', 30)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->string('foto_path')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['residencial_id', 'identidad']);
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('vivienda_residentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id');
            $table->foreignId('residente_id')->index();
            $table->enum('tipo', ['propietario', 'inquilino', 'familiar']);
            $table->boolean('es_responsable_pago')->default(false);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->foreign(['residencial_id', 'residente_id'])->references(['residencial_id', 'id'])->on('residentes');
        });

        // Como máximo un responsable de pago vigente por vivienda
        DB::statement('CREATE UNIQUE INDEX vivienda_un_responsable_unique ON vivienda_residentes (vivienda_id) WHERE es_responsable_pago AND fecha_fin IS NULL');
        DB::statement('CREATE UNIQUE INDEX vivienda_residente_vigente_unique ON vivienda_residentes (vivienda_id, residente_id) WHERE fecha_fin IS NULL');

        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vivienda_id')->index();
            $table->enum('tipo', ['carro', 'moto', 'otro']);
            $table->string('placa', 20);
            $table->string('marca', 60)->nullable();
            $table->string('modelo', 60)->nullable();
            $table->string('color', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->unique(['residencial_id', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX vehiculos_placa_activa_unique ON vehiculos (residencial_id, upper(placa)) WHERE activo');

        Schema::create('vehiculo_conductores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vehiculo_id')->index();
            $table->foreignId('residente_id')->nullable();
            $table->string('nombre', 150)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->foreign(['residencial_id', 'vehiculo_id'])->references(['residencial_id', 'id'])->on('vehiculos');
            $table->foreign(['residencial_id', 'residente_id'])->references(['residencial_id', 'id'])->on('residentes');
        });

        Schema::create('stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('vehiculo_id')->index();
            $table->string('numero', 30);
            $table->enum('estado', ['activo', 'vencido', 'suspendido', 'reemplazado', 'dado_de_baja'])->default('activo');
            $table->date('fecha_emision');
            $table->text('motivo')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'vehiculo_id'])->references(['residencial_id', 'id'])->on('vehiculos');
        });

        DB::statement("CREATE UNIQUE INDEX stickers_numero_activo_unique ON stickers (residencial_id, numero) WHERE estado = 'activo'");

        Schema::create('carnets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('residente_id')->index();
            $table->string('numero', 30);
            $table->date('fecha_emision');
            $table->date('vigente_hasta')->nullable();
            $table->enum('estado', ['activo', 'vencido', 'suspendido', 'reemplazado', 'dado_de_baja'])->default('activo');
            $table->text('motivo')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'residente_id'])->references(['residencial_id', 'id'])->on('residentes');
            $table->unique(['residencial_id', 'numero']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehiculo_conductores ADD CONSTRAINT vehiculo_conductores_identificado_check CHECK (residente_id IS NOT NULL OR nombre IS NOT NULL)');
            DB::statement("ALTER TABLE stickers ADD CONSTRAINT stickers_reemplazo_motivo_check CHECK (estado <> 'reemplazado' OR motivo IS NOT NULL)");
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('carnets');
        Schema::dropIfExists('stickers');
        Schema::dropIfExists('vehiculo_conductores');
        Schema::dropIfExists('vehiculos');
        Schema::dropIfExists('vivienda_residentes');
        Schema::dropIfExists('residentes');
        Schema::dropIfExists('viviendas');
    }
};
