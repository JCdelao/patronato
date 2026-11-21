<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un vehículo sin placa todavía (en trámite) se registra con un comprobante que valida su circulación
     *
     * Cada vehículo tiene placa o comprobante. Cuando llega la placa, el comprobante se conserva como historial.
     */
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        // Cambiar la columna reconstruye la tabla en SQLite; el índice por expresión se crea de nuevo después
        DB::statement('DROP INDEX IF EXISTS vehiculos_placa_activa_unique');

        Schema::table('vehiculos', function (Blueprint $table) {
            $table->string('placa', 20)->nullable()->change();
            $table->string('comprobante_tipo', 40)->nullable()->after('placa');
            $table->string('comprobante_descripcion', 100)->nullable()->after('comprobante_tipo');
            $table->string('comprobante_numero', 40)->nullable()->after('comprobante_descripcion');
            $table->date('comprobante_vence')->nullable()->after('comprobante_numero');
            $table->string('comprobante_path')->nullable()->after('comprobante_vence');
        });

        DB::statement('CREATE UNIQUE INDEX vehiculos_placa_activa_unique ON vehiculos (residencial_id, upper(placa)) WHERE activo AND placa IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX vehiculos_comprobante_activo_unique ON vehiculos (residencial_id, upper(comprobante_numero)) WHERE activo AND comprobante_numero IS NOT NULL');

        if ($pgsql) {
            DB::statement('ALTER TABLE vehiculos ADD CONSTRAINT vehiculos_identificado_check CHECK (placa IS NOT NULL OR (comprobante_tipo IS NOT NULL AND comprobante_numero IS NOT NULL AND comprobante_vence IS NOT NULL))');
            DB::statement("ALTER TABLE vehiculos ADD CONSTRAINT vehiculos_comprobante_tipo_check CHECK (comprobante_tipo IS NULL OR comprobante_tipo IN ('permiso_provisional', 'constancia_tramite', 'otro'))");
        }
    }

    /**
     * Revierte la migración; falla si quedó algún vehículo sin placa
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehiculos DROP CONSTRAINT IF EXISTS vehiculos_identificado_check');
            DB::statement('ALTER TABLE vehiculos DROP CONSTRAINT IF EXISTS vehiculos_comprobante_tipo_check');
        }

        DB::statement('DROP INDEX IF EXISTS vehiculos_comprobante_activo_unique');
        DB::statement('DROP INDEX IF EXISTS vehiculos_placa_activa_unique');

        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropColumn(['comprobante_tipo', 'comprobante_descripcion', 'comprobante_numero', 'comprobante_vence', 'comprobante_path']);
            $table->string('placa', 20)->nullable(false)->change();
        });

        DB::statement('CREATE UNIQUE INDEX vehiculos_placa_activa_unique ON vehiculos (residencial_id, upper(placa)) WHERE activo');
    }
};
