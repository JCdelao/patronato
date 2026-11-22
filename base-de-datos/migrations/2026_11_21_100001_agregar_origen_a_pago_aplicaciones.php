<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue lo aplicado al cobrar de lo aplicado después como saldo a favor
     *
     * El comprobante y el estado de cuenta muestran solo lo aplicado al cobrar. El índice
     * único pasa a valer solo para eso: tras anular un pago, otro pago con saldo a favor
     * puede volver a cubrir un cargo que ya había cubierto, en una fila aparte.
     *
     * El índice único era el único que empezaba por pago_id; se agrega uno normal para
     * que las consultas de las aplicaciones de un pago lo sigan usando.
     */
    public function up(): void
    {
        Schema::table('pago_aplicaciones', function (Blueprint $table) {
            $table->enum('origen', ['pago', 'saldo_a_favor'])->default('pago');
            $table->index('pago_id');
        });

        Schema::table('pago_aplicaciones', function (Blueprint $table) {
            $table->dropUnique(['pago_id', 'cargo_id']);
        });

        DB::statement("CREATE UNIQUE INDEX pago_aplicaciones_pago_cargo_pago_unique ON pago_aplicaciones (pago_id, cargo_id) WHERE origen = 'pago'");
    }

    /**
     * Revierte la migración solo si no hay aplicaciones de saldo a favor
     *
     * Con ellas, quitar la columna mezclaría lo aplicado después con lo cobrado y
     * cambiaría comprobantes ya emitidos; no se borran ni se combinan registros.
     */
    public function down(): void
    {
        // Ninguna aplicación nueva entra entre la revisión y el cambio de la tabla
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE pago_aplicaciones IN SHARE ROW EXCLUSIVE MODE');
        }

        $conSaldo = DB::table('pago_aplicaciones')->where('origen', '<>', 'pago')->count();

        if ($conSaldo > 0) {
            throw new RuntimeException("No se puede revertir: existen {$conSaldo} aplicaciones de saldo a favor. Revertir las mezclaría con las del cobro y cambiaría comprobantes ya emitidos.");
        }

        DB::statement('DROP INDEX pago_aplicaciones_pago_cargo_pago_unique');

        Schema::table('pago_aplicaciones', function (Blueprint $table) {
            $table->unique(['pago_id', 'cargo_id']);
        });

        Schema::table('pago_aplicaciones', function (Blueprint $table) {
            $table->dropIndex(['pago_id']);
            $table->dropColumn('origen');
        });
    }
};
