import { Head, Link, router, usePage } from '@inertiajs/react';
import { Landmark, Search } from 'lucide-react';
import { useRef } from 'react';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import TransferenciaController from '@/actions/Patronato/Presentacion/Controllers/TransferenciaController';
import EstadoPago from '@/components/finanzas/estado-pago';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatoFecha, formatoMonto } from '@/lib/formato';

type Pago = {
    id: number;
    numero_recibo: string | null;
    fecha_pago: string;
    vivienda: string;
    monto: string;
    forma_pago: string;
    estado: string;
    cobrador: string | null;
    numero_referencia: string | null;
};

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function Pagos({
    pagos,
    buscar,
}: {
    pagos: Paginado<Pago>;
    buscar: string;
}) {
    const { auth } = usePage().props;
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                PagoController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <>
            <Head title="Cobros" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Cobros
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Pagos registrados, incluidos los anulados
                        </p>
                    </div>
                    {auth.permisos.includes('transferencias.registrar') && (
                        <Button asChild>
                            <Link href={TransferenciaController.create.url()}>
                                <Landmark />
                                Registrar transferencia
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="relative w-full max-w-md">
                    <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        defaultValue={buscar}
                        onChange={(evento) => alBuscar(evento.target.value)}
                        placeholder="N.º de recibo o vivienda"
                        aria-label="Buscar por número de recibo o vivienda"
                        className="h-11 pl-11"
                    />
                </div>

                {pagos.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {buscar
                            ? 'No hay pagos con esa búsqueda.'
                            : 'Todavía no hay pagos registrados.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {pagos.data.map((pago) => (
                            <li key={pago.id}>
                                <Link
                                    href={PagoController.show.url(pago.id)}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                                >
                                    <span>
                                        <span className="block font-medium">
                                            {pago.numero_recibo
                                                ? `Recibo N.º ${pago.numero_recibo}`
                                                : `Transferencia ref. ${pago.numero_referencia}`}{' '}
                                            · {pago.vivienda}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {formatoFecha(pago.fecha_pago)}
                                            {pago.cobrador &&
                                                ` · ${pago.cobrador}`}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <EstadoPago estado={pago.estado} />
                                        <span
                                            className={
                                                pago.estado === 'anulado'
                                                    ? 'font-medium text-muted-foreground line-through'
                                                    : 'font-medium'
                                            }
                                        >
                                            L {formatoMonto(pago.monto)}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {(pagos.prev_page_url || pagos.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {pagos.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={pagos.prev_page_url}>Anterior</Link>
                            </Button>
                        )}
                        {pagos.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={pagos.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}
