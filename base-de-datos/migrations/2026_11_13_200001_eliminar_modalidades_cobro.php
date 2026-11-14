<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elimina la modalidad de cobro: lo que se cobra depende solo de los conceptos y sus tarifas
     */
    public function up(): void
    {
        Schema::dropIfExists('modalidades_cobro');
    }

    /**
     * Vuelve a crear la tabla vacía
     */
    public function down(): void
    {
        Schema::create('modalidades_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->enum('modalidad', ['unificada', 'diversificada']);
            $table->json('desglose')->nullable();
            $table->date('vigente_desde');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->unique(['residencial_id', 'vigente_desde']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE modalidades_cobro ADD CONSTRAINT modalidades_dia_uno_check CHECK (EXTRACT(DAY FROM vigente_desde) = 1)');
        }
    }
};
