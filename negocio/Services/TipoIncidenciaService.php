<?php

namespace Patronato\Negocio\Services;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\TipoIncidencia;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\TipoIncidenciaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Catálogo de tipos de incidencia del residencial
 *
 * Un tipo no se borra: se desactiva para que no se use en incidencias nuevas y las
 * anteriores lo conservan.
 */
class TipoIncidenciaService
{
    public const PREDETERMINADOS = ['Robo', 'Daño a la propiedad', 'Ingreso no autorizado', 'Persona sospechosa', 'Ruido', 'Emergencia médica', 'Otro'];

    private const NOMBRE_USADO = 'Ya existe un tipo de incidencia con ese nombre.';

    public function __construct(
        private TipoIncidenciaRepositorio $tipos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Crea los tipos iniciales de un residencial nuevo
     */
    public function crearPredeterminados(int $residencialId): void
    {
        foreach (self::PREDETERMINADOS as $nombre) {
            $this->tipos->crear(['residencial_id' => $residencialId, 'nombre' => $nombre]);
        }
    }

    /**
     * Tipos del residencial; con $soloActivos, los que se pueden elegir al registrar
     *
     * @return list<array{id: int, nombre: string, activo: bool}>
     */
    public function listado(bool $soloActivos = false): array
    {
        $tipos = $soloActivos ? $this->tipos->activos() : $this->tipos->todos();

        return array_values($tipos
            ->map(fn (TipoIncidencia $tipo): array => ['id' => $tipo->id, 'nombre' => $tipo->nombre, 'activo' => $tipo->activo])
            ->all());
    }

    /**
     * @throws ValidationException si el nombre ya se usa
     */
    public function registrar(User $usuario, string $nombre): TipoIncidencia
    {
        $nombre = trim($nombre);

        return $this->guardandoNombre($nombre, null, function () use ($usuario, $nombre) {
            $tipo = $this->tipos->crear(['residencial_id' => $usuario->residencial_id, 'nombre' => $nombre]);

            $this->auditoria->registrar($usuario, 'vigilancia', 'tipo_incidencia_creado', $tipo, [
                'valor_nuevo' => ['nombre' => $nombre],
            ]);

            return $tipo;
        });
    }

    /**
     * @throws ValidationException si el nombre ya se usa
     */
    public function renombrar(User $usuario, TipoIncidencia $tipo, string $nombre): void
    {
        $nombre = trim($nombre);
        $anterior = $tipo->nombre;

        if ($nombre === $anterior) {
            return;
        }

        $this->guardandoNombre($nombre, $tipo->id, function () use ($usuario, $tipo, $nombre, $anterior) {
            $this->tipos->actualizar($tipo, ['nombre' => $nombre]);

            $this->auditoria->registrar($usuario, 'vigilancia', 'tipo_incidencia_actualizado', $tipo, [
                'valor_anterior' => ['nombre' => $anterior],
                'valor_nuevo' => ['nombre' => $nombre],
            ]);

            return $tipo;
        });
    }

    /**
     * Activa o desactiva el tipo; uno inactivo no se elige en incidencias nuevas
     */
    public function cambiarActivo(User $usuario, TipoIncidencia $tipo, bool $activo): void
    {
        if ($tipo->activo === $activo) {
            return;
        }

        $this->transaccion->ejecutar(function () use ($usuario, $tipo, $activo) {
            $this->tipos->actualizar($tipo, ['activo' => $activo]);

            $this->auditoria->registrar($usuario, 'vigilancia', $activo ? 'tipo_incidencia_activado' : 'tipo_incidencia_desactivado', $tipo, [
                'valor_anterior' => ['activo' => ! $activo],
                'valor_nuevo' => ['activo' => $activo],
            ]);
        });
    }

    /**
     * Guarda revisando que el nombre esté libre; el índice de la base cubre la carrera
     *
     * @param  Closure(): TipoIncidencia  $guardar
     *
     * @throws ValidationException
     */
    private function guardandoNombre(string $nombre, ?int $exceptoId, Closure $guardar): TipoIncidencia
    {
        if ($this->tipos->existeNombre($nombre, $exceptoId)) {
            throw ValidationException::withMessages(['nombre' => self::NOMBRE_USADO]);
        }

        try {
            return $this->transaccion->ejecutar($guardar);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => self::NOMBRE_USADO]);
        }
    }
}
