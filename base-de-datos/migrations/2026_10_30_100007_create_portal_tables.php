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
        Schema::create('solicitudes_correccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('residente_id');
            $table->foreignId('vivienda_id')->nullable();
            $table->string('tipo', 40);
            $table->text('descripcion');
            $table->string('adjunto_path')->nullable();
            $table->enum('estado', ['recibida', 'en_revision', 'resuelta', 'rechazada'])->default('recibida');
            $table->text('respuesta')->nullable();
            $table->foreignId('atendida_por')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['residencial_id', 'residente_id'])->references(['residencial_id', 'id'])->on('residentes');
            $table->foreign(['residencial_id', 'vivienda_id'])->references(['residencial_id', 'id'])->on('viviendas');
            $table->index(['residencial_id', 'estado']);
        });
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_correccion');
    }
};
