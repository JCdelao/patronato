import { router } from '@inertiajs/react';
import { Ban, Ellipsis, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import TipoViviendaController from '@/actions/Patronato/Presentacion/Controllers/TipoViviendaController';
import TipoViviendaDialog from '@/components/finanzas/tipo-vivienda-dialog';
import ConfirmarDialog from '@/components/padron/confirmar-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TipoViviendaCuota } from '@/types/finanzas';

type Dialogo =
    | { tipo: 'editar'; tipoVivienda?: TipoViviendaCuota }
    | { tipo: 'desactivar'; tipoVivienda: TipoViviendaCuota }
    | { tipo: 'reactivar'; tipoVivienda: TipoViviendaCuota }
    | { tipo: 'eliminar'; tipoVivienda: TipoViviendaCuota };

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

export default function TiposViviendaSeccion({
    tipos,
    periodoActual,
    puedeEditar,
}: {
    tipos: TipoViviendaCuota[];
    periodoActual: string;
    puedeEditar: boolean;
}) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);

    const cerrar = () => setDialogo(null);

    // Recarga los datos tras guardar
    const alGuardar = () => {
        cerrar();
        router.reload({ only: ['tipos'] });
    };

    return (
        <>
            <section className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight">
                            Tipos de vivienda y cuota mensual
                        </h2>
                        <p className="mt-1 text-muted-foreground">
                            Cada vivienda paga la cuota de su tipo, más los
                            conceptos de cobro activos
                        </p>
                    </div>

                    {puedeEditar && (
                        <Button onClick={() => setDialogo({ tipo: 'editar' })}>
                            <Plus />
                            Tipo de vivienda
                        </Button>
                    )}
                </div>

                {tipos.length === 0 ? (
                    <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                        Todavía no hay tipos de vivienda. Registra el primero
                        con el botón “Tipo de vivienda”.
                    </p>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {tipos.map((tipoVivienda) => (
                            <article
                                key={tipoVivienda.id}
                                className={`rounded-2xl border bg-card p-6 shadow-xs ${tipoVivienda.activo ? '' : 'opacity-70'}`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h3 className="truncate text-xl font-bold tracking-tight">
                                            {tipoVivienda.nombre}
                                        </h3>
                                        {!tipoVivienda.activo && (
                                            <span className="mt-2 inline-block rounded-full bg-muted px-3 py-0.5 text-sm text-muted-foreground">
                                                Inactivo
                                            </span>
                                        )}
                                    </div>

                                    {puedeEditar && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Acciones de ${tipoVivienda.nombre}`}
                                                >
                                                    <Ellipsis />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {tipoVivienda.activo ? (
                                                    <>
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setDialogo({
                                                                    tipo: 'editar',
                                                                    tipoVivienda,
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
                                                                    tipoVivienda,
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
                                                                tipoVivienda,
                                                            })
                                                        }
                                                    >
                                                        <RotateCcw />
                                                        Reactivar
                                                    </DropdownMenuItem>
                                                )}
                                                {tipoVivienda.activo &&
                                                    !tipoVivienda.tiene_viviendas && (
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onSelect={() =>
                                                                setDialogo({
                                                                    tipo: 'eliminar',
                                                                    tipoVivienda,
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

                                <ul className="mt-4 grid gap-1">
                                    {tipoVivienda.actual ? (
                                        <li>
                                            <span className="text-xl font-semibold">
                                                L.{' '}
                                                {formatoMonto(
                                                    tipoVivienda.actual.monto,
                                                )}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                al mes desde{' '}
                                                {nombrePeriodo(
                                                    tipoVivienda.actual.desde,
                                                )}
                                            </span>
                                        </li>
                                    ) : (
                                        tipoVivienda.proximos.length === 0 && (
                                            <li className="text-muted-foreground">
                                                Sin cuota definida
                                            </li>
                                        )
                                    )}
                                    {tipoVivienda.proximos.map((vigencia) => (
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
                <TipoViviendaDialog
                    tipo={dialogo.tipoVivienda}
                    periodoActual={periodoActual}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'desactivar' && (
                <ConfirmarDialog
                    titulo={`¿Desactivar ${dialogo.tipoVivienda.nombre}?`}
                    descripcion="Ya no se podrá elegir para viviendas nuevas. Las viviendas que lo tienen lo conservan."
                    confirmar="Desactivar"
                    accion={TipoViviendaController.desactivar.form(
                        dialogo.tipoVivienda.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'reactivar' && (
                <ConfirmarDialog
                    titulo={`¿Reactivar ${dialogo.tipoVivienda.nombre}?`}
                    descripcion="Se podrá volver a elegir para las viviendas."
                    confirmar="Reactivar"
                    variante="default"
                    accion={TipoViviendaController.reactivar.form(
                        dialogo.tipoVivienda.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'eliminar' && (
                <ConfirmarDialog
                    titulo={`¿Eliminar ${dialogo.tipoVivienda.nombre}?`}
                    descripcion="Se borrará junto con sus cuotas. Solo es posible porque ninguna vivienda lo usa."
                    confirmar="Eliminar"
                    accion={TipoViviendaController.destroy.form(
                        dialogo.tipoVivienda.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}
