import { Head, Link, router, usePage } from '@inertiajs/react';
import { RotateCcw, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import ResidenteController from '@/actions/Patronato/Modules/Padron/Controllers/ResidenteController';
import AgregarViviendaDialog from '@/components/padron/agregar-vivienda-dialog';
import PadronNav from '@/components/padron/padron-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { OpcionVivienda, ResidenteInactivo } from '@/types/padron';

const TIPOS_RESIDENTE = {
    propietario: 'Propietario',
    inquilino: 'Inquilino',
    familiar: 'Familiar',
};

const formatoFecha = (fecha: string | null) =>
    fecha
        ? new Date(`${fecha}T00:00:00`).toLocaleDateString('es-HN')
        : 'sin fecha';

export default function Residentes({
    residentes,
    opcionesViviendas,
    buscar,
}: {
    residentes: {
        data: ResidenteInactivo[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    opcionesViviendas: OpcionVivienda[];
    buscar: string;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('padron.registrar');

    const [reactivar, setReactivar] = useState<ResidenteInactivo | null>(null);
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alGuardar = () => {
        setReactivar(null);
        router.reload({ only: ['residentes'] });
    };

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                ResidenteController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <>
            <Head title="Residentes inactivos" />

            <div className="space-y-6 p-6 md:p-10">
                <div>
                    <h1 className="text-4xl font-bold tracking-tight">
                        Viviendas y residentes
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Personas que ya no viven en el residencial, con su
                        historial
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative w-full max-w-xl">
                        <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            aria-label="Buscar residentes inactivos"
                            defaultValue={buscar}
                            onChange={(evento) => alBuscar(evento.target.value)}
                            placeholder="Buscar por nombre, identidad o teléfono"
                            className="pl-11"
                        />
                    </div>

                    <PadronNav actual="residentes" />
                </div>

                {residentes.data.length === 0 ? (
                    <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                        {buscar
                            ? 'Ningún residente coincide con la búsqueda.'
                            : 'No hay residentes inactivos.'}
                    </p>
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {residentes.data.map((residente) => (
                            <article
                                key={residente.id}
                                className="rounded-2xl border bg-card p-6 shadow-xs"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="truncate text-2xl font-bold tracking-tight">
                                            {residente.nombres}{' '}
                                            {residente.apellidos}
                                        </h2>
                                        <p className="mt-1 text-muted-foreground">
                                            {[
                                                residente.identidad,
                                                residente.telefono,
                                                residente.correo,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                                'Sin datos de contacto'}
                                        </p>
                                    </div>

                                    {puedeRegistrar && (
                                        <Button
                                            variant="outline"
                                            className="shrink-0"
                                            disabled={
                                                opcionesViviendas.length === 0
                                            }
                                            onClick={() =>
                                                setReactivar(residente)
                                            }
                                        >
                                            <RotateCcw />
                                            Reactivar
                                        </Button>
                                    )}
                                </div>

                                <h3 className="mt-4 text-sm font-medium">
                                    Historial
                                </h3>
                                <ul className="mt-1 grid gap-1 text-sm">
                                    {residente.historial.map(
                                        (vinculo, indice) => (
                                            <li
                                                key={indice}
                                                className="flex flex-wrap gap-x-2"
                                            >
                                                <span className="font-medium">
                                                    {vinculo.vivienda}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {
                                                        TIPOS_RESIDENTE[
                                                            vinculo.tipo
                                                        ]
                                                    }
                                                </span>
                                                <span className="ml-auto text-muted-foreground">
                                                    {formatoFecha(
                                                        vinculo.desde,
                                                    )}{' '}
                                                    a{' '}
                                                    {formatoFecha(
                                                        vinculo.hasta,
                                                    )}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </article>
                        ))}
                    </div>
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

            {reactivar && (
                <AgregarViviendaDialog
                    residente={reactivar}
                    titulo={`Reactivar a ${reactivar.nombres}`}
                    descripcion="Elige la vivienda donde vive ahora. Su historial anterior se conserva."
                    viviendas={opcionesViviendas}
                    onClose={() => setReactivar(null)}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}

Residentes.layout = {
    breadcrumbs: [
        {
            title: 'Residentes inactivos',
            href: ResidenteController.index(),
        },
    ],
};
