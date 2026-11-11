<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el desglose informativo de la cuota unificada
     */
    public function up(): void
    {
        Schema::table('modalidades_cobro', function (Blueprint $table) {
            $table->json('desglose')->nullable()->after('modalidad');
        });
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::table('modalidades_cobro', function (Blueprint $table) {
            $table->dropColumn('desglose');
        });
    }
};
