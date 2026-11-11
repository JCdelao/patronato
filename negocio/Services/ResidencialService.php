<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;
use Patronato\Persistencia\Transaccion;
use Throwable;

class ResidencialService
{
    public function __construct(
        private ResidencialRepositorio $residenciales,
        private ConfiguracionService $configuracion,
        private Transaccion $transaccion,
        private CuentaService $cuentas,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Residenciales de la plataforma, por nombre
     *
     * @return Collection<int, Residencial>
     */
    public function todos(): Collection
    {
        return $this->residenciales->todos();
    }

    /**
     * Indica si otro residencial ya usa el nombre
     */
    public function nombreUsado(string $nombre): bool
    {
        return $this->residenciales->existeNombre($nombre);
    }

    /**
     * Cambia el estado de un residencial
     */
    public function cambiarEstado(Residencial $residencial, string $estado, string $motivo, User $usuario): void
    {
        $this->transaccion->ejecutar(function () use ($residencial, $estado, $motivo, $usuario) {
            $anterior = $residencial->estado;

            $this->residenciales->actualizar($residencial, ['estado' => $estado]);

            $this->auditoria->registrar($usuario, 'plataforma', "residencial_{$estado}", $residencial, [
                'residencial_id' => $residencial->id,
                'valor_anterior' => ['estado' => $anterior],
                'valor_nuevo' => ['estado' => $estado],
                'motivo' => $motivo,
            ]);
        });
    }

    /**
     * Registra un residencial junto con su configuración y su administrador técnico
     *
     * @param  array{nombre: string, direccion?: string|null, telefono?: string|null, correo?: string|null, admin_nombre: string, admin_correo: string}  $datos
     * @return array{residencial: Residencial, administrador: User, password_temporal: string, correo_enviado: bool}
     */
    public function registrar(array $datos, ?UploadedFile $logo): array
    {
        $logoPath = $logo?->store('logos', 'public') ?: null;

        try {
            $registro = $this->transaccion->ejecutar(function () use ($datos, $logoPath) {
                $residencial = $this->residenciales->crear([
                    'nombre' => $datos['nombre'],
                    'direccion' => $datos['direccion'] ?? null,
                    'telefono' => $datos['telefono'] ?? null,
                    'correo' => $datos['correo'] ?? null,
                    'logo_path' => $logoPath,
                ]);

                $this->configuracion->crear($residencial->id);

                $cuenta = $this->cuentas->crearAdministradorTecnico(
                    $residencial->id,
                    $datos['admin_nombre'],
                    $datos['admin_correo'],
                );

                return [
                    'residencial' => $residencial,
                    'administrador' => $cuenta['usuario'],
                    'password_temporal' => $cuenta['password_temporal'],
                ];
            });
        } catch (Throwable $e) {
            if ($logoPath !== null) {
                Storage::disk('public')->delete($logoPath);
            }

            throw $e;
        }

        return [
            ...$registro,
            'correo_enviado' => $this->cuentas->enviarPasswordTemporal($registro['administrador'], $registro['password_temporal']),
        ];
    }
}
