<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Notificaciones\TransferenciaReportada;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Repositorios\ResidenteRepositorio;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Transaccion;
use RuntimeException;
use Throwable;

/**
 * Registro de transferencias bancarias
 *
 * Reglas:
 * - La transferencia queda pendiente de verificación: no tiene recibo, no se aplica
 *   a meses y no reduce el saldo hasta que se confirme (HU-26).
 * - El residencial no admite dos transferencias vigentes con la misma referencia del
 *   mismo banco; una rechazada o anulada libera la referencia.
 * - El comprobante se guarda en el disco privado y solo se ve con permiso.
 * - El vecino la reporta desde el portal solo para una vivienda donde vive (HU-25);
 *   queda como reportada por el vecino y tesorería recibe un aviso por correo.
 */
class TransferenciaService
{
    /**
     * Disco privado de los comprobantes; no tiene dirección pública
     */
    public const DISCO = 'local';

    public const TAMANO_MAXIMO_KB = 5120;

    /**
     * @var list<string>
     */
    public const EXTENSIONES = ['jpg', 'jpeg', 'png', 'pdf'];

    /**
     * Zona horaria de la fecha del pago
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    private const REFERENCIA_REPETIDA = 'Ya existe una transferencia con ese número de referencia en ese banco.';

    public function __construct(
        private PagoRepositorio $pagos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private ResidenteRepositorio $residentes,
        private VinculoRepositorio $vinculos,
        private UsuarioRepositorio $usuarios,
    ) {}

    /**
     * Fecha de hoy en Honduras; una transferencia no puede tener fecha posterior
     */
    public static function hoy(): string
    {
        return CarbonImmutable::now(self::ZONA_LOCAL)->toDateString();
    }

    /**
     * Quita los espacios sobrantes, para que "BAC " y "BAC" sean el mismo banco
     */
    public static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * Registra la transferencia pendiente de verificación con su comprobante
     *
     * @param  array{vivienda_id: int|string, monto: string, fecha_pago: string, banco: string, numero_referencia: string, cuenta_receptora: string}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(User $usuario, array $datos, UploadedFile $comprobante): Pago
    {
        return $this->guardar($usuario, $datos, $comprobante, Pago::PENDIENTE_VERIFICACION, Pago::SISTEMA, 'transferencias', 'transferencia_registrada');
    }

    /**
     * El vecino reporta desde el portal una transferencia de una vivienda donde vive
     *
     * El aviso a tesorería se envía después de guardar: si el correo falla, el error
     * queda registrado y el reporte se conserva.
     *
     * @param  array{vivienda_id: int|string, monto: string, fecha_pago: string, banco: string, numero_referencia: string, cuenta_receptora?: string|null}  $datos
     *
     * @throws ValidationException
     */
    public function reportar(User $usuario, array $datos, UploadedFile $comprobante): Pago
    {
        $residente = $this->residenteDe($usuario);

        if (! $this->vinculos->viveEn($residente->id, (int) $datos['vivienda_id'])) {
            throw ValidationException::withMessages(['vivienda_id' => 'Solo puedes reportar transferencias de una vivienda donde vives.']);
        }

        $pago = $this->guardar($usuario, $datos, $comprobante, Pago::REPORTADO_POR_VECINO, Pago::PORTAL, 'portal', 'transferencia_reportada');

        try {
            $this->avisarATesoreria($pago, $residente);
        } catch (Throwable $error) {
            report($error);
        }

        return $pago;
    }

    /**
     * Residente activo vinculado a la cuenta; sin él no se usa el portal
     */
    public function residenteDe(User $usuario): Residente
    {
        $residente = $this->residenteActivo($usuario);

        abort_if($residente === null, 403, 'Tu cuenta no está vinculada a un residente activo.');

        return $residente;
    }

    /**
     * Indica si la cuenta está vinculada a un residente activo; el menú muestra el portal solo entonces
     */
    public function esResidente(User $usuario): bool
    {
        return $this->residenteActivo($usuario) !== null;
    }

    /**
     * Viviendas activas donde vive el residente de la cuenta
     *
     * @return list<array{id: int, codigo: string}>
     */
    public function viviendasDe(User $usuario): array
    {
        return array_values($this->vinculos->vigentesDe($this->residenteDe($usuario)->id)
            ->load('vivienda')
            ->filter(fn (ViviendaResidente $vinculo): bool => $vinculo->vivienda->activa)
            ->map(fn (ViviendaResidente $vinculo): array => ['id' => $vinculo->vivienda_id, 'codigo' => $vinculo->vivienda->codigo])
            ->all());
    }

    /**
     * Transferencias que la cuenta reportó desde el portal, con su estado
     *
     * @return list<array{id: int, fecha_pago: string, registrado_en: string, vivienda: string, monto: string, banco: string|null, numero_referencia: string|null, cuenta_receptora: string|null, estado: string, tiene_comprobante: bool}>
     */
    public function misReportes(User $usuario): array
    {
        $this->residenteDe($usuario);

        return array_values($this->pagos->reportesDe($usuario->id)->map(fn (Pago $pago): array => [
            'id' => $pago->id,
            'fecha_pago' => $pago->fecha_pago->toDateString(),
            'registrado_en' => $pago->created_at->toIso8601String(),
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'banco' => $pago->banco,
            'numero_referencia' => $pago->numero_referencia,
            'cuenta_receptora' => $pago->cuenta_receptora,
            'estado' => $pago->estado,
            'tiene_comprobante' => $pago->comprobante_path !== null,
        ])->all());
    }

    /**
     * Indica si el pago es un reporte que la cuenta hizo desde el portal
     */
    public function esReporteDe(User $usuario, Pago $pago): bool
    {
        return $pago->origen === Pago::PORTAL && $pago->registrado_por === $usuario->id;
    }

