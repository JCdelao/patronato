<?php

namespace Patronato\Negocio\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Notificaciones\SolicitudCorreccionAtendida;
use Patronato\Persistencia\Models\SolicitudCorreccion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\SolicitudCorreccionRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Transaccion;
use RuntimeException;
use Throwable;

// Patrón Facade: el portal y la bandeja solo hablan con esta clase, que junta el archivo, la bitácora y el aviso
/**
 * Solicitudes de corrección del residente y la bandeja donde la Junta Directiva las atiende (HU-54)
 *
 * Reglas:
 * - La solicitud lleva tipo, descripción, una vivienda donde el residente vive hoy y un adjunto opcional.
 * - Ninguna solicitud cambia datos: quien la atiende corrige en el módulo que corresponde,
 *   con sus permisos y su bitácora, y luego la cierra.
 * - Recibida → En revisión → Resuelta o Rechazada; desde Recibida también se cierra directamente.
 *   La respuesta es obligatoria al cerrar y una solicitud cerrada no se reabre.
 * - Al cerrarla se avisa por correo al residente; si el correo falla, el cierre se conserva.
 * - El adjunto lo abren el residente que lo subió y quienes ven la bandeja.
 */
class SolicitudCorreccionService
{
    public const DISCO = TransferenciaService::DISCO;

    public const TAMANO_MAXIMO_KB = TransferenciaService::TAMANO_MAXIMO_KB;

    /**
     * @var list<string>
     */
    public const EXTENSIONES = TransferenciaService::EXTENSIONES;

    public const DESCRIPCION_MINIMA = 10;

    public const RESPUESTA_MINIMA = 5;

    /**
     * Solicitudes por página en la bandeja
     */
    private const POR_PAGINA = 20;

    private const YA_CERRADA = 'Esta solicitud ya se cerró y no se puede cambiar.';

    private const YA_EN_REVISION = 'Esta solicitud ya no está recibida: alguien más la tomó o la cerró.';

    public function __construct(
        private SolicitudCorreccionRepositorio $solicitudes,
        private VinculoRepositorio $vinculos,
        private TransferenciaService $transferencias,
        private PortalEstadoCuentaService $estados,
        private PermisoService $permisos,
        private AuditoriaService $auditoria,
        private Transaccion $transaccion,
    ) {}

    /**
     * Viviendas donde vive hoy el residente, para elegir de cuál se trata
     *
     * @return list<array{id: int, codigo: string, activa: bool}>
     */
    public function viviendasDe(User $usuario): array
    {
        return $this->estados->viviendasDe($usuario);
    }

    /**
     * Solicitudes del residente de la cuenta, de la más reciente a la más antigua
     *
     * @return list<array<string, mixed>>
     */
    public function deUsuario(User $usuario): array
    {
        $residente = $this->transferencias->residenteDe($usuario);

        return array_values($this->solicitudes->deResidente($residente->id)
            ->map(fn (SolicitudCorreccion $solicitud): array => [
                'id' => $solicitud->id,
                'tipo' => $solicitud->tipo,
                'vivienda' => $solicitud->vivienda?->codigo,
                'descripcion' => $solicitud->descripcion,
                'estado' => $solicitud->estado,
                // La respuesta solo se muestra cuando la solicitud ya se cerró
                'respuesta' => $solicitud->abierta() ? null : $solicitud->respuesta,
                'creada_en' => $solicitud->created_at->toIso8601String(),
                'actualizada_en' => $solicitud->updated_at->toIso8601String(),
                'tiene_adjunto' => $solicitud->adjunto_path !== null,
            ])
            ->all());
    }

