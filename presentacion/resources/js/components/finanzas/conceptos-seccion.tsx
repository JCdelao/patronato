import { router } from '@inertiajs/react';
import { Ban, Ellipsis, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ConceptoController from '@/actions/Patronato/Presentacion/Controllers/ConceptoController';
import ConceptoDialog from '@/components/finanzas/concepto-dialog';
import ConfirmarDialog from '@/components/padron/confirmar-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Concepto } from '@/types/finanzas';

type Dialogo =
    | { tipo: 'concepto'; concepto?: Concepto }
    | { tipo: 'desactivar'; concepto: Concepto }
    | { tipo: 'reactivar'; concepto: Concepto }
    | { tipo: 'eliminar'; concepto: Concepto };

const TIPOS = { recurrente: 'Recurrente', unico: 'Único' };

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

export default function ConceptosSeccion({
    conceptos,
    periodoActual,
    puedeEditar,
}: {
    conceptos: Concepto[];
    periodoActual: string;
    puedeEditar: boolean;
}) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);

    const cerrar = () => setDialogo(null);

    // Recarga los datos tras guardar
    const alGuardar = () => {
        cerrar();
        router.reload({ only: ['conceptos'] });
    };

    return (
        <>
            <section className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight">
                            Conceptos de cobro
                        </h2>
                        <p className="mt-1 text-muted-foreground">
                            Agua, mantenimiento, vigilancia y otros cobros con
                            su monto
                        </p>
                    </div>

                    {puedeEditar && (
                        <Button
                            onClick={() => setDialogo({ tipo: 'concepto' })}
                        >
                            <Plus />
                            Concepto
                        </Button>
                    )}
                </div>

                {conceptos.length === 0 ? (
                    <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                        Todavía no hay conceptos. Registra el primero con el
                        botón “Concepto”.
                    </p>
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {conceptos.map((concepto) => (
                            <article
                                key={concepto.id}
                                className={`rounded-2xl border bg-card p-6 shadow-xs ${concepto.activo ? '' : 'opacity-70'}`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h3 className="truncate text-xl font-bold tracking-tight">
                                            {concepto.nombre}
                                        </h3>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <span className="rounded-full bg-secondary px-3 py-0.5 text-sm">
                                                {TIPOS[concepto.tipo]}
                                            </span>
                                            {!concepto.activo && (
                                                <span className="rounded-full bg-muted px-3 py-0.5 text-sm text-muted-foreground">
                                                    Inactivo
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {puedeEditar &&
                                        !concepto.es_cuota_unificada && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Acciones de ${concepto.nombre}`}
                                                    >
                                                        <Ellipsis />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {concepto.activo ? (
                                                        <>
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    setDialogo({
                                                                        tipo: 'concepto',
                                                                        concepto,
                                                                    })
                                                                }
                                                            >
                                                                <Pencil />
                                                                Editar
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    setDialogo({
                                                                        tipo: 'desactivar',
                                                                        concepto,
                                                                    })
                                                                }
                                                            >
                                                                <Ban />
                                                                Desactivar
                                                            </DropdownMenuItem>
                                                        </>
                                                    ) : (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setDialogo({
                                                                    tipo: 'reactivar',
                                                                    concepto,
                                                                })
                                                            }
                                                        >
                                                            <RotateCcw />
                                                            Reactivar
                                                        </DropdownMenuItem>
                                                    )}
                                                    {concepto.activo &&
                                                        !concepto.tiene_cargos && (
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onSelect={() =>
                                                                    setDialogo({
                                                                        tipo: 'eliminar',
                                                                        concepto,
                                                                    })
                                                                }
                                                            >
                                                                <Trash2 />
                                                                Eliminar
                                                            </DropdownMenuItem>
                                                        )}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                </div>

                                {concepto.es_cuota_unificada ? (
                                    <p className="mt-4 text-muted-foreground">
                                        Cada vivienda conserva el monto que
                                        tenía en el padrón hasta que se definan
                                        las tarifas por tipo de vivienda.
                                    </p>
                                ) : (
                                    <ul className="mt-4 grid gap-1">
                                        {concepto.actual && (
                                            <li>
                                                <span className="text-xl font-semibold">
                                                    L.{' '}
                                                    {formatoMonto(
                                                        concepto.actual.monto,
                                                    )}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    desde{' '}
                                                    {nombrePeriodo(
                                                        concepto.actual.desde,
                                                    )}
                                                </span>
                                            </li>
                                        )}
                                        {concepto.proximos.map((vigencia) => (
                                            <li
                                                key={vigencia.desde}
                                                className="text-muted-foreground"
                                            >
                                                Desde{' '}
                                                {nombrePeriodo(vigencia.desde)}:
                                                L.{' '}
                                                {formatoMonto(vigencia.monto)}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </article>
                        ))}
                    </div>
                )}
            </section>

            {dialogo?.tipo === 'concepto' && (
                <ConceptoDialog
                    concepto={dialogo.concepto}
                    periodoActual={periodoActual}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'desactivar' && (
                <ConfirmarDialog
                    titulo={`¿Desactivar ${dialogo.concepto.nombre}?`}
                    descripcion="Dejará de generar cargos nuevos. Los cargos ya generados no cambian."
                    confirmar="Desactivar"
                    accion={ConceptoController.desactivar.form(
                        dialogo.concepto.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'reactivar' && (
                <ConfirmarDialog
                    titulo={`¿Reactivar ${dialogo.concepto.nombre}?`}
                    descripcion="Volverá a generar cargos con su monto vigente."
                    confirmar="Reactivar"
                    variante="default"
                    accion={ConceptoController.reactivar.form(
                        dialogo.concepto.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'eliminar' && (
                <ConfirmarDialog
                    titulo={`¿Eliminar ${dialogo.concepto.nombre}?`}
                    descripcion="Se borrará junto con sus montos. Solo es posible porque todavía no tiene cargos."
                    confirmar="Eliminar"
                    accion={ConceptoController.destroy.form(
                        dialogo.concepto.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}
