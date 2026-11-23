<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\MedioEntradaRepositorio;
use Patronato\Persistencia\Transaccion;

class MedioEntradaService
{
    public const PREDETERMINADOS = ['A pie' => false, 'Moto' => true, 'Carro' => true];

    private const MEDIO_EN_USO = 'Este medio de entrada tiene registros asociados. Puedes desactivarlo para conservar el historial.';

    public function __construct(
        private MedioEntradaRepositorio $medios,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    public function crearPredeterminados(int $residencialId): void
    {
        foreach (self::PREDETERMINADOS as $nombre => $requierePlaca) {
            $this->medios->crear(['residencial_id' => $residencialId, 'nombre' => $nombre, 'requiere_placa' => $requierePlaca]);
        }
    }

    /** @return list<array{id: int, nombre: string, requiere_placa: bool, activo: bool}> */
    public function listado(int $residencialId, bool $soloActivos = false): array
    {
        return array_values($this->medios->listado($residencialId, $soloActivos)->map(fn (MedioEntrada $medio) => [
            'id' => $medio->id,
            'nombre' => $medio->nombre,
            'requiere_placa' => $medio->exigePlaca(),
            'activo' => $medio->activo,
        ])->all());
    }

    public function guardar(User $usuario, string $nombre, bool $requierePlaca, bool $activo, ?MedioEntrada $medio = null): void
    {
        $residencialId = $usuario->residencial_id;
        abort_if($residencialId === null || ($medio !== null && $medio->residencial_id !== $residencialId), 403);
        $datos = [
            'nombre' => $nombre,
            'requiere_placa' => $requierePlaca || MedioEntrada::esMedioMotorizado($nombre),
            'activo' => $activo,
        ];

        try {
            $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $medio, $datos): void {
                if ($medio === null) {
                    $medio = $this->medios->crear(['residencial_id' => $residencialId, ...$datos]);
                    $accion = 'medio_entrada_creado';
                } else {
                    $this->medios->actualizar($medio, $datos);
                    $accion = 'medio_entrada_actualizado';
                }

                $this->auditoria->registrar($usuario, 'vigilancia', $accion, $medio, ['valor_nuevo' => $datos]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un medio de entrada con ese nombre.']);
        }
    }

    public function eliminar(User $usuario, MedioEntrada $medio): void
    {
        $residencialId = $usuario->residencial_id;
        abort_if($residencialId === null || $medio->residencial_id !== $residencialId, 403);

        $this->transaccion->ejecutar(function () use ($usuario, $medio, $residencialId): void {
            $medio = $this->medios->bloquear($residencialId, $medio->id);
            if (! $this->medios->eliminarSiNoTieneReferencias($medio)) {
                throw ValidationException::withMessages(['eliminar' => self::MEDIO_EN_USO]);
            }

            $this->auditoria->registrar($usuario, 'vigilancia', 'medio_entrada_eliminado', $medio, [
                'valor_anterior' => $medio->only(['nombre', 'requiere_placa', 'activo']),
            ]);
        });
    }
}
