<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\EscaneoQr;
use Patronato\Persistencia\Models\Guardia;

class EscaneoQrRepositorio extends Repositorio
{
    /** @return Collection<int, EscaneoQr> */
    public function recientes(Guardia $guardia): Collection
    {
        return $this->delGuardia($guardia)->latest('id')->limit(30)->get();
    }

    public function registrar(Guardia $guardia, ?int $qrId, string $resultado): EscaneoQr
    {
        return EscaneoQr::query()->create([
            'residencial_id' => $guardia->residencial_id,
            'guardia_id' => $guardia->id,
            'qr_id' => $qrId,
            'escaneado_en' => now(),
            'resultado' => $resultado,
        ]);
    }

    public function obtener(Guardia $guardia, int $id): EscaneoQr
    {
        return $this->delGuardia($guardia)->findOrFail($id);
    }

    public function decidir(EscaneoQr $escaneo, string $decision): void
    {
        $escaneo->forceFill(['decision_final' => $decision])->save();
    }

    /** @return Builder<EscaneoQr> */
    private function delGuardia(Guardia $guardia): Builder
    {
        return EscaneoQr::query()->where('residencial_id', $guardia->residencial_id)->where('guardia_id', $guardia->id);
    }
}
