import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import CajaController from '@/actions/Patronato/Presentacion/Controllers/CajaController';
import { formatoFechaHora, formatoMonto } from '@/lib/formato';

type ResumenCaja = {
    id: number;
    cobrador: string;
    abierta_en: string;
    cerrada_en: string | null;
    cobros: number;
    total_registrado: string;
    diferencia: string | null;
    propia: boolean;
};

function FilaCaja({ caja }: { caja: ResumenCaja }) {
    const diferencia = Number(caja.diferencia ?? 0);

    return (
        <Link
            href={CajaController.show.url(caja.id)}
            className="flex items-center gap-3 p-4 hover:bg-muted"
        >
            <span className="flex-1">
                <span className="block font-semibold">
                    {caja.cobrador}
                    {caja.propia && (
                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                            (tu caja)
                        </span>
                    )}
                </span>
                <span className="text-sm text-muted-foreground">
                    {caja.cerrada_en === null
                        ? `Abierta el ${formatoFechaHora(caja.abierta_en)}`
                        : `Cerrada el ${formatoFechaHora(caja.cerrada_en)}`}{' '}
                    · {caja.cobros} {caja.cobros === 1 ? 'cobro' : 'cobros'}
                </span>
            </span>
            <span className="text-right">
                <span className="block font-medium">
                    L {formatoMonto(caja.total_registrado)}
                </span>
                {caja.cerrada_en !== null && diferencia !== 0 && (
                    <span
                        className={
                            diferencia < 0
                                ? 'text-sm text-destructive'
                                : 'text-sm text-muted-foreground'
                        }
                    >
                        {diferencia < 0 ? 'Faltante' : 'Sobrante'} L{' '}
                        {formatoMonto(Math.abs(diferencia))}
                    </span>
                )}
            </span>
            <ChevronRight className="size-4 text-muted-foreground" />
        </Link>
    );
}

export default function Cajas({
    abiertas,
    cerradas,
}: {
    abiertas: ResumenCaja[];
    cerradas: ResumenCaja[];
}) {
    return (
        <>
            <Head title="Cierre de caja" />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Cierre de caja
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Compara lo registrado por cada cobrador con el efectivo
                        que entrega
                    </p>
                </div>

                <section className="space-y-2">
                    <h2 className="text-sm font-medium">Cajas abiertas</h2>
                    {abiertas.length === 0 ? (
                        <p className="text-muted-foreground">
                            No hay cajas abiertas. Cada cobrador abre la suya
                            con su primer cobro.
                        </p>
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {abiertas.map((caja) => (
                                <FilaCaja key={caja.id} caja={caja} />
                            ))}
                        </div>
                    )}
                </section>

                {cerradas.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Últimos cierres</h2>
                        <div className="divide-y rounded-xl border">
                            {cerradas.map((caja) => (
                                <FilaCaja key={caja.id} caja={caja} />
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
