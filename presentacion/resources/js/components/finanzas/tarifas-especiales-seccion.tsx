import { router } from '@inertiajs/react';
import { Ellipsis, Pencil, Plus, Undo2 } from 'lucide-react';
import { useState } from 'react';
import QuitarEspecialDialog from '@/components/finanzas/quitar-especial-dialog';
import TarifaEspecialDialog from '@/components/finanzas/tarifa-especial-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TarifaEspecial } from '@/types/finanzas';
import type { OpcionVivienda } from '@/types/padron';

type Dialogo =
    | { tipo: 'editar'; especial?: TarifaEspecial }
    | { tipo: 'quitar'; especial: TarifaEspecial };

// Muestra una fecha AAAA-MM-DD como "noviembre de 2026"
const nombrePeriodo = (fecha: string) =>
    new Date(`${fecha}T00:00:00Z`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

const formatoMonto = (monto: string) =>
    Number(monto).toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

export default function TarifasEspecialesSeccion({
    especiales,
    viviendas,
    periodoActual,
    puedeEditar,
}: {
    especiales: TarifaEspecial[];
    viviendas: OpcionVivienda[];
    periodoActual: string;
    puedeEditar: boolean;
}) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);

    const cerrar = () => setDialogo(null);

    // Recarga los datos tras guardar
    const alGuardar = () => {
        cerrar();
        router.reload({ only: ['especiales'] });
    };

    // Una vivienda que ya tiene cuota especial se cambia desde su tarjeta
    const sinEspecial = viviendas.filter(
        (vivienda) =>
            !especiales.some(
                (especial) => especial.vivienda_id === vivienda.id,
            ),
    );

    return (
        <>
            <section className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight">
                            Cuotas especiales
                        </h2>
                        <p className="mt-1 text-muted-foreground">
                            Viviendas que pagan una cuota distinta a la de su
                            tipo, con su motivo
                        </p>
                    </div>

                    {puedeEditar && (
                        <Button
                            disabled={sinEspecial.length === 0}
                            onClick={() => setDialogo({ tipo: 'editar' })}
                        >
                            <Plus />
                            Cuota especial
                        </Button>
                    )}
                </div>

                {especiales.length === 0 ? (
                    <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                        Ninguna vivienda tiene cuota especial: todas pagan la
                        cuota de su tipo.
                    </p>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {especiales.map((especial) => (
                            <article
                                key={especial.vivienda_id}
                                className="rounded-2xl border bg-card p-6 shadow-xs"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <h3 className="truncate text-xl font-bold tracking-tight">
                                        {especial.codigo}
                                    </h3>

                                    {puedeEditar && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Acciones de ${especial.codigo}`}
                                                >
                                                    <Ellipsis />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'editar',
                                                            especial,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                    Cambiar
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'quitar',
                                                            especial,
                                                        })
                                                    }
                                                >
                                                    <Undo2 />
                                                    Quitar
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </div>

                                <ul className="mt-4 grid gap-1">
                                    {especial.actual && (
                                        <li>
                                            <span className="text-xl font-semibold">
                                                L.{' '}
                                                {formatoMonto(
                                                    especial.actual.monto,
                                                )}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                al mes desde{' '}
                                                {nombrePeriodo(
                                                    especial.actual.desde,
                                                )}
                                            </span>
                                            <p className="text-sm text-muted-foreground">
                                                {especial.actual.motivo}
                                            </p>
                                        </li>
                                    )}
                                    {especial.proximos.map((vigencia) => (
                                        <li
                                            key={vigencia.desde}
                                            className="text-muted-foreground"
                                        >
                                            Desde{' '}
                                            {nombrePeriodo(vigencia.desde)}: L.{' '}
                                            {formatoMonto(vigencia.monto)}
                                        </li>
                                    ))}
                                </ul>
                            </article>
                        ))}
                    </div>
                )}
            </section>

            {dialogo?.tipo === 'editar' && (
                <TarifaEspecialDialog
                    especial={dialogo.especial}
                    viviendas={sinEspecial}
                    periodoActual={periodoActual}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'quitar' && (
                <QuitarEspecialDialog
                    especial={dialogo.especial}
                    periodoActual={periodoActual}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}
