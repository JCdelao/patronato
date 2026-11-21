import { Head, Link, router, usePage } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { useState } from 'react';
import IncidenciaController from '@/actions/Patronato/Presentacion/Controllers/IncidenciaController';
import { Button } from '@/components/ui/button';
import IncidenciaItem from '@/components/vigilancia/incidencia-item';
import TiposIncidenciaDialog from '@/components/vigilancia/tipos-incidencia-dialog';
import type { Incidencia, TipoIncidencia } from '@/types/vigilancia';

const claseSelect =
    'h-10 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

// Incidencias que registraron los guardias, con filtros por tipo y urgencia
export default function Incidencias({
    tipo,
    urgentes,
    incidencias,
    tipos,
}: {
    tipo: number | null;
    urgentes: boolean;
    incidencias: Paginado<Incidencia>;
    tipos: TipoIncidencia[];
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('tipos_incidencia.registrar');
    const [catalogo, setCatalogo] = useState(false);

    const filtrar = (cambios: { tipo?: number | null; urgentes?: boolean }) => {
        const filtros = { tipo, urgentes, ...cambios };

        router.get(
            IncidenciaController.index.url(),
            {
                ...(filtros.tipo ? { tipo: filtros.tipo } : {}),
                ...(filtros.urgentes ? { urgentes: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Incidencias" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-3xl font-bold tracking-tight">
                        Incidencias
                    </h1>
                    {puedeRegistrar && (
                        <Button
                            variant="outline"
                            onClick={() => setCatalogo(true)}
                        >
                            <ListChecks />
                            Tipos de incidencia
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <select
                        aria-label="Filtrar por tipo"
                        value={tipo ?? ''}
                        onChange={(evento) =>
                            filtrar({
                                tipo: Number(evento.target.value) || null,
                            })
                        }
                        className={claseSelect}
                    >
                        <option value="">Todos los tipos</option>
                        {tipos.map((opcion) => (
                            <option key={opcion.id} value={opcion.id}>
                                {opcion.nombre}
                            </option>
                        ))}
                    </select>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={urgentes}
                            onChange={(evento) =>
                                filtrar({ urgentes: evento.target.checked })
                            }
                            className="size-4"
                        />
                        Solo urgentes
                    </label>
                </div>

                {incidencias.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        No hay incidencias registradas.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {incidencias.data.map((incidencia) => (
                            <IncidenciaItem
                                key={incidencia.id}
                                incidencia={incidencia}
                            />
                        ))}
                    </ul>
                )}

                {(incidencias.prev_page_url || incidencias.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {incidencias.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={incidencias.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {incidencias.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={incidencias.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {catalogo && (
                <TiposIncidenciaDialog
                    tipos={tipos}
                    onClose={() => setCatalogo(false)}
                />
            )}
        </>
    );
}
