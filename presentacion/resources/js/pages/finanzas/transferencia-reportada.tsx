import { Head, Link } from '@inertiajs/react';
import { Clock, Paperclip, Wallet } from 'lucide-react';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import TransferenciaReportadaController from '@/actions/Patronato/Presentacion/Controllers/TransferenciaReportadaController';
import EstadoPago from '@/components/finanzas/estado-pago';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatoFecha, formatoFechaHora, formatoMonto } from '@/lib/formato';

type Reporte = {
    id: number;
    vivienda: string;
    monto: string;
    fecha_pago: string;
    banco: string | null;
    numero_referencia: string | null;
    cuenta_receptora: string | null;
    estado: string;
    reportado_por: string | null;
    reportado_en: string;
    tiene_comprobante: boolean;
};

export default function TransferenciaReportada({
    reporte,
    puedeVerCobros,
}: {
    reporte: Reporte;
    puedeVerCobros: boolean;
}) {
    const titulo = `Transferencia ref. ${reporte.numero_referencia ?? ''}`;

    return (
        <>
            <Head title={titulo} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            {titulo}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Reportada desde el portal · Vivienda{' '}
                            {reporte.vivienda}
                        </p>
                    </div>
                    <EstadoPago estado={reporte.estado} />
                </div>

                {reporte.estado === 'reportado_por_vecino' && (
                    <Alert>
                        <Clock />
                        <AlertTitle>Pendiente de verificación</AlertTitle>
                        <AlertDescription>
                            No reduce el saldo de la vivienda hasta que se
                            confirme que el dinero ingresó a la cuenta.
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Monto</dt>
                    <dd className="font-semibold">
                        L {formatoMonto(reporte.monto)}
                    </dd>
                    <dt className="text-muted-foreground">Fecha</dt>
                    <dd className="font-semibold">
                        {formatoFecha(reporte.fecha_pago)}
                    </dd>
                    <dt className="text-muted-foreground">Banco</dt>
                    <dd className="font-semibold">{reporte.banco}</dd>
                    <dt className="text-muted-foreground">Referencia</dt>
                    <dd className="font-semibold">
                        {reporte.numero_referencia}
                    </dd>
                    <dt className="text-muted-foreground">Cuenta receptora</dt>
                    <dd className="font-semibold">
                        {reporte.cuenta_receptora ?? 'No indicada'}
                    </dd>
                    <dt className="text-muted-foreground">Reportó</dt>
                    <dd className="font-semibold">
                        {reporte.reportado_por ?? '—'}
                    </dd>
                    <dt className="text-muted-foreground">Reportado</dt>
                    <dd className="font-semibold">
                        {formatoFechaHora(reporte.reportado_en)}
                    </dd>
                </dl>

                <div className="flex flex-wrap gap-3">
                    {reporte.tiene_comprobante && (
                        <Button variant="outline" asChild>
                            <a
                                href={TransferenciaReportadaController.comprobante.url(
                                    reporte.id,
                                )}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Paperclip />
                                Ver comprobante de la transferencia
                            </a>
                        </Button>
                    )}
                    {puedeVerCobros && (
                        <Button variant="outline" asChild>
                            <Link href={PagoController.show.url(reporte.id)}>
                                <Wallet />
                                Ver en cobros
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}
