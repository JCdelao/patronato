<?php

namespace Patronato\Modules\Plataforma\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Patronato\Modules\Identidad\Services\AuditoriaService;
use Patronato\Modules\Identidad\Services\CuentaService;
use Patronato\Modules\Plataforma\Models\Residencial;
use Throwable;

class ResidencialService
{
    public function __construct(
        private CuentaService $cuentas,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Cambia el estado de un residencial
     */
    public function cambiarEstado(Residencial $residencial, string $estado, string $motivo, User $usuario): void
    {
        DB::transaction(function () use ($residencial, $estado, $motivo, $usuario) {
            $anterior = $residencial->estado;

            $residencial->update(['estado' => $estado]);

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
            $registro = DB::transaction(function () use ($datos, $logoPath) {
                $residencial = Residencial::query()->create([
                    'nombre' => $datos['nombre'],
                    'direccion' => $datos['direccion'] ?? null,
                    'telefono' => $datos['telefono'] ?? null,
                    'correo' => $datos['correo'] ?? null,
                    'logo_path' => $logoPath,
                ]);

                DB::table('configuracion_residencial')->insert([
                    'residencial_id' => $residencial->id,
                    'updated_at' => now(),
                ]);

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
