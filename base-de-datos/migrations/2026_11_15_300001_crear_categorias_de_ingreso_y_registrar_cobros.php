<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Categoría que reciben los cobros de cuotas
     */
    private const CUOTAS = 'Cuotas de mantenimiento';

    /**
     * Categorías de ingreso iniciales de cada residencial
     */
    private const CATEGORIAS = [
        self::CUOTAS,
        'Alquiler de áreas comunes',
        'Multas',
        'Donaciones',
        'Otros ingresos',
    ];

    /**
     * Crea las categorías de ingreso y registra como ingreso los cobros anteriores
     *
     * Un pago confirmado entra vigente y uno anulado entra anulado con su motivo,
     * para que el informe financiero incluya lo cobrado antes de esta versión.
     */
    public function up(): void
    {
        foreach (DB::table('residenciales')->pluck('id') as $residencialId) {
            foreach (self::CATEGORIAS as $nombre) {
                DB::table('categorias_financieras')->insertOrIgnore([
                    'residencial_id' => $residencialId,
                    'tipo' => 'ingreso',
                    'nombre' => $nombre,
                    'activa' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $cuotas = DB::table('categorias_financieras')
                ->where('residencial_id', $residencialId)
                ->where('tipo', 'ingreso')
                ->where('nombre', self::CUOTAS)
                ->value('id');

            $pagos = DB::table('pagos')
                ->join('viviendas', 'viviendas.id', '=', 'pagos.vivienda_id')
                ->where('pagos.residencial_id', $residencialId)
                ->whereIn('pagos.estado', ['confirmado', 'anulado'])
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('ingresos')->whereColumn('ingresos.pago_id', 'pagos.id'))
                ->orderBy('pagos.id')
                ->get(['pagos.id', 'pagos.estado', 'pagos.monto', 'pagos.fecha_pago', 'pagos.numero_recibo', 'pagos.motivo_anulacion', 'pagos.registrado_por', 'pagos.created_at', 'viviendas.codigo']);

            foreach ($pagos as $pago) {
                $anulado = $pago->estado === 'anulado';

                DB::table('ingresos')->insert([
                    'residencial_id' => $residencialId,
                    'categoria_id' => $cuotas,
                    'pago_id' => $pago->id,
                    'fecha' => $pago->fecha_pago,
                    'concepto' => "Cuota · recibo N.º {$pago->numero_recibo} · vivienda {$pago->codigo}",
                    'monto' => $pago->monto,
                    'numero_comprobante' => $pago->numero_recibo,
                    'estado' => $anulado ? 'anulado' : 'vigente',
                    'motivo_anulacion' => $anulado ? ($pago->motivo_anulacion ?? 'Pago anulado.') : null,
                    'registrado_por' => $pago->registrado_por,
                    'created_at' => $pago->created_at,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Revierte la migración: quita los ingresos de cobros y las categorías sin ingresos manuales
     */
    public function down(): void
    {
        DB::table('ingresos')->whereNotNull('pago_id')->delete();

        DB::table('categorias_financieras')
            ->where('tipo', 'ingreso')
            ->whereIn('nombre', self::CATEGORIAS)
            ->whereNotIn('id', DB::table('ingresos')->select('categoria_id'))
            ->delete();
    }
};
