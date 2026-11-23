<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Visita;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\GuardiaRepositorio;
use Patronato\Persistencia\Repositorios\MedioEntradaRepositorio;
use Patronato\Persistencia\Repositorios\TurnoRepositorio;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;
use Patronato\Persistencia\Repositorios\VisitaRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

class VisitaService
{
    public function __construct(
        private TurnoService $turnosDeGuardia,
        private GuardiaRepositorio $guardias,
        private UsuarioRepositorio $usuarios,
        private PermisoService $permisos,
        private TurnoRepositorio $turnos,
        private ViviendaRepositorio $viviendas,
        private MedioEntradaRepositorio $medios,
        private VisitaRepositorio $visitas,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    public function guardia(User $usuario): Guardia
    {
        $guardia = $this->turnosDeGuardia->guardiaDe($usuario);
        abort_if($guardia === null || $guardia->residencial_id !== $usuario->residencial_id, 403, 'Esta cuenta no está vinculada a un guardia activo.');

        return $guardia;
    }

    /** @return list<array{id: int, codigo: string}> */
    public function viviendas(Guardia $guardia): array
    {
        return array_values($this->viviendas->paraVisitas($guardia->residencial_id)->map(fn (Vivienda $vivienda) => [
            'id' => $vivienda->id, 'codigo' => $vivienda->codigo,
        ])->all());
    }

    /** @param array{nombre_visitante: string, identidad: string, motivo: string, vivienda_id: int|string, medio_entrada_id: int|string, placa?: string|null} $datos */
    public function registrar(User $usuario, array $datos): Visita
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $datos): Visita {
            $residencialId = $usuario->residencial_id;
            abort_if($residencialId === null, 403, 'Esta cuenta no está vinculada a un guardia activo.');

            // Guardia -> cuenta -> turno -> medio. La desactivación también empieza por el guardia.
            $guardia = $this->guardias->bloquearActivoDeUsuario($usuario->id, $residencialId);
            abort_if($guardia === null, 403, 'Esta cuenta no está vinculada a un guardia activo.');
            $cuenta = $this->bloquearCuenta($usuario->id);
            abort_if(
                $cuenta === null || $cuenta->residencial_id !== $residencialId
                    || $cuenta->motivoSinAcceso() !== null || ! $this->permisos->puede($cuenta, 'visitas', 'registrar'),
                403,
                'Esta cuenta no está autorizada para registrar entradas.',
            );
            $turno = $this->turnos->enCurso($guardia->id);

            // La entrega bloquea la misma fila. Tras adquirir el bloqueo, se vuelve a comprobar el turno.
            if ($turno === null || $this->turnos->bloquearRegistro($turno->id) === null
                || $this->turnos->enCurso($guardia->id)?->id !== $turno->id) {
                throw ValidationException::withMessages(['turno' => 'Inicia tu turno antes de registrar una entrada.']);
            }

            $vivienda = $this->viviendas->buscar((int) $datos['vivienda_id']);
            if ($vivienda === null || $vivienda->residencial_id !== $residencialId) {
                throw ValidationException::withMessages(['vivienda_id' => 'Elige una vivienda de tu residencial.']);
            }

            $medio = $this->medios->bloquearActivo($residencialId, (int) $datos['medio_entrada_id']);
            if ($medio === null) {
                throw ValidationException::withMessages(['medio_entrada_id' => 'Elige un medio de entrada activo de tu residencial.']);
            }

            if ($medio->exigePlaca() && blank($datos['placa'] ?? null)) {
                throw ValidationException::withMessages(['placa' => 'Escribe la placa para este medio de entrada.']);
            }

            $visita = $this->visitas->registrarEntrada([
                ...$datos,
                'residencial_id' => $residencialId,
                'identificador' => (string) Str::uuid(),
                'guardia_entrada_id' => $guardia->id,
                'turno_entrada_id' => $turno->id,
                'entrada_en' => CarbonImmutable::now()->utc(),
            ]);
            $this->auditoria->registrar($cuenta, 'vigilancia', 'visita_entrada_registrada', $visita, [
                'valor_nuevo' => ['vivienda_id' => $vivienda->id, 'medio_entrada_id' => $medio->id, 'turno_entrada_id' => $turno->id],
            ]);

            return $visita;
        });
    }

    private function bloquearCuenta(int $id): ?User
    {
        try {
            return $this->usuarios->bloquearSinEspera($id);
        } catch (QueryException $error) {
            if ($error->getCode() !== '55P03') {
                throw $error;
            }

            // La edición de cuentas puede tener la cuenta bloqueada antes de actualizar el vínculo.
            throw ValidationException::withMessages(['guardia' => 'La cuenta se está actualizando. Vuelve a intentar el registro.']);
        }
    }
}
