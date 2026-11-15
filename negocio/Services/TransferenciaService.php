<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
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
        $residencialId = (int) $usuario->residencial_id;
        $banco = self::normalizar($datos['banco']);
        $referencia = self::normalizar($datos['numero_referencia']);

        // Se revisa antes de guardar el archivo, para no guardarlo en vano
        $this->exigirReferenciaLibre($banco, $referencia);

        $ruta = $comprobante->store("transferencias/{$residencialId}", self::DISCO);

        if ($ruta === false) {
            throw new RuntimeException('No se pudo guardar el comprobante de la transferencia.');
        }

        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $datos, $banco, $referencia, $ruta) {
                // Dos registros a la vez no pueden tomar la misma referencia
                $this->pagos->bloquear($residencialId);
                $this->exigirReferenciaLibre($banco, $referencia);

                $pago = $this->pagos->crear([
                    'residencial_id' => $residencialId,
                    'vivienda_id' => (int) $datos['vivienda_id'],
                    'forma_pago' => Pago::TRANSFERENCIA,
                    'estado' => Pago::PENDIENTE_VERIFICACION,
                    'origen' => 'sistema',
                    'monto' => AplicacionPagoService::monto(AplicacionPagoService::centavos($datos['monto'])),
                    'fecha_pago' => $datos['fecha_pago'],
                    'registrado_por' => $usuario->id,
                    'banco' => $banco,
                    'numero_referencia' => $referencia,
                    'cuenta_receptora' => self::normalizar($datos['cuenta_receptora']),
                    'comprobante_path' => $ruta,
                ]);

                $this->auditoria->registrar($usuario, 'transferencias', 'transferencia_registrada', $pago, [
                    'valor_nuevo' => [
                        'vivienda_id' => $pago->vivienda_id,
                        'monto' => $pago->monto,
                        'fecha_pago' => $datos['fecha_pago'],
                        'banco' => $banco,
                        'numero_referencia' => $referencia,
                        'cuenta_receptora' => $pago->cuenta_receptora,
                        'estado' => Pago::PENDIENTE_VERIFICACION,
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
     * @throws ValidationException
     */
    private function exigirReferenciaLibre(string $banco, string $referencia): void
    {
        if ($this->pagos->existeReferencia($banco, $referencia)) {
            throw ValidationException::withMessages(['numero_referencia' => self::REFERENCIA_REPETIDA]);
        }
    }
}
