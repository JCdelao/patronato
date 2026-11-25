<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_residencial', function (Blueprint $table) {
            $table->unsignedSmallInteger('meses_tolerancia_acceso')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_residencial', function (Blueprint $table) {
            $table->dropColumn('meses_tolerancia_acceso');
        });
    }
};