    /**
     * Registra la solicitud del residente; si algo falla no queda el archivo
     *
     * @param  array{tipo: string, vivienda_id: int|string, descripcion: string}  $datos
     *
     * @throws ValidationException
     */
    public function crear(User $usuario, array $datos, ?UploadedFile $adjunto): SolicitudCorreccion
    {
        $residente = $this->transferencias->residenteDe($usuario);
        $viviendaId = (int) $datos['vivienda_id'];

        if (! $this->vinculos->viveEn($residente->id, $viviendaId)) {
            throw ValidationException::withMessages(['vivienda_id' => 'Solo puedes pedir correcciones de una vivienda donde vives.']);
        }

        $ruta = null;

        if ($adjunto !== null) {
            $ruta = $adjunto->store("solicitudes-correccion/{$residente->residencial_id}", self::DISCO);

            if ($ruta === false) {
                throw new RuntimeException('No se pudo guardar el archivo adjunto.');
            }
        }

        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $residente, $viviendaId, $datos, $ruta) {
                $solicitud = $this->solicitudes->crear([
                    'residencial_id' => $residente->residencial_id,
                    'residente_id' => $residente->id,
                    'vivienda_id' => $viviendaId,
                    'tipo' => $datos['tipo'],
                    'descripcion' => trim($datos['descripcion']),
                    'adjunto_path' => $ruta,
                    'estado' => SolicitudCorreccion::RECIBIDA,
                ]);

                $this->auditoria->registrar($usuario, 'portal', 'solicitud_correccion_creada', $solicitud, [
                    'valor_nuevo' => [
                        'tipo' => $solicitud->tipo,
                        'vivienda_id' => $viviendaId,
                        'estado' => SolicitudCorreccion::RECIBIDA,
                        'con_adjunto' => $ruta !== null,
                    ],
                ]);

                return $solicitud;
            });
        } catch (Throwable $error) {
            if (is_string($ruta)) {
                Storage::disk(self::DISCO)->delete($ruta);
            }

            throw $error;
        }
    }

    /**
     * Adjunto de una solicitud propia; null si no tiene o el archivo ya no está
     */
    public function adjuntoDeResidente(User $usuario, SolicitudCorreccion $solicitud): ?string
    {
        $residente = $this->transferencias->residenteDe($usuario);

        abort_unless($solicitud->residente_id === $residente->id, 403, 'Solo puedes abrir los archivos de tus solicitudes.');

        return $this->adjunto($solicitud);
    }

    /**
     * Ruta del adjunto en el disco privado; null si no tiene o el archivo ya no está
     */
    public function adjunto(SolicitudCorreccion $solicitud): ?string
    {
        $ruta = $solicitud->adjunto_path;

        return $ruta !== null && Storage::disk(self::DISCO)->exists($ruta) ? $ruta : null;
    }

    /**
     * Solicitudes abiertas o cerradas de la bandeja, con lo que el usuario puede hacer en cada una
     *
     * @return LengthAwarePaginator<int, array{id: int, tipo: string, residente: string, vivienda: string|null, descripcion: string, estado: string, respuesta: string|null, atendida_por: string|null, creada_en: string, actualizada_en: string, tiene_adjunto: bool, puede_tomar: bool, puede_cerrar: bool}>
     */
    public function bandeja(User $usuario, bool $abiertas): LengthAwarePaginator
    {
        $puedeAtender = $this->permisos->puede($usuario, 'solicitudes', 'registrar');

        return $this->solicitudes->bandeja($abiertas, self::POR_PAGINA)->through(fn (SolicitudCorreccion $solicitud): array => [
            'id' => $solicitud->id,
            'tipo' => $solicitud->tipo,
            'residente' => trim("{$solicitud->residente->nombres} {$solicitud->residente->apellidos}"),
            'vivienda' => $solicitud->vivienda?->codigo,
            'descripcion' => $solicitud->descripcion,
            'estado' => $solicitud->estado,
            'respuesta' => $solicitud->respuesta,
            'atendida_por' => $solicitud->atendidaPor?->name,
            'creada_en' => $solicitud->created_at->toIso8601String(),
            'actualizada_en' => $solicitud->updated_at->toIso8601String(),
            'tiene_adjunto' => $solicitud->adjunto_path !== null,
            'puede_tomar' => $puedeAtender && $solicitud->estado === SolicitudCorreccion::RECIBIDA,
            'puede_cerrar' => $puedeAtender && $solicitud->abierta(),
        ]);
    }

    /**
     * Solicitudes recibidas que nadie ha tomado, para el número del menú
     */
    public function cantidadRecibidas(User $usuario): int
    {
        return $this->permisos->puede($usuario, 'solicitudes', 'ver') ? $this->solicitudes->cantidadRecibidas() : 0;
    }

    /**
     * Pasa la solicitud a revisión, para que los demás sepan que alguien la atiende
     *
     * @throws ValidationException
     */
    public function tomar(User $usuario, SolicitudCorreccion $solicitud): SolicitudCorreccion
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $solicitud) {
            $solicitud = $this->solicitudes->bloquearRegistro($solicitud->id) ?? $solicitud;

            if ($solicitud->estado !== SolicitudCorreccion::RECIBIDA) {
                throw ValidationException::withMessages(['solicitud' => self::YA_EN_REVISION]);
            }

            $this->solicitudes->actualizar($solicitud, [
                'estado' => SolicitudCorreccion::EN_REVISION,
                'atendida_por' => $usuario->id,
            ]);

            $this->auditoria->registrar($usuario, 'solicitudes', 'solicitud_correccion_en_revision', $solicitud, [
                'valor_anterior' => ['estado' => SolicitudCorreccion::RECIBIDA],
                'valor_nuevo' => ['estado' => SolicitudCorreccion::EN_REVISION],
            ]);

            return $solicitud;
        });
    }

    /**
     * Cierra la solicitud como resuelta o rechazada, con la respuesta para el residente
     *
     * @throws ValidationException
     */
    public function cerrar(User $usuario, SolicitudCorreccion $solicitud, bool $resuelta, string $respuesta): SolicitudCorreccion
    {
        $respuesta = trim($respuesta);
        $estado = $resuelta ? SolicitudCorreccion::RESUELTA : SolicitudCorreccion::RECHAZADA;

        $solicitud = $this->transaccion->ejecutar(function () use ($usuario, $solicitud, $estado, $respuesta) {
            $solicitud = $this->solicitudes->bloquearRegistro($solicitud->id) ?? $solicitud;

            if (! $solicitud->abierta()) {
                throw ValidationException::withMessages(['solicitud' => self::YA_CERRADA]);
            }

            $estadoAnterior = $solicitud->estado;

            $this->solicitudes->actualizar($solicitud, [
                'estado' => $estado,
                'respuesta' => $respuesta,
                'atendida_por' => $usuario->id,
            ]);

            $this->auditoria->registrar($usuario, 'solicitudes', $estado === SolicitudCorreccion::RESUELTA ? 'solicitud_correccion_resuelta' : 'solicitud_correccion_rechazada', $solicitud, [
                'valor_anterior' => ['estado' => $estadoAnterior],
                'valor_nuevo' => ['estado' => $estado, 'respuesta' => $respuesta],
            ]);

            return $solicitud;
        });

        $this->avisar($solicitud, $resuelta, $respuesta);

        return $solicitud;
    }

    /**
     * Envía el aviso al residente sin deshacer el cierre si el correo falla
     */
    private function avisar(SolicitudCorreccion $solicitud, bool $resuelta, string $respuesta): void
    {
        try {
            $datos = $this->solicitudes->datosDeAviso($solicitud);

            if ($datos['correos'] !== []) {
                Notification::route('mail', $datos['correos'])->notify(new SolicitudCorreccionAtendida(
                    $datos['residencial'],
                    $datos['vivienda'],
                    SolicitudCorreccion::TIPOS[$solicitud->tipo] ?? $solicitud->tipo,
                    $resuelta,
                    $respuesta,
                ));
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
