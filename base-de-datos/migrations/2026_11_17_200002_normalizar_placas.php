<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Guarda cada placa en su forma única: solo letras y números, en mayúsculas
     *
     * Si dos placas activas del mismo residencial quedarían iguales, la segunda
     * se deja como estaba para no romper el índice único; el secretario la revisa.
     */
    public function up(): void
    {
        $vehiculos = DB::table('vehiculos')->orderByDesc('activo')->orderBy('id')->get(['id', 'residencial_id', 'placa', 'activo']);
        $forma = fn (string $placa): string => (string) preg_replace('/[^\pL\pN]/u', '', mb_strtoupper($placa));
        $clave = fn (stdClass $vehiculo): string => $vehiculo->residencial_id.':'.$forma((string) $vehiculo->placa);

        // Primero se reservan las placas activas que ya estaban en su forma única
        $usadas = [];

        foreach ($vehiculos as $vehiculo) {
            if ((bool) $vehiculo->activo && $forma((string) $vehiculo->placa) === $vehiculo->placa) {
                $usadas[$clave($vehiculo)] = true;
            }
        }

        foreach ($vehiculos as $vehiculo) {
            $placa = $forma((string) $vehiculo->placa);

            if ($placa === '' || $placa === $vehiculo->placa) {
                continue;
            }

            if ((bool) $vehiculo->activo) {
                if (isset($usadas[$clave($vehiculo)])) {
                    continue;
                }

                $usadas[$clave($vehiculo)] = true;
            }

            DB::table('vehiculos')->where('id', $vehiculo->id)->update(['placa' => $placa]);
        }
    }

    /**
     * No se puede recuperar cómo estaba escrita cada placa; la forma única sigue siendo válida
     */
    public function down(): void {}
};
