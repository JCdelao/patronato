<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\QrResidente;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Residente;
use RuntimeException;
use SensitiveParameter;

class QrResidenteRepositorio extends Repositorio
{
    public function tieneCarnetVigente(Residente $residente, string $hoy): bool
    {
        return $this->carnetsVigentes($hoy)
            ->where('residencial_id', $residente->residencial_id)
            ->where('residente_id', $residente->id)
            ->exists();
    }

    public function activoDe(Residente $residente): ?QrResidente
    {
        return $this->activosDelResidencial($residente->residencial_id)
            ->where('residente_id', $residente->id)
            ->first();
    }

    public function invalidarDe(Residente $residente): void
    {
        $this->activosDelResidencial($residente->residencial_id)
            ->where('residente_id', $residente->id)
            ->update(['activo' => false, 'invalidado_en' => now()]);
    }

    public function emitir(Residente $residente, #[SensitiveParameter] string $token): QrResidente
    {
        try {
            return QrResidente::query()->create([
                'residencial_id' => $residente->residencial_id,
                'residente_id' => $residente->id,
                'token' => $token,
            ]);
        } catch (QueryException) {
            // La excepción original puede contener el identificador en los parámetros SQL.
            throw new RuntimeException('No se pudo guardar el código QR.');
        }
    }

    public function esValido(int $residencialId, #[SensitiveParameter] string $token, string $hoy): bool
    {
        $carnets = $this->carnetsVigentes($hoy)
            ->select('carnets.id')
            ->whereColumn('carnets.residencial_id', 'qr_residentes.residencial_id')
            ->whereColumn('carnets.residente_id', 'qr_residentes.residente_id');

        try {
            return $this->activosDelResidencial($residencialId)
                ->join('residentes', 'residentes.id', '=', 'qr_residentes.residente_id')
                ->join('users', 'users.id', '=', 'residentes.user_id')
                ->join('residenciales', 'residenciales.id', '=', 'qr_residentes.residencial_id')
                ->whereColumn('residentes.residencial_id', 'qr_residentes.residencial_id')
                ->whereColumn('users.residencial_id', 'qr_residentes.residencial_id')
                ->where('residentes.activo', true)
                ->where('users.activo', true)
                ->where('residenciales.estado', '!=', Residencial::SUSPENDIDO)
                ->where('qr_residentes.token', $token)
                ->whereExists($carnets)
                ->exists();
        } catch (QueryException) {
            throw new RuntimeException('No se pudo validar el código QR.');
        }
    }

    /** @return Builder<QrResidente> */
    private function activosDelResidencial(int $residencialId): Builder
    {
        return QrResidente::query()
            ->where('qr_residentes.residencial_id', $residencialId)
            ->where('qr_residentes.activo', true);
    }

    /** @return Builder<Carnet> */
    private function carnetsVigentes(string $hoy): Builder
    {
        return Carnet::query()
            ->where('carnets.estado', Carnet::ACTIVO)
            ->where(fn (Builder $query) => $query
                ->whereNull('carnets.vigente_hasta')
                ->orWhere('carnets.vigente_hasta', '>=', $hoy));
    }
}
