<?php

namespace Patronato\Negocio\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Egreso;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\EgresoRepositorio;
use Patronato\Persistencia\Transaccion;
use Throwable;

class EgresoService
{
    public const MOTIVO_MINIMO = 10;

    private const POR_PAGINA = 25;

    /**
     * Las facturas van a un disco privado: solo se descargan desde el sistema
     */
    private const DISCO = 'local';

    public function __construct(
        private EgresoRepositorio $egresos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private PermisoService $permisos,
    ) {}

    /**
     * Egresos del residencial para el listado
     *
     * @return LengthAwarePaginator<int, array{id: int, fecha: string, concepto: string, categoria: string, proveedor: string|null, numero_comprobante: string, monto: string, estado: string}>
     */
    public function listado(string $buscar): LengthAwarePaginator
    {
        return $this->egresos->paginar($buscar, self::POR_PAGINA)->through(fn (Egreso $egreso): array => [
            'id' => $egreso->id,
            'fecha' => $egreso->fecha->toDateString(),
            'concepto' => $egreso->concepto,
            'categoria' => $egreso->categoria->nombre,
            'proveedor' => $egreso->proveedor,
            'numero_comprobante' => $egreso->numero_comprobante,
            'monto' => $egreso->monto,
            'estado' => $egreso->estado,
        ]);
    }

    /**
     * Detalle del egreso con su anulación
     *
     * @return array<string, mixed>
     */
    public function detalle(User $usuario, Egreso $egreso): array
    {
        $egreso = $this->egresos->conDetalle($egreso);
        $anulacion = $egreso->estado === Egreso::ANULADO
            ? ($this->auditoria->ultimosPorEntidad('egresos', [$egreso->id], 'egreso_anulado')[$egreso->id] ?? null)
            : null;

        return [
            'id' => $egreso->id,
            'fecha' => $egreso->fecha->toDateString(),
            'concepto' => $egreso->concepto,
            'categoria' => $egreso->categoria->nombre,
            'proveedor' => $egreso->proveedor,
            'monto' => $egreso->monto,
            'forma_pago' => $egreso->forma_pago,
            'numero_comprobante' => $egreso->numero_comprobante,
            'tiene_factura' => $egreso->factura_path !== null,
            'estado' => $egreso->estado,
            'registrado_por' => $egreso->registrador?->name,
            'registrado_en' => $egreso->created_at->toIso8601String(),
            'anulacion' => $egreso->estado === Egreso::ANULADO ? [
                'motivo' => $egreso->motivo_anulacion,
                'anulado_por' => $anulacion?->usuario?->name,
                'anulado_en' => $anulacion?->created_at->toIso8601String(),
            ] : null,
            'puede_anular' => $egreso->estado === Egreso::VIGENTE && $this->permisos->puede($usuario, 'tesoreria', 'anular'),
        ];
    }

    /**
     * Registra un egreso con su factura, si la trae
     *
     * @param  array{fecha: string, concepto: string, categoria_id: int|string, proveedor?: string|null, monto: float|int|string, forma_pago?: string|null, numero_comprobante: string}  $datos
     */
    public function registrar(User $usuario, array $datos, ?UploadedFile $factura): Egreso
    {
        $ruta = $factura?->store("facturas/{$usuario->residencial_id}", self::DISCO) ?: null;

        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $datos, $ruta) {
                $egreso = $this->egresos->crear([
                    'residencial_id' => $usuario->residencial_id,
                    'categoria_id' => (int) $datos['categoria_id'],
                    'fecha' => $datos['fecha'],
                    'concepto' => trim($datos['concepto']),
                    'proveedor' => $this->textoOpcional($datos['proveedor'] ?? null),
                    'monto' => number_format((float) $datos['monto'], 2, '.', ''),
                    'forma_pago' => $this->textoOpcional($datos['forma_pago'] ?? null),
                    'numero_comprobante' => trim($datos['numero_comprobante']),
                    'factura_path' => $ruta,
                    'estado' => Egreso::VIGENTE,
                    'registrado_por' => $usuario->id,
                ]);

                $this->auditoria->registrar($usuario, 'tesoreria', 'egreso_registrado', $egreso, [
                    'valor_nuevo' => [
                        'fecha' => $datos['fecha'],
                        'concepto' => $egreso->concepto,
                        'categoria_id' => $egreso->categoria_id,
                        'proveedor' => $egreso->proveedor,
                        'monto' => $egreso->monto,
                        'forma_pago' => $egreso->forma_pago,
                        'numero_comprobante' => $egreso->numero_comprobante,
                        'factura' => $ruta !== null,
                    ],
                ]);

                return $egreso;
            });
        } catch (Throwable $e) {
            // Sin egreso guardado, la factura subida no debe quedar huérfana
            if ($ruta !== null) {
                Storage::disk(self::DISCO)->delete($ruta);
            }

            throw $e;
        }
    }

    /**
     * Anula un egreso con su motivo; un egreso nunca se borra
     *
     * @throws ValidationException si ya estaba anulado
     */
    public function anular(User $usuario, Egreso $egreso, string $motivo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $egreso, $motivo) {
            // Se relee bloqueado para que dos anulaciones a la vez no se registren ambas
            $actual = $this->egresos->bloquearRegistro($egreso->id);

            if ($actual === null || $actual->estado !== Egreso::VIGENTE) {
                throw ValidationException::withMessages(['motivo' => 'El egreso ya está anulado.']);
            }

            $this->egresos->actualizar($actual, [
                'estado' => Egreso::ANULADO,
                'motivo_anulacion' => trim($motivo),
            ]);

            $this->auditoria->registrar($usuario, 'tesoreria', 'egreso_anulado', $actual, [
                'valor_anterior' => ['estado' => Egreso::VIGENTE],
                'valor_nuevo' => ['estado' => Egreso::ANULADO],
                'motivo' => trim($motivo),
            ]);
        });
    }

    /**
     * Ruta del archivo de la factura, o null si el egreso no tiene o el archivo no existe
     */
    public function rutaFactura(Egreso $egreso): ?string
    {
        $disco = Storage::disk(self::DISCO);

        return $egreso->factura_path !== null && $disco->exists($egreso->factura_path)
            ? $disco->path($egreso->factura_path)
            : null;
    }

    private function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
