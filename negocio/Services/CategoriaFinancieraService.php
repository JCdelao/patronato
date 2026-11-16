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
     * Crea las categorías de egreso iniciales de un residencial nuevo
     */
    public function crearPredeterminadas(int $residencialId): void
    {
        foreach (self::EGRESOS_PREDETERMINADOS as $nombre) {
            $this->categorias->crear([
                'residencial_id' => $residencialId,
                'tipo' => CategoriaFinanciera::EGRESO,
                'nombre' => $nombre,
            ]);
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
     * Registra una categoría de egreso propia del residencial
     */
    public function registrarDeEgreso(User $usuario, string $nombre): CategoriaFinanciera
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $nombre) {
            $categoria = $this->categorias->crear([
                'residencial_id' => $usuario->residencial_id,
                'tipo' => CategoriaFinanciera::EGRESO,
                'nombre' => trim($nombre),
            ]);

            $this->auditoria->registrar($usuario, 'tesoreria', 'categoria_creada', $categoria, [
                'valor_nuevo' => ['tipo' => $categoria->tipo, 'nombre' => $categoria->nombre],
            ]);

            return $categoria;
        });
    }
}
