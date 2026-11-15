<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitudes para anular un pago, que aprueba o rechaza un segundo directivo
     */
    public function up(): void
    {
        Schema::create('solicitudes_anulacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residencial_id');
            $table->foreignId('pago_id');
            $table->foreignId('solicitada_por')->constrained('users');
            $table->text('motivo');
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->foreignId('resuelta_por')->nullable()->constrained('users');
            $table->timestamp('resuelta_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamps();
            $table->foreign(['residencial_id', 'pago_id'])->references(['residencial_id', 'id'])->on('pagos');
            $table->index(['residencial_id', 'estado']);
            $table->index('pago_id');
        });

        // Un pago tiene como máximo una solicitud pendiente
        DB::statement("CREATE UNIQUE INDEX solicitudes_anulacion_una_pendiente_unique ON solicitudes_anulacion (pago_id) WHERE estado = 'pendiente'");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE solicitudes_anulacion
                    ADD CONSTRAINT solicitudes_anulacion_resuelta_check
                        CHECK (estado = 'pendiente' OR (resuelta_por IS NOT NULL AND resuelta_en IS NOT NULL)),
                    -- Quien resuelve la solicitud debe ser otro directivo.
                    ADD CONSTRAINT solicitudes_anulacion_resolutor_distinto_check
                        CHECK (resuelta_por IS NULL OR resuelta_por <> solicitada_por),
                    ADD CONSTRAINT solicitudes_anulacion_rechazo_motivo_check
                        CHECK (estado <> 'rechazada' OR motivo_rechazo IS NOT NULL);
                SQL);
        }
    }

    /**
     * Revierte la migración
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_anulacion');
    }
};
