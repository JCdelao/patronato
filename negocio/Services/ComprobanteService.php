<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Notificaciones\ComprobantePago;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;

/**
 * Comprobante de un pago: datos, PDF, envío por correo y acceso
 */
class ComprobanteService
{
    /**
     * Días que funciona el enlace para compartir por mensajería
     */
    public const DIAS_ENLACE = 30;

    /**
     * Zona horaria de la hora que muestra el comprobante
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    private const VISTA_PDF = 'pdf.comprobante';

    public function __construct(
        private PagoRepositorio $pagos,
        private VinculoRepositorio $vinculos,
        private PermisoService $permisos,
        private GeneradorPdf $generador,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Solo los pagos con número de recibo tienen comprobante
     */
    public function tieneComprobante(Pago $pago): bool
    {
        return $pago->numero_recibo !== null;
    }

    /**
     * Lo ve el cobrador que registró el pago y quien tenga cobros.ver en su residencial
     */
    public function puedeVer(User $usuario, Pago $pago): bool
    {
        if ($usuario->residencial_id !== $pago->residencial_id) {
            return false;
        }

        if ($this->permisos->puede($usuario, 'cobros', 'ver')) {
            return true;
        }

        return $pago->registrado_por === $usuario->id
            && $this->permisos->puede($usuario, 'cobros', 'registrar');
    }

    /**
     * Pago de un enlace firmado, que se abre sin iniciar sesión
     */
    public function delEnlace(int $pagoId): ?Pago
    {
        $pago = $this->pagos->buscarSinResidencial($pagoId);

        return $pago !== null && $this->tieneComprobante($pago) ? $pago : null;
    }

    /**
     * Datos que muestra el comprobante
     *
     * Un pago anulado conserva su comprobante, marcado como anulado con la fecha y el motivo.
     *
     * En una transferencia, la hora es la de su confirmación y en lugar del cobrador aparece quien la verificó.
     *
     * @return array{pago_id: int, residencial: string, direccion: string|null, telefono: string|null, numero_recibo: string, fecha: string, hora: string, vivienda: string, monto: string, periodos: list<array{periodo: string, concepto: string, monto: string}>, forma_pago: string, referencia: string|null, etiqueta_responsable: string, cobrador: string, anulado: bool, anulado_en: string|null, motivo_anulacion: string|null}
     */
    public function datos(Pago $pago): array
    {
        $pago = $this->pagos->conDetalle($pago);
        $esTransferencia = $pago->forma_pago === Pago::TRANSFERENCIA;
        $hora = $esTransferencia && $pago->verificado_en !== null ? $pago->verificado_en : $pago->created_at;

        // Solo lo aplicado al cobrar: el saldo a favor aplicado después no cambia el comprobante
        $periodos = array_values($pago->aplicaciones
            ->filter(fn (PagoAplicacion $aplicacion): bool => $aplicacion->esDelPago())
            ->sortBy(fn (PagoAplicacion $aplicacion) => [$aplicacion->cargo->periodo->toDateString(), $aplicacion->cargo_id])
            ->map(fn (PagoAplicacion $aplicacion): array => [
                'periodo' => $aplicacion->cargo->periodo->toDateString(),
                'concepto' => $aplicacion->cargo->concepto->nombre,
                'monto' => $aplicacion->monto,
            ])
            ->all());

        return [
            'pago_id' => $pago->id,
            'residencial' => $pago->residencial->nombre,
            'direccion' => $pago->residencial->direccion,
            'telefono' => $pago->residencial->telefono,
            'numero_recibo' => (string) $pago->numero_recibo,
            'fecha' => $pago->fecha_pago->toDateString(),
            'hora' => $hora->setTimezone(self::ZONA_LOCAL)->format('H:i'),
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'periodos' => $periodos,
            'forma_pago' => $esTransferencia ? 'Transferencia' : 'Efectivo',
            'referencia' => $esTransferencia ? trim("{$pago->banco} · ref. {$pago->numero_referencia}") : null,
            'etiqueta_responsable' => $esTransferencia ? 'Verificó' : 'Cobrador',
            'cobrador' => ($esTransferencia ? $pago->verificador?->name : $pago->cobrador?->name) ?? 'Sin cobrador',
            'anulado' => $pago->estado === Pago::ANULADO,
            'anulado_en' => $pago->anulado_en?->setTimezone(self::ZONA_LOCAL)->format('Y-m-d H:i'),
            'motivo_anulacion' => $pago->estado === Pago::ANULADO ? $pago->motivo_anulacion : null,
        ];
    }

    /**
     * Contenido del comprobante en PDF, con el logo del residencial si tiene
     */
    public function pdf(Pago $pago): string
    {
        $pago = $this->pagos->conDetalle($pago);

        return $this->generador->generar(self::VISTA_PDF, [
            'comprobante' => $this->datos($pago),
            'logo' => $this->logoEnLinea($pago->residencial->logo_path),
        ]);
    }

    public function nombreArchivo(Pago $pago): string
    {
        return "comprobante-{$pago->numero_recibo}.pdf";
    }

    /**
     * Correos de los residentes actuales de la vivienda, para llenar el formulario
     *
     * @return list<string>
     */
    public function correosSugeridos(Pago $pago): array
    {
        return $this->vinculos->correosVigentesDe($pago->vivienda_id);
    }

    /**
     * Envía el comprobante en PDF a los correos indicados y deja el envío auditado
     *
     * @param  list<string>  $correos
     */
    public function enviarPorCorreo(User $usuario, Pago $pago, array $correos): void
    {
        $pago = $this->pagos->conDetalle($pago);

        Notification::route('mail', $correos)->notify(new ComprobantePago(
            $pago->residencial->nombre,
            (string) $pago->numero_recibo,
            $this->pdf($pago),
            $this->nombreArchivo($pago),
        ));

        $this->auditoria->registrar($usuario, 'cobros', 'comprobante_enviado', $pago, [
            'valor_nuevo' => [
                'numero_recibo' => $pago->numero_recibo,
                'correos' => $correos,
            ],
        ]);
    }

    /**
     * El logo como data URI, para que dompdf no tenga que abrir archivos del disco
     */
    private function logoEnLinea(?string $ruta): ?string
    {
        $disco = Storage::disk('public');

        if ($ruta === null || ! $disco->exists($ruta)) {
            return null;
        }

        $tipo = $disco->mimeType($ruta) ?: 'image/png';

        return "data:{$tipo};base64,".base64_encode((string) $disco->get($ruta));
    }
}
