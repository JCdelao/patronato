import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useRef } from 'react';
import IngresoController from '@/actions/Patronato/Presentacion/Controllers/IngresoController';
import EstadoMovimiento from '@/components/finanzas/estado-movimiento';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatoFecha, formatoMonto } from '@/lib/formato';
import type { FilaIngreso } from '@/types/finanzas';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function Ingresos({
    ingresos,
    buscar,
}: {
    ingresos: Paginado<FilaIngreso>;
    buscar: string;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('tesoreria.registrar');
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                IngresoController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <>
            <Head title="Ingresos" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Ingresos
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Cuotas cobradas y otros ingresos, incluidos los
                            anulados
                        </p>
                    </div>
                    {puedeRegistrar && (
                        <Button asChild>
                            <Link href={IngresoController.create.url()}>
                                <Plus />
                                Otro ingreso
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
                        placeholder="Concepto, recibo o comprobante"
                        aria-label="Buscar por concepto, recibo o número de comprobante"
                        className="h-11 pl-11"
                    />
                </div>

                {ingresos.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {buscar
                            ? 'No hay ingresos con esa búsqueda.'
                            : 'Todavía no hay ingresos registrados.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {ingresos.data.map((ingreso) => (
                            <li key={ingreso.id}>
                                <Link
                                    href={IngresoController.show.url(
                                        ingreso.id,
                                    )}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">
                                            {ingreso.concepto}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {formatoFecha(ingreso.fecha)} ·{' '}
                                            {ingreso.categoria}
                                        </span>
                                    </span>
                                    <span className="flex shrink-0 items-center gap-3">
                                        {ingreso.de_cobro && (
                                            <Badge variant="outline">
                                                Cobro
                                            </Badge>
                                        )}
                                        {ingreso.estado === 'anulado' && (
                                            <EstadoMovimiento
                                                estado={ingreso.estado}
                                            />
                                        )}
                                        <span
                                            className={
                                                ingreso.estado === 'anulado'
                                                    ? 'font-medium text-muted-foreground line-through'
                                                    : 'font-medium'
                                            }
                                        >
                                            L {formatoMonto(ingreso.monto)}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {(ingresos.prev_page_url || ingresos.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {ingresos.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={ingresos.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {ingresos.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={ingresos.next_page_url}>
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
