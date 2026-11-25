<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\EscaneoQr;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\QrResidente;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\EscaneoQrRepositorio;
use Patronato\Persistencia\Repositorios\GuardiaRepositorio;
use Patronato\Persistencia\Repositorios\QrResidenteRepositorio;
use Patronato\Persistencia\Repositorios\TurnoRepositorio;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;
use Patronato\Persistencia\Transaccion;
use SensitiveParameter;

class EscaneoQrService
{
    public function __construct(
        private QrResidenteService $codigos,
        private QrResidenteRepositorio $qrResidentes,
        private EscaneoQrRepositorio $escaneos,
        private PreautorizacionService $portal,
        private VisitaService $visitas,
        private GuardiaRepositorio $guardias,
        private UsuarioRepositorio $usuarios,
        private TurnoRepositorio $turnos,
        private PermisoService $permisos,
        private Transaccion $transaccion,
        private ReglaAccesoPagoService $reglaAcceso,
    ) {}

    public function guardia(User $usuario): Guardia
    {
        $this->autorizar($usuario);

        return $this->visitas->guardia($usuario);
    }

    /** @return Collection<int, array{id: int, hora: string, resultado: string, decision: string|null}> */
    public function historial(Guardia $guardia): Collection
    {
        return $this->escaneos->recientes($guardia)->map($this->resumen(...));
    }

    /** @return array{id: int, hora: string, resultado: string, decision: string|null, nombre: string|null, viviendas: list<string>, foto: bool, indicacion?: string} */
    public function escanear(User $usuario, #[SensitiveParameter] string $token): array
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $token): array {
            $guardia = $this->contexto($usuario);
            $qr = $this->codigos->valido($guardia->residencial_id, trim($token));
            if ($qr !== null) {
                $qr = $this->bloquearQrVigente($guardia, $qr);
            }
            $residente = $this->residente($qr);
            $viviendas = $residente === null ? [] : $this->portal->viviendas($residente);
            $evaluacion = $residente === null ? null : $this->reglaAcceso->evaluar($guardia->residencial_id, array_column($viviendas, 'id'));
            $resultado = match (true) {
                $residente === null || $evaluacion === ReglaAccesoPagoService::DENEGADO => 'denegado',
                count($viviendas) !== 1 || $evaluacion === ReglaAccesoPagoService::REVISAR => 'revisar',
                default => 'permitido',
            };
            $escaneo = $this->escaneos->registrar($guardia, $resultado === 'denegado' ? null : $qr?->id, $resultado);
            $residente = $resultado === 'denegado' ? null : $residente;

            $respuesta = $this->resumen($escaneo) + [
                'nombre' => $residente === null ? null : trim($residente->nombres.' '.$residente->apellidos),
                'viviendas' => $residente === null ? [] : array_column($viviendas, 'codigo'),
                'foto' => $residente?->foto_path !== null,
            ];

            return match ($evaluacion) {
                ReglaAccesoPagoService::DENEGADO => $respuesta + ['indicacion' => 'Dirija al residente a la Junta Directiva.'],
                ReglaAccesoPagoService::REVISAR => $respuesta + ['indicacion' => 'Solicite revisión a la Junta Directiva.'],
                default => $respuesta,
            };
        });
    }

    public function decidir(User $usuario, int $id, string $decision): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $id, $decision): void {
            $guardia = $this->contexto($usuario);
            $escaneo = $this->escaneos->obtener($guardia, $id);
            if ($escaneo->decision_final === $decision) {
                return;
            }

            abort_if($escaneo->decision_final !== null, 409, 'La decisión ya fue registrada.');
            if ($decision === 'ingreso') {
                $qr = $this->qrDeEscaneo($guardia, $escaneo);
                $residente = $qr === null ? null : $this->residente($this->bloquearQrVigente($guardia, $qr));
                $viviendas = $residente === null ? [] : $this->portal->viviendas($residente);
                abort_if($viviendas === [], 409, 'El QR ya no autoriza el ingreso. Escanea el código vigente.');
                $evaluacion = $this->reglaAcceso->evaluar($guardia->residencial_id, array_column($viviendas, 'id'));
                abort_if($evaluacion !== null && $evaluacion !== ReglaAccesoPagoService::AL_DIA, 409, 'Dirija al residente a la Junta Directiva.');
            }

            $this->escaneos->decidir($escaneo, $decision);
        });
    }

    public function foto(Guardia $guardia, int $id): ?string
    {
        return $this->residenteDeEscaneo($guardia, $this->escaneos->obtener($guardia, $id))?->foto_path;
    }

    private function residenteDeEscaneo(Guardia $guardia, EscaneoQr $escaneo): ?Residente
    {
        $qr = $this->qrDeEscaneo($guardia, $escaneo);

        return $qr === null ? null : $this->residente($this->codigos->valido($guardia->residencial_id, $qr->token));
    }

    private function qrDeEscaneo(Guardia $guardia, EscaneoQr $escaneo): ?QrResidente
    {
        return $escaneo->qr_id === null ? null : $this->qrResidentes->obtener($guardia->residencial_id, $escaneo->qr_id);
    }

    private function bloquearQrVigente(Guardia $guardia, QrResidente $qr): ?QrResidente
    {
        // La regeneración actualiza esta misma fila. Revalida después de obtener el bloqueo.
        $actual = $this->qrResidentes->obtener($guardia->residencial_id, $qr->id, bloquear: true);

        return $actual === null ? null : $this->codigos->valido($guardia->residencial_id, $actual->token);
    }

    private function residente(?QrResidente $qr): ?Residente
    {
        return $qr === null ? null : $this->qrResidentes->residente($qr);
    }

    /** @return array{id: int, hora: string, resultado: string, decision: string|null} */
    private function resumen(EscaneoQr $escaneo): array
    {
        return ['id' => $escaneo->id, 'hora' => $escaneo->escaneado_en->toIso8601String(),
            'resultado' => $escaneo->resultado, 'decision' => $escaneo->decision_final];
    }

    private function autorizar(User $usuario): void
    {
        abort_unless($usuario->rol?->codigo === Rol::GUARDIA && $usuario->motivoSinAcceso() === null
            && $this->permisos->puede($usuario, 'visitas', 'registrar'), 403);
    }

    private function contexto(User $usuario): Guardia
    {
        abort_if($usuario->residencial_id === null, 403);
        $guardia = $this->guardias->bloquearActivoDeUsuario($usuario->id, $usuario->residencial_id);
        try {
            $cuenta = $this->usuarios->bloquearSinEspera($usuario->id);
        } catch (QueryException $error) {
            if ($error->getCode() !== '55P03') {
                throw $error;
            }

            abort(409, 'La cuenta se está actualizando. Vuelve a intentarlo.');
        }
        abort_if($guardia === null || $cuenta === null || $cuenta->residencial_id !== $guardia->residencial_id, 403);
        $this->autorizar($cuenta);
        $turno = $this->turnos->enCurso($guardia->id);
        if ($turno === null || $this->turnos->bloquearRegistro($turno->id) === null
            || $this->turnos->enCurso($guardia->id)?->id !== $turno->id) {
            throw ValidationException::withMessages(['turno' => 'Inicia tu turno antes de escanear o decidir.']);
        }

        return $guardia;
    }
}
