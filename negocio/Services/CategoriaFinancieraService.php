<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CategoriaFinancieraRepositorio;
use Patronato\Persistencia\Transaccion;

class CategoriaFinancieraService
{
    /**
     * Categorías de egreso con las que empieza cada residencial
     */
    public const EGRESOS_PREDETERMINADOS = [
        'Vigilancia',
        'Mantenimiento y reparaciones',
        'Servicios públicos',
        'Limpieza y jardinería',
        'Papelería y administración',
        'Otros gastos',
    ];

    /**
     * Categoría que reciben los cobros de cuotas; no se usa en un ingreso manual
     */
    public const CUOTAS = 'Cuotas de mantenimiento';

    /**
     * Categorías de ingreso con las que empieza cada residencial
     */
    public const INGRESOS_PREDETERMINADOS = [
        self::CUOTAS,
        'Alquiler de áreas comunes',
        'Multas',
        'Donaciones',
        'Otros ingresos',
    ];

    public function __construct(
        private CategoriaFinancieraRepositorio $categorias,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Categorías de egreso activas del residencial
     *
     * @return Collection<int, CategoriaFinanciera>
     */
    public function deEgresos(): Collection
    {
        return $this->categorias->activasDe(CategoriaFinanciera::EGRESO);
    }

    /**
     * Categorías de ingreso activas que se pueden elegir en un ingreso manual
     *
     * @return Collection<int, CategoriaFinanciera>
     */
    public function deIngresosManuales(): Collection
    {
        return $this->categorias->activasDe(CategoriaFinanciera::INGRESO)
            ->reject(fn (CategoriaFinanciera $categoria): bool => $categoria->nombre === self::CUOTAS)
            ->values();
    }

    /**
     * Categoría de las cuotas del residencial; la crea si alguien la quitó
     */
    public function deCuotas(int $residencialId): CategoriaFinanciera
    {
        return $this->categorias->obtenerOCrear($residencialId, CategoriaFinanciera::INGRESO, self::CUOTAS);
    }

    /**
     * Crea las categorías de egreso e ingreso iniciales de un residencial nuevo
     */
    public function crearPredeterminadas(int $residencialId): void
    {
        $iniciales = [
            CategoriaFinanciera::EGRESO => self::EGRESOS_PREDETERMINADOS,
            CategoriaFinanciera::INGRESO => self::INGRESOS_PREDETERMINADOS,
        ];

        foreach ($iniciales as $tipo => $nombres) {
            foreach ($nombres as $nombre) {
                $this->categorias->crear(['residencial_id' => $residencialId, 'tipo' => $tipo, 'nombre' => $nombre]);
            }
        }
    }

    /**
     * Motivo por el que no se puede usar el nombre, o null si está libre
     */
    public function motivoNombreNoDisponible(string $tipo, string $nombre): ?string
    {
        return $this->categorias->existeNombre($tipo, trim($nombre))
            ? 'Ya existe una categoría con ese nombre.'
            : null;
    }

    /**
     * Registra una categoría propia del residencial
     */
    public function registrar(User $usuario, string $tipo, string $nombre): CategoriaFinanciera
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $tipo, $nombre) {
            $categoria = $this->categorias->crear([
                'residencial_id' => $usuario->residencial_id,
                'tipo' => $tipo,
                'nombre' => trim($nombre),
            ]);

            $this->auditoria->registrar($usuario, 'tesoreria', 'categoria_creada', $categoria, [
                'valor_nuevo' => ['tipo' => $categoria->tipo, 'nombre' => $categoria->nombre],
            ]);

            return $categoria;
        });
    }

    /**
     * Categorías de egreso inactivas, para poder reactivarlas
     *
     * @return Collection<int, CategoriaFinanciera>
     */
    public function inactivasDeEgreso(): Collection
    {
        return $this->categorias->inactivasDe(CategoriaFinanciera::EGRESO);
    }

    /**
     * Quita una categoría de egreso: la borra si nunca se usó y la desactiva si ya tiene historial
     *
     * @return bool true si se borró, false si solo se desactivó
     *
     * @throws ValidationException si no es de egreso o ya está inactiva
     */
    public function quitarDeEgreso(User $usuario, CategoriaFinanciera $categoria): bool
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $categoria) {
            // El bloqueo espera a los egresos que se estén guardando con esta categoría
            $actual = $this->exigirDeEgreso($categoria, true);
            $datos = ['tipo' => $actual->tipo, 'nombre' => $actual->nombre];

            if ($this->categorias->estaEnUso($actual->id)) {
                $this->categorias->actualizar($actual, ['activa' => false]);

                $this->auditoria->registrar($usuario, 'tesoreria', 'categoria_desactivada', $actual, [
                    'valor_anterior' => ['activa' => true],
                    'valor_nuevo' => ['activa' => false],
                ]);

                return false;
            }

            $this->auditoria->registrar($usuario, 'tesoreria', 'categoria_eliminada', $actual, [
                'valor_anterior' => $datos,
            ]);
            $this->categorias->eliminar($actual);

            return true;
        });
    }

    /**
     * Vuelve a ofrecer una categoría de egreso desactivada
     *
     * @throws ValidationException si no es de egreso o ya está activa
     */
    public function reactivarDeEgreso(User $usuario, CategoriaFinanciera $categoria): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $categoria) {
            $actual = $this->exigirDeEgreso($categoria, false);

            $this->categorias->actualizar($actual, ['activa' => true]);

            $this->auditoria->registrar($usuario, 'tesoreria', 'categoria_reactivada', $actual, [
                'valor_anterior' => ['activa' => false],
                'valor_nuevo' => ['activa' => true],
            ]);
        });
    }

    /**
     * Relee la categoría bloqueada y comprueba que sea de egreso y esté en el estado esperado
     *
     * @throws ValidationException
     */
    private function exigirDeEgreso(CategoriaFinanciera $categoria, bool $activa): CategoriaFinanciera
    {
        $actual = $this->categorias->bloquearRegistro($categoria->id);

        if ($actual === null || $actual->tipo !== CategoriaFinanciera::EGRESO || $actual->activa !== $activa) {
            throw ValidationException::withMessages(['categoria' => $activa
                ? 'La categoría ya no está activa; actualiza la página.'
                : 'La categoría ya está activa; actualiza la página.']);
        }

        return $actual;
    }
}
