import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useRef } from 'react';
import EgresoController from '@/actions/Patronato/Presentacion/Controllers/EgresoController';
import EstadoEgreso from '@/components/finanzas/estado-egreso';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatoFecha, formatoMonto } from '@/lib/formato';
import type { FilaEgreso } from '@/types/finanzas';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function Egresos({
    egresos,
    buscar,
}: {
    egresos: Paginado<FilaEgreso>;
    buscar: string;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('tesoreria.registrar');
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                EgresoController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <>
            <Head title="Egresos" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Egresos
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Gastos del patronato, incluidos los anulados
                        </p>
                    </div>
                    {puedeRegistrar && (
                        <Button asChild>
                            <Link href={EgresoController.create.url()}>
                                <Plus />
                                Nuevo egreso
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
                        placeholder="Concepto, proveedor o comprobante"
                        aria-label="Buscar por concepto, proveedor o número de comprobante"
                        className="h-11 pl-11"
                    />
                </div>

                {egresos.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {buscar
                            ? 'No hay egresos con esa búsqueda.'
                            : 'Todavía no hay egresos registrados.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {egresos.data.map((egreso) => (
                            <li key={egreso.id}>
                                <Link
                                    href={EgresoController.show.url(egreso.id)}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">
                                            {egreso.concepto}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {formatoFecha(egreso.fecha)} ·{' '}
                                            {egreso.categoria}
                                            {egreso.proveedor &&
                                                ` · ${egreso.proveedor}`}{' '}
                                            · Comp. {egreso.numero_comprobante}
                                        </span>
                                    </span>
                                    <span className="flex shrink-0 items-center gap-3">
                                        {egreso.estado === 'anulado' && (
                                            <EstadoEgreso
                                                estado={egreso.estado}
                                            />
                                        )}
                                        <span
                                            className={
                                                egreso.estado === 'anulado'
                                                    ? 'font-medium text-muted-foreground line-through'
                                                    : 'font-medium'
                                            }
                                        >
                                            L {formatoMonto(egreso.monto)}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {(egresos.prev_page_url || egresos.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {egresos.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={egresos.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {egresos.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={egresos.next_page_url}>
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