    /**
     * Indica si el pago es una transferencia reportada desde el portal
     *
     * La vista de tesorería del aviso solo abre estas; las que registró el
     * tesorero (HU-24) se consultan en cobros.
     */
    public function esReportePortal(Pago $pago): bool
    {
        return $pago->forma_pago === Pago::TRANSFERENCIA && $pago->origen === Pago::PORTAL;
    }

    /**
     * Datos del reporte para quien registra transferencias, sin el saldo de la vivienda
     *
     * @return array{id: int, vivienda: string, monto: string, fecha_pago: string, banco: string|null, numero_referencia: string|null, cuenta_receptora: string|null, estado: string, reportado_por: string|null, reportado_en: string, tiene_comprobante: bool}
     */
    public function detalleReporte(Pago $pago): array
    {
        return [
            'id' => $pago->id,
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'fecha_pago' => $pago->fecha_pago->toDateString(),
            'banco' => $pago->banco,
            'numero_referencia' => $pago->numero_referencia,
            'cuenta_receptora' => $pago->cuenta_receptora,
            'estado' => $pago->estado,
            'reportado_por' => $pago->cobrador?->name,
            'reportado_en' => $pago->created_at->toIso8601String(),
            'tiene_comprobante' => $pago->comprobante_path !== null,
        ];
    }

    /**
     * Ruta del comprobante de la transferencia, o null si no tiene o el archivo no existe
     */
    public function comprobante(Pago $pago): ?string
    {
        $ruta = $pago->comprobante_path;

        return $pago->forma_pago === Pago::TRANSFERENCIA && $ruta !== null && Storage::disk(self::DISCO)->exists($ruta)
            ? $ruta
            : null;
    }

    /**
     * Guarda el comprobante y la transferencia; si algo falla no queda el archivo
     *
     * @param  array{vivienda_id: int|string, monto: string, fecha_pago: string, banco: string, numero_referencia: string, cuenta_receptora?: string|null}  $datos
     *
     * @throws ValidationException
     */
    private function guardar(User $usuario, array $datos, UploadedFile $comprobante, string $estado, string $origen, string $modulo, string $accion): Pago
    {
        $residencialId = (int) $usuario->residencial_id;
        $banco = self::normalizar($datos['banco']);
        $referencia = self::normalizar($datos['numero_referencia']);
        $cuenta = self::normalizar((string) ($datos['cuenta_receptora'] ?? ''));

        // Se revisa antes de guardar el archivo, para no guardarlo en vano
        $this->exigirReferenciaLibre($banco, $referencia);

        $ruta = $comprobante->store("transferencias/{$residencialId}", self::DISCO);

        if ($ruta === false) {
            throw new RuntimeException('No se pudo guardar el comprobante de la transferencia.');
        }

        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $datos, $banco, $referencia, $cuenta, $ruta, $estado, $origen, $modulo, $accion) {
                // Dos registros a la vez no pueden tomar la misma referencia
                $this->pagos->bloquear($residencialId);
                $this->exigirReferenciaLibre($banco, $referencia);

                $pago = $this->pagos->crear([
                    'residencial_id' => $residencialId,
                    'vivienda_id' => (int) $datos['vivienda_id'],
                    'forma_pago' => Pago::TRANSFERENCIA,
                    'estado' => $estado,
                    'origen' => $origen,
                    'monto' => AplicacionPagoService::monto(AplicacionPagoService::centavos($datos['monto'])),
                    'fecha_pago' => $datos['fecha_pago'],
                    'registrado_por' => $usuario->id,
                    'banco' => $banco,
                    'numero_referencia' => $referencia,
                    // Solo el portal la admite vacía; el registro del tesorero la exige
                    'cuenta_receptora' => $cuenta === '' ? null : $cuenta,
                    'comprobante_path' => $ruta,
                ]);

                $this->auditoria->registrar($usuario, $modulo, $accion, $pago, [
                    'valor_nuevo' => [
                        'vivienda_id' => $pago->vivienda_id,
                        'monto' => $pago->monto,
                        'fecha_pago' => $datos['fecha_pago'],
                        'banco' => $banco,
                        'numero_referencia' => $referencia,
                        'cuenta_receptora' => $pago->cuenta_receptora,
                        'estado' => $estado,
                    ],
                ]);

                return $pago;
            });
        } catch (Throwable $error) {
            // Sin registro no se conserva el archivo
            Storage::disk(self::DISCO)->delete($ruta);

            // El índice de la base es la última defensa si otro registro ganó la carrera
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['numero_referencia' => self::REFERENCIA_REPETIDA]);
            }

            throw $error;
        }
    }

    private function residenteActivo(User $usuario): ?Residente
    {
        return $this->residentes->activoDeUsuario($usuario->id);
    }

    /**
     * @throws ValidationException
     */
    private function exigirReferenciaLibre(string $banco, string $referencia): void
    {
        if ($this->pagos->existeReferencia($banco, $referencia)) {
            throw ValidationException::withMessages(['numero_referencia' => self::REFERENCIA_REPETIDA]);
        }
    }

    /**
     * Avisa a las cuentas activas del residencial que registran transferencias
     */
    private function avisarATesoreria(Pago $pago, Residente $residente): void
    {
        $destinatarios = $this->usuarios->activasConPermiso($pago->residencial_id, 'transferencias', 'registrar');

        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send($destinatarios, new TransferenciaReportada(
            $pago->id,
            $pago->vivienda->codigo,
            trim("{$residente->nombres} {$residente->apellidos}"),
            $pago->monto,
            $pago->fecha_pago->toDateString(),
            (string) $pago->banco,
            (string) $pago->numero_referencia,
        ));
    }
}
