<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Notificaciones\IncidenciaUrgente;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Incidencia;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\IncidenciaRepositorio;
use Patronato\Persistencia\Repositorios\TurnoRepositorio;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;
use Patronato\Persistencia\Transaccion;
use RuntimeException;
use Throwable;

/**
 * Incidencias que registran los guardias
 *
 * Reglas:
 * - La hora en que ocurrió no puede ser futura ni de hace más de una semana.
 * - La foto es opcional y se guarda en el disco privado; solo se ve con permiso.
 * - Si el guardia tiene un turno en curso, la incidencia queda ligada a ese turno.
 * - La urgente se avisa por correo a quienes administran la vigilancia; si el
 *   correo falla, la incidencia queda registrada igual.
 *
 * @phpstan-type IncidenciaDatos array{id: int, tipo: string, guardia: string, descripcion: string, ocurrida_en: string, urgente: bool, tiene_foto: bool}
 */
class IncidenciaService
{
    /**
     * Disco privado de las fotos; no tiene dirección pública
     */
    public const DISCO = 'local';

    private const POR_PAGINA = 20;

    public const TAMANO_MAXIMO_KB = 5120;

    public const EXTENSIONES = ['jpg', 'jpeg', 'png'];

    /**
     * Días hacia atrás en que se puede registrar una incidencia
     */
    public const DIAS_ATRAS = 7;

    // Minutos de tolerancia por la diferencia entre el reloj del teléfono y el del servidor
    private const TOLERANCIA_MINUTOS = 5;

    public function __construct(
        private IncidenciaRepositorio $incidencias,
        private TurnoRepositorio $turnos,
        private UsuarioRepositorio $usuarios,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Incidencias del residencial, de la más reciente a la más antigua
     *
     * @return LengthAwarePaginator<int, IncidenciaDatos>
     */
    public function listado(?int $tipoId, bool $soloUrgentes): LengthAwarePaginator
    {
        return $this->incidencias->paginar($tipoId, $soloUrgentes, self::POR_PAGINA)
            ->through(fn (Incidencia $incidencia): array => $this->datos($incidencia));
    }

    /**
     * Incidencias que el guardia registró en la última semana
     *
     * @return list<IncidenciaDatos>
     */
    public function deGuardia(Guardia $guardia): array
    {
        $desde = CarbonImmutable::now()->subDays(self::DIAS_ATRAS)->utc();

        return array_values($this->incidencias->deGuardia($guardia->id, $desde)
            ->map(fn (Incidencia $incidencia): array => $this->datos($incidencia))
            ->all());
    }

    /**
     * Registra la incidencia y, si es urgente, avisa por correo
     *
     * @param  array{tipo_incidencia_id: int|string, descripcion: string, ocurrida_en: string, urgente?: bool|int|string|null}  $datos
     * @return array{incidencia: Incidencia, aviso_enviado: bool|null} aviso_enviado es null si no era urgente
     *
     * @throws ValidationException si la fecha es futura o muy antigua
     */
    public function registrar(User $usuario, Guardia $guardia, array $datos, ?UploadedFile $foto): array
    {
        $ocurrida = $this->ocurrida($datos['ocurrida_en']);
        $urgente = filter_var($datos['urgente'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $ruta = null;

        if ($foto !== null) {
            $ruta = $foto->store("incidencias/{$guardia->residencial_id}", self::DISCO);

            if ($ruta === false) {
                throw new RuntimeException('No se pudo guardar la foto de la incidencia.');
            }
        }

        try {
            $incidencia = $this->transaccion->ejecutar(function () use ($usuario, $guardia, $datos, $ocurrida, $urgente, $ruta) {
                $incidencia = $this->incidencias->crear([
                    'residencial_id' => $guardia->residencial_id,
                    'tipo_incidencia_id' => (int) $datos['tipo_incidencia_id'],
                    'guardia_id' => $guardia->id,
                    'turno_id' => $this->turnos->enCurso($guardia->id)?->id,
                    'descripcion' => trim($datos['descripcion']),
                    'ocurrida_en' => $ocurrida->utc(),
                    'foto_path' => $ruta,
                    'urgente' => $urgente,
                ]);

                $this->auditoria->registrar($usuario, 'vigilancia', 'incidencia_registrada', $incidencia, [
                    'valor_nuevo' => [
                        'tipo_incidencia_id' => $incidencia->tipo_incidencia_id,
                        'ocurrida_en' => $ocurrida->format('Y-m-d H:i'),
                        'urgente' => $urgente,
                        'con_foto' => $ruta !== null,
                    ],
                ]);

                return $incidencia;
            });
        } catch (Throwable $error) {
            // Sin registro no se conserva la foto
            if ($ruta !== null) {
                Storage::disk(self::DISCO)->delete($ruta);
            }

            throw $error;
        }

        return ['incidencia' => $incidencia, 'aviso_enviado' => $urgente ? $this->avisar($incidencia, $guardia, $ocurrida) : null];
    }

    /**
     * Ruta de la foto en el disco privado, si la incidencia tiene una y existe
     */
    public function foto(Incidencia $incidencia): ?string
    {
        $ruta = $incidencia->foto_path;

        return $ruta !== null && Storage::disk(self::DISCO)->exists($ruta) ? $ruta : null;
    }

    /**
     * @throws ValidationException
     */
    private function ocurrida(string $valor): CarbonImmutable
    {
        $ocurrida = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $valor, TurnoService::ZONA_LOCAL);
        $ahora = CarbonImmutable::now(TurnoService::ZONA_LOCAL);

        if ($ocurrida === null) {
            throw ValidationException::withMessages(['ocurrida_en' => 'Escribe la fecha y la hora en que ocurrió.']);
        }

        $error = match (true) {
            $ocurrida->greaterThan($ahora->addMinutes(self::TOLERANCIA_MINUTOS)) => 'La incidencia no puede haber ocurrido en el futuro.',
            $ocurrida->lessThan($ahora->subDays(self::DIAS_ATRAS)) => 'Solo se registran incidencias de la última semana.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['ocurrida_en' => $error]);
        }

        return $ocurrida;
    }

    /**
     * Avisa por correo a las cuentas activas que ven las incidencias
     *
     * @return bool si se envió a alguien
     */
    private function avisar(Incidencia $incidencia, Guardia $guardia, CarbonImmutable $ocurrida): bool
    {
        $destinatarios = $this->usuarios->activasConPermiso($incidencia->residencial_id, 'incidencias', 'ver');

        if ($destinatarios->isEmpty()) {
            return false;
        }

        try {
            Notification::send($destinatarios, new IncidenciaUrgente(
                $incidencia->tipo->nombre,
                $guardia->nombre,
                $ocurrida->format('d/m/Y \a \l\a\s H:i'),
                $incidencia->descripcion,
                $incidencia->foto_path !== null,
            ));
        } catch (Throwable $error) {
            report($error);

            return false;
        }

        return true;
    }

    /**
     * @return IncidenciaDatos
     */
    private function datos(Incidencia $incidencia): array
    {
        return [
            'id' => $incidencia->id,
            'tipo' => $incidencia->tipo->nombre,
            'guardia' => $incidencia->guardia->nombre,
            'descripcion' => $incidencia->descripcion,
            'ocurrida_en' => $this->local($incidencia->ocurrida_en)->format('Y-m-d H:i'),
            'urgente' => $incidencia->urgente,
            'tiene_foto' => $incidencia->foto_path !== null,
        ];
    }

    private function local(DateTimeInterface $fecha): CarbonImmutable
    {
        return CarbonImmutable::instance($fecha)->setTimezone(TurnoService::ZONA_LOCAL);
    }
}
