import { Head, Link } from '@inertiajs/react';
import { Paperclip, Send } from 'lucide-react';
import PortalTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/PortalTransferenciaController';
import EstadoPago from '@/components/finanzas/estado-pago';
import { Button } from '@/components/ui/button';
import { formatoFecha, formatoMonto } from '@/lib/formato';

type Reporte = {
    id: number;
    fecha_pago: string;
    registrado_en: string;
    vivienda: string;
    monto: string;
    banco: string | null;
    numero_referencia: string | null;
    cuenta_receptora: string | null;
    estado: string;
    tiene_comprobante: boolean;
};

export default function Transferencias({ reportes }: { reportes: Reporte[] }) {
    return (
        <>
            <Head title="Mis reportes" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Mis reportes
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Transferencias que reportaste desde el portal
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={PortalTransferenciaController.create.url()}>
                            <Send />
                            Reportar transferencia
                        </Link>
                    </Button>
                </div>

                {reportes.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-muted-foreground">
                        Todavía no has reportado transferencias.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {reportes.map((reporte) => (
                            <li key={reporte.id} className="space-y-2 p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-semibold">
                                            L {formatoMonto(reporte.monto)} ·
                                            Vivienda {reporte.vivienda}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatoFecha(reporte.fecha_pago)} ·{' '}
                                            {reporte.banco} · Ref.{' '}
                                            {reporte.numero_referencia}
                                        </p>
                                    </div>
                                    <EstadoPago estado={reporte.estado} />
                                </div>
                                {reporte.tiene_comprobante && (
                                    <a
                                        href={PortalTransferenciaController.comprobante.url(
                                            reporte.id,
                                        )}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        <Paperclip className="size-4" />
                                        Ver comprobante
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
