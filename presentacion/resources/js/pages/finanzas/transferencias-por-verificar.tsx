import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import VerificacionTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/VerificacionTransferenciaController';
import { Badge } from '@/components/ui/badge';
import { formatoFecha, formatoMonto } from '@/lib/formato';

type Pendiente = {
    id: number;
    vivienda: string;
    monto: string;
    fecha_pago: string;
    banco: string | null;
    numero_referencia: string | null;
    origen: string;
    registrado_por: string | null;
    registrado_en: string;
    dias_sin_verificar: number;
    motivo_no_confirmar: string | null;
};

// Días que lleva sin verificar, en palabras
const textoDias = (dias: number) =>
    dias === 0
        ? 'Registrada hoy'
        : dias === 1
          ? '1 día sin verificar'
          : `${dias} días sin verificar`;

export default function TransferenciasPorVerificar({
    transferencias,
}: {
    transferencias: Pendiente[];
}) {
    return (
        <>
            <Head title="Transferencias por verificar" />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Transferencias por verificar
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Revisa la cuenta bancaria antes de confirmar. Solo el
                        dinero confirmado reduce el saldo de la vivienda.
                    </p>
                </div>

                {transferencias.length === 0 ? (
                    <p className="text-muted-foreground">
                        No hay transferencias pendientes.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {transferencias.map((transferencia) => (
                            <li key={transferencia.id}>
                                <Link
                                    href={VerificacionTransferenciaController.show.url(
                                        transferencia.id,
                                    )}
                                    className="flex items-center gap-4 rounded-xl border p-4 hover:bg-muted/50"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold">
                                            {transferencia.vivienda} ·{' '}
                                            {transferencia.banco} · ref.{' '}
                                            {transferencia.numero_referencia}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatoFecha(
                                                transferencia.fecha_pago,
                                            )}{' '}
                                            ·{' '}
                                            {transferencia.origen === 'portal'
                                                ? `reportó ${transferencia.registrado_por ?? 'el vecino'} desde el portal`
                                                : `registró ${transferencia.registrado_por ?? '—'}`}
                                        </p>
                                        {transferencia.motivo_no_confirmar && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                La registraste tú: otra persona
                                                debe confirmarla.
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-col items-end gap-1">
                                        <span className="text-lg font-semibold">
                                            L{' '}
                                            {formatoMonto(transferencia.monto)}
                                        </span>
                                        <Badge variant="secondary">
                                            {textoDias(
                                                transferencia.dias_sin_verificar,
                                            )}
                                        </Badge>
                                    </div>
                                    <ChevronRight className="size-4 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
