<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\SolicitudCorreccion;
use Patronato\Persistencia\Models\Vivienda;

class SolicitudCorreccionRepositorio extends Repositorio
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): SolicitudCorreccion
    {
        return SolicitudCorreccion::query()->create($datos);
    }

    /**
     * Vuelve a leer la solicitud reservándola hasta terminar la transacción
     */
    public function bloquearRegistro(int $solicitudId): ?SolicitudCorreccion
    {
        return SolicitudCorreccion::query()->lockForUpdate()->find($solicitudId);
    }

    /**
     * Solicitudes de un residente, de la más reciente a la más antigua
     *
     * @return Collection<int, SolicitudCorreccion>
     */
    public function deResidente(int $residenteId): Collection
    {
        return SolicitudCorreccion::query()
            ->where('residente_id', $residenteId)
            ->with('vivienda:id,codigo')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Bandeja del residencial: las abiertas de la más antigua a la más reciente, las cerradas al revés
     *
     * @return LengthAwarePaginator<int, SolicitudCorreccion>
     */
    public function bandeja(bool $abiertas, int $porPagina): LengthAwarePaginator
    {
        return SolicitudCorreccion::query()
            ->when(
                $abiertas,
                fn ($query) => $query->whereIn('estado', SolicitudCorreccion::ABIERTAS)->orderBy('id'),
                fn ($query) => $query->whereNotIn('estado', SolicitudCorreccion::ABIERTAS)->orderByDesc('updated_at')->orderByDesc('id'),
            )
            ->with(['residente:id,nombres,apellidos', 'vivienda:id,codigo', 'atendidaPor:id,name'])
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Solicitudes que nadie ha tomado todavía, para el número del menú
     */
    public function cantidadRecibidas(): int
    {
        return SolicitudCorreccion::query()->where('estado', SolicitudCorreccion::RECIBIDA)->count();
    }

    /**
     * Datos para avisar al residente: los correos de su cuenta y del padrón, el residencial y la vivienda
     *
     * @return array{correos: list<string>, residencial: string, vivienda: string}
     */
    public function datosDeAviso(SolicitudCorreccion $solicitud): array
    {
        $fila = Residente::query()
            ->withoutGlobalScopes()
            ->whereKey($solicitud->residente_id)
            ->leftJoin('users', 'users.id', '=', 'residentes.user_id')
            ->join('residenciales', 'residenciales.id', '=', 'residentes.residencial_id')
            ->first(['users.email as cuenta', 'residentes.correo as correo', 'residenciales.nombre as residencial']);

        $vivienda = $solicitud->vivienda_id === null
            ? ''
            : (string) Vivienda::query()->withoutGlobalScopes()->whereKey($solicitud->vivienda_id)->value('codigo');

        $correos = [];

        foreach ([$fila?->getAttribute('cuenta'), $fila?->getAttribute('correo')] as $correo) {
            if (is_string($correo) && trim($correo) !== '') {
                $correos[mb_strtolower(trim($correo))] ??= trim($correo);
            }
        }

        return [
            'correos' => array_values($correos),
            'residencial' => (string) $fila?->getAttribute('residencial'),
            'vivienda' => $vivienda,
        ];
    }
}
