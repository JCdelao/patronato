<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\VehiculoConductor;
use Patronato\Persistencia\Repositorios\CarnetRepositorio;
use Patronato\Persistencia\Repositorios\VehiculoRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;

// Patrón Facade: el controlador del portal solo habla con esta clase, que junta viviendas, vehículos y carnets
/**
 * Vehículos, stickers y carnets que el residente consulta desde el portal (HU-53)
 *
 * Reglas:
 * - Es de solo lectura: no cambia ni audita nada.
 * - Solo se ven las viviendas donde el residente vive hoy, con la misma regla del estado de cuenta.
 * - Se muestran los vehículos activos y los stickers y carnets vigentes (activos, suspendidos o vencidos);
 *   los reemplazados y dados de baja no aparecen.
 * - Los carnets son los de todos los residentes activos que viven hoy en la vivienda.
 * - El comprobante de un vehículo sin placa se abre solo si el vehículo es de una vivienda propia.
 */
class PortalVehiculosService
{
    public function __construct(
        private PortalEstadoCuentaService $estados,
        private TransferenciaService $transferencias,
        private VehiculoService $vehiculoService,
        private VehiculoRepositorio $vehiculos,
        private CarnetRepositorio $carnets,
        private VinculoRepositorio $vinculos,
        private PeriodoService $periodos,
    ) {}

    /**
     * Viviendas donde vive hoy el residente, cada una con sus vehículos y los carnets de quienes viven en ella
     *
     * @return list<array<string, mixed>>
     */
    public function deUsuario(User $usuario): array
    {
        $viviendas = $this->estados->viviendasDe($usuario);
        $hoy = $this->periodos->hoy()->toDateString();

        $vehiculos = $this->vehiculos->activosDeViviendas(array_column($viviendas, 'id'))->groupBy('vivienda_id');

        return array_map(function (array $vivienda) use ($vehiculos, $hoy): array {
            $residentes = $this->vinculos->residentesVigentesDe($vivienda['id'])
                ->filter(fn (Residente $residente): bool => $residente->activo)
                ->values();

            $carnets = $this->carnets->vigentesDeResidentes(array_values($residentes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()))
                ->keyBy('residente_id');

            return [
                ...$vivienda,
                'vehiculos' => array_values(($vehiculos->get($vivienda['id']) ?? collect())
                    ->map(fn (Vehiculo $vehiculo): array => $this->vehiculo($vehiculo, $hoy))
                    ->all()),
                'residentes' => array_values($residentes->map(fn (Residente $residente): array => [
                    'id' => $residente->id,
                    'nombre' => trim("{$residente->nombres} {$residente->apellidos}"),
                    'carnet' => $this->carnet($carnets->get($residente->id)),
                ])->all()),
            ];
        }, $viviendas);
    }

    /**
     * Ruta del comprobante de un vehículo sin placa de una vivienda propia; null si no tiene archivo
     */
    public function rutaComprobante(User $usuario, Vehiculo $vehiculo): ?string
    {
        $residente = $this->transferencias->residenteDe($usuario);

        abort_unless(
            $vehiculo->activo && ! $vehiculo->tienePlaca() && $this->vinculos->viveEn($residente->id, $vehiculo->vivienda_id),
            403,
            'Solo puedes abrir los comprobantes de los vehículos de tu vivienda.',
        );

        return $this->vehiculoService->rutaComprobante($vehiculo);
    }

    /**
     * Datos del vehículo que ve el residente, sin motivos ni datos internos de la Junta Directiva
     *
     * @return array<string, mixed>
     */
    private function vehiculo(Vehiculo $vehiculo, string $hoy): array
    {
        /** @var Sticker|null $sticker */
        $sticker = $vehiculo->stickers->first();

        return [
            'id' => $vehiculo->id,
            'tipo' => $vehiculo->tipo,
            'placa' => $vehiculo->placa,
            // Solo identifica al vehículo mientras no tiene placa; después queda como historial interno
            'comprobante' => $vehiculo->tienePlaca() ? null : [
                'tipo' => Vehiculo::TIPOS_COMPROBANTE[$vehiculo->comprobante_tipo ?? ''] ?? null,
                'descripcion' => $vehiculo->comprobante_descripcion,
                'numero' => $vehiculo->comprobante_numero,
                'vence' => $vehiculo->comprobante_vence?->toDateString(),
                'tiene_archivo' => $vehiculo->comprobante_path !== null,
            ],
            'comprobante_vencido' => $vehiculo->comprobanteVencido($hoy),
            'marca' => $vehiculo->marca,
            'modelo' => $vehiculo->modelo,
            'color' => $vehiculo->color,
            'sticker' => $sticker === null ? null : [
                'numero' => $sticker->numero,
                'estado' => $sticker->estado,
                'fecha_emision' => $sticker->fecha_emision->toDateString(),
            ],
            'conductores' => array_values($vehiculo->conductores
                ->map(fn (VehiculoConductor $conductor): string => $conductor->residente !== null
                    ? trim("{$conductor->residente->nombres} {$conductor->residente->apellidos}")
                    : (string) $conductor->nombre)
                ->all()),
        ];
    }

    /**
     * @return array{numero: string, estado: string, fecha_emision: string, vigente_hasta: string|null}|null
     */
    private function carnet(?Carnet $carnet): ?array
    {
        return $carnet === null ? null : [
            'numero' => $carnet->numero,
            'estado' => $carnet->estado,
            'fecha_emision' => $carnet->fecha_emision->toDateString(),
            'vigente_hasta' => $carnet->vigente_hasta?->toDateString(),
        ];
    }
}
