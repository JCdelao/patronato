import { Head, Link, router } from '@inertiajs/react';
import { IdCard, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import CarnetController from '@/actions/Patronato/Presentacion/Controllers/CarnetController';
import CarnetsDialog from '@/components/carnets/carnets-dialog';
import EstadoCredencial from '@/components/credenciales/estado-credencial';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatoFecha } from '@/lib/formato';
import type { ResidenteCarnets } from '@/types/carnets';
import { CREDENCIAL_VIGENTE } from '@/types/credenciales';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function Carnets({
    buscar,
    residentes,
    vigenciaPredeterminada,
}: {
    buscar: string;
    residentes: Paginado<ResidenteCarnets>;
    vigenciaPredeterminada: string;
}) {
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const [abiertoId, setAbiertoId] = useState<number | null>(null);

    // Se lee de los datos de la página para ver cada cambio sin cerrar el diálogo
    const abierto = residentes.data.find(
        (residente) => residente.id === abiertoId,
    );

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                CarnetController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <>
            <Head title="Carnets" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Carnets
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Carnet de cada residente, su vigencia e historial. Los
                        que terminan su vigencia vencen solos cada día.
                    </p>
                </div>

                <div className="relative w-full max-w-md">
                    <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        defaultValue={buscar}
                        onChange={(evento) => alBuscar(evento.target.value)}
                        placeholder="Nombre, identidad, carnet o vivienda"
                        aria-label="Buscar por nombre, identidad, número de carnet o vivienda"
                        className="h-11 pl-11"
                    />
                </div>

                {residentes.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {buscar
                            ? 'No hay residentes con esa búsqueda.'
                            : 'Todavía no hay residentes activos.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {residentes.data.map((residente) => {
                            const vigente = residente.carnets.find((carnet) =>
                                CREDENCIAL_VIGENTE.includes(carnet.estado),
                            );

                            return (
                                <li key={residente.id}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setAbiertoId(residente.id)
                                        }
                                        className="flex w-full items-center justify-between gap-4 p-4 text-left hover:bg-muted/50"
                                    >
                                        <span className="flex min-w-0 gap-3">
                                            <IdCard className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">
                                                    {residente.nombre}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    {residente.viviendas.join(
                                                        ', ',
                                                    ) || 'Sin vivienda'}
                                                    {vigente?.vigente_hasta &&
                                                        ` · vale hasta el ${formatoFecha(vigente.vigente_hasta)}`}
                                                </span>
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 items-center gap-2 text-sm">
                                            {vigente ? (
                                                <>
                                                    <span className="font-medium">
                                                        {vigente.numero}
                                                    </span>
                                                    {vigente.estado !==
                                                        'activo' && (
                                                        <EstadoCredencial
                                                            estado={
                                                                vigente.estado
                                                            }
                                                        />
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Sin carnet
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {(residentes.prev_page_url || residentes.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {residentes.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={residentes.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {residentes.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={residentes.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {abierto && (
                <CarnetsDialog
                    residente={abierto}
                    vigenciaPredeterminada={vigenciaPredeterminada}
                    onClose={() => setAbiertoId(null)}
                />
            )}
        </>
    );
}
