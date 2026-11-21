<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El guardia puede terminar su turno sin que otro lo releve
     */
    public function up(): void
    {
        Schema::table('entregas_turno', function (Blueprint $table) {
            $table->foreignId('guardia_recibe_id')->nullable()->change();
        });
    }

    /**
     * Revierte la migración; falla si hay turnos terminados sin relevo
     */
    public function down(): void
    {
        Schema::table('entregas_turno', function (Blueprint $table) {
            $table->foreignId('guardia_recibe_id')->nullable(false)->change();
        });
    }
};
