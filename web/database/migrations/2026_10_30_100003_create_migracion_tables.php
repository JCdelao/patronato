<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Va antes de finanzas porque cargos, pagos y ajustes referencian el lote.
     */
    public function up(): void
    {
        Schema::create('lotes_migracion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id')->constrained('residenciales');
            $table->enum('tipo', ['analisis', 'prueba', 'definitiva']);
            $table->enum('estado', ['pendiente', 'procesado', 'aprobado', 'importado', 'revertido'])->default('pendiente');
            $table->string('archivo_nombre');
            $table->char('archivo_sha256', 64);
            $table->jsonb('informe_calidad')->nullable();
            $table->jsonb('reporte_conciliacion')->nullable();
            $table->text('correcciones')->nullable();
            $table->foreignId('ejecutado_por')->nullable()->constrained('users');
            $table->foreignId('aprobado_tesorero_por')->nullable()->constrained('users');
            $table->foreignId('aprobado_fiscal_por')->nullable()->constrained('users');
            $table->timestamp('ejecutado_en')->nullable();
            $table->timestamps();
            $table->unique(['residencial_id', 'id']);
        });

        Schema::create('migracion_errores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->index()->constrained('lotes_migracion')->cascadeOnDelete();
            $table->integer('linea')->nullable();
            $table->text('registro_original');
            $table->string('motivo');
            $table->timestamp('created_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            // La importación definitiva necesita a tesorero y fiscal (HU-60).
            DB::statement(<<<'SQL'
                ALTER TABLE lotes_migracion ADD CONSTRAINT lotes_definitiva_aprobada_check
                CHECK (tipo <> 'definitiva' OR estado NOT IN ('aprobado', 'importado')
                       OR (aprobado_tesorero_por IS NOT NULL
                           AND aprobado_fiscal_por IS NOT NULL
                           AND aprobado_tesorero_por <> aprobado_fiscal_por))
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migracion_errores');
        Schema::dropIfExists('lotes_migracion');
    }
};
