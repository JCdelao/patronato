<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\ModalidadCobro;

class ModalidadRepositorio extends Repositorio
{
    /**
     * Modalidad más reciente que empieza en la fecha o antes
     */
    public function vigenteEn(int $residencialId, string $fecha): ?ModalidadCobro
    {
        return ModalidadCobro::query()
            ->where('residencial_id', $residencialId)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /**
     * Primera modalidad que empieza después de la fecha
     */
    public function siguienteA(int $residencialId, string $fecha): ?ModalidadCobro
    {
        return ModalidadCobro::query()
            ->where('residencial_id', $residencialId)
            ->whereDate('vigente_desde', '>', $fecha)
            ->orderBy('vigente_desde')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): ModalidadCobro
    {
        return ModalidadCobro::query()->create($datos);
    }
}
