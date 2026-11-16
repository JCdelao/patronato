<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\Eloquent\Collection;
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
}
