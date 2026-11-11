import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Ellipsis,
    History,
    HousePlus,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    Trash2,
    UserMinus,
} from 'lucide-react';
import { useRef, useState } from 'react';
import ResidenteController from '@/actions/Patronato/Presentacion/Controllers/ResidenteController';
import ViviendaController from '@/actions/Patronato/Presentacion/Controllers/ViviendaController';
import AgregarViviendaDialog from '@/components/padron/agregar-vivienda-dialog';
import ConfirmarDialog from '@/components/padron/confirmar-dialog';
import HistorialDialog from '@/components/padron/historial-dialog';
import PadronNav from '@/components/padron/padron-nav';
import ResidenteDialog from '@/components/padron/residente-dialog';
import ViviendaDialog from '@/components/padron/vivienda-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { OpcionVivienda, Residente, Vivienda } from '@/types/padron';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Dialogo =
    | { tipo: 'vivienda'; vivienda?: Vivienda }
    | { tipo: 'residente'; residente?: Residente }
    | { tipo: 'eliminar-vivienda'; vivienda: Vivienda }
    | { tipo: 'reactivar-vivienda'; vivienda: Vivienda }
    | { tipo: 'baja-residente'; residente: Residente }
    | { tipo: 'agregar-vivienda'; residente: Residente }
    | { tipo: 'historial'; vivienda: Vivienda };

const TIPOS_VIVIENDA = { casa: 'Casa', apartamento: 'Apartamento' };

const ubicacion = (vivienda: Vivienda) =>
    [
        vivienda.tipo === 'apartamento'
            ? vivienda.edificio_torre
            : vivienda.calle_bloque,
        vivienda.numero && `No. ${vivienda.numero}`,
    ]
        .filter(Boolean)
        .join(', ');

const formatoCuota = (cuota: string) =>
    Number(cuota).toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

export default function Viviendas({
    viviendas,
    opcionesViviendas,
    buscar,
    estado,
}: {
    viviendas: Paginado<Vivienda>;
    opcionesViviendas: OpcionVivienda[];
    buscar: string;
    estado: 'activas' | 'inactivas';
}) {
    const inactivas = estado === 'inactivas';
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('padron.registrar');
    const puedeAnular = auth.permisos.includes('padron.anular');

    const [dialogo, setDialogo] = useState<Dialogo | null>(null);
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const cerrar = () => setDialogo(null);

    // Recarga los datos tras guardar
    const alGuardar = () => {
        cerrar();
        router.reload({ only: ['viviendas', 'opcionesViviendas'] });
    };

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                ViviendaController.index.url(),
                {
                    ...(valor ? { buscar: valor } : {}),
                    ...(inactivas ? { estado } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <>
            <Head title="Viviendas y residentes" />

            <div className="space-y-6 p-6 md:p-10">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-4xl font-bold tracking-tight">
                            Viviendas y residentes
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            Padrón de casas, apartamentos y sus habitantes
                        </p>
                    </div>

                    {puedeRegistrar && (
                        <div className="flex gap-3">
                            <Button
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'vivienda' })}
                            >
                                <Plus />
                                Vivienda
                            </Button>
                            <Button
                                disabled={opcionesViviendas.length === 0}
                                onClick={() =>
                                    setDialogo({ tipo: 'residente' })
                                }
                            >
                                <Plus />
                                Residente
                            </Button>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative w-full max-w-xl">
                        <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            aria-label="Buscar viviendas"
                            defaultValue={buscar}
                            onChange={(evento) => alBuscar(evento.target.value)}
                            placeholder="Buscar por código, nombre o teléfono"
                            className="pl-11"
                        />
                    </div>

                    <PadronNav actual={estado} />
                </div>

                {viviendas.data.length === 0 ? (
                    <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                        {buscar
                            ? 'Ninguna vivienda coincide con la búsqueda.'
                            : inactivas
                              ? 'No hay viviendas inactivas.'
                              : 'Todavía no hay viviendas. Registra la primera con el botón “Vivienda”.'}
                    </p>
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {viviendas.data.map((vivienda) => (
                            <article
                                key={vivienda.id}
                                className="rounded-2xl border bg-card p-6 shadow-xs"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="truncate text-2xl font-bold tracking-tight">
                                            {vivienda.codigo}
                                        </h2>
                                        <p className="mt-1 text-muted-foreground">
                                            {[
                                                TIPOS_VIVIENDA[vivienda.tipo],
                                                ubicacion(vivienda),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        {vivienda.estado_ocupacion ===
                                            'desocupada' && (
                                            <span className="mt-2 inline-block rounded-full bg-muted px-3 py-0.5 text-sm text-muted-foreground">
                                                Desocupada
                                            </span>
                                        )}
                                    </div>

                                    <div className="flex shrink-0 items-center gap-1">
                                        {vivienda.cuota !== null && (
                                            <span className="rounded-full bg-secondary px-3 py-1 text-sm font-medium whitespace-nowrap">
                                                L.{' '}
                                                {formatoCuota(vivienda.cuota)}
                                                /mes
                                            </span>
                                        )}

                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Acciones de ${vivienda.codigo}`}
                                                >
                                                    <Ellipsis />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'historial',
                                                            vivienda,
                                                        })
                                                    }
                                                >
                                                    <History />
                                                    Historial
                                                </DropdownMenuItem>
                                                {!inactivas &&
                                                    puedeRegistrar && (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setDialogo({
                                                                    tipo: 'vivienda',
                                                                    vivienda,
                                                                })
                                                            }
                                                        >
                                                            <Pencil />
                                                            Editar
                                                        </DropdownMenuItem>
                                                    )}
                                                {!inactivas && puedeAnular && (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            setDialogo({
                                                                tipo: 'eliminar-vivienda',
                                                                vivienda,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 />
                                                        Eliminar
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>

                                {inactivas && puedeRegistrar && (
                                    <Button
                                        variant="outline"
                                        className="mt-4"
                                        onClick={() =>
                                            setDialogo({
                                                tipo: 'reactivar-vivienda',
                                                vivienda,
                                            })
                                        }
                                    >
                                        <RotateCcw />
                                        Reactivar
                                    </Button>
                                )}

                                <ul
                                    className={
                                        inactivas ? 'hidden' : 'mt-4 grid gap-1'
                                    }
                                >
                                    {vivienda.residentes.length === 0 && (
                                        <li className="text-muted-foreground">
                                            Sin residentes
                                        </li>
                                    )}

                                    {vivienda.residentes.map((residente) => (
                                        <li
                                            key={residente.id}
                                            className="flex flex-wrap items-center gap-x-2"
                                        >
                                            <span className="min-w-0 truncate">
                                                {residente.nombres}{' '}
                                                {residente.apellidos}
                                            </span>
                                            {residente.es_responsable_pago && (
                                                <span className="text-sm text-primary">
                                                    (responsable)
                                                </span>
                                            )}
                                            {residente.otras_viviendas.length >
                                                0 && (
                                                <span className="text-sm text-muted-foreground">
                                                    también en{' '}
                                                    {residente.otras_viviendas.join(
                                                        ', ',
                                                    )}
                                                </span>
                                            )}
                                            <span className="ml-auto text-muted-foreground">
                                                {residente.telefono}
                                            </span>
                                            {puedeRegistrar && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Agregar otra vivienda a ${residente.nombres}`}
                                                    onClick={() =>
                                                        setDialogo({
                                                            tipo: 'agregar-vivienda',
                                                            residente,
                                                        })
                                                    }
                                                >
                                                    <HousePlus />
                                                </Button>
                                            )}
                                            {puedeRegistrar && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Editar a ${residente.nombres}`}
                                                    onClick={() =>
                                                        setDialogo({
                                                            tipo: 'residente',
                                                            residente,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                            )}
                                            {puedeAnular && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Dar de baja a ${residente.nombres}`}
                                                    onClick={() =>
                                                        setDialogo({
                                                            tipo: 'baja-residente',
                                                            residente,
                                                        })
                                                    }
                                                >
                                                    <UserMinus />
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </article>
                        ))}
                    </div>
                )}

                {(viviendas.prev_page_url || viviendas.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {viviendas.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={viviendas.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {viviendas.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={viviendas.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {dialogo?.tipo === 'vivienda' && (
                <ViviendaDialog
                    vivienda={dialogo.vivienda}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'residente' && (
                <ResidenteDialog
                    residente={dialogo.residente}
                    viviendas={opcionesViviendas}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'eliminar-vivienda' && (
                <ConfirmarDialog
                    titulo={`¿Eliminar ${dialogo.vivienda.codigo}?`}
                    descripcion="Si tiene pagos o historial, quedará inactiva en lugar de borrarse."
                    confirmar="Eliminar"
                    accion={ViviendaController.destroy.form(
                        dialogo.vivienda.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'reactivar-vivienda' && (
                <ConfirmarDialog
                    titulo={`¿Reactivar ${dialogo.vivienda.codigo}?`}
                    descripcion="Volverá a aparecer en Activas con sus datos y su cuota."
                    confirmar="Reactivar"
                    variante="default"
                    accion={ViviendaController.reactivar.form(
                        dialogo.vivienda.id,
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'baja-residente' && (
                <ConfirmarDialog
                    titulo={`¿Dar de baja a ${dialogo.residente.nombres}?`}
                    descripcion={
                        dialogo.residente.otras_viviendas.length > 0
                            ? `Dejará de aparecer en esta vivienda y seguirá en ${dialogo.residente.otras_viviendas.join(', ')}.`
                            : 'Dejará de aparecer en la vivienda y pasará a Residentes inactivos. Su historial se conserva.'
                    }
                    confirmar="Dar de baja"
                    accion={ResidenteController.destroy.form(
                        dialogo.residente.id,
                        { query: { vivienda: dialogo.residente.vivienda_id } },
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'historial' && (
                <HistorialDialog vivienda={dialogo.vivienda} onClose={cerrar} />
            )}

            {dialogo?.tipo === 'agregar-vivienda' && (
                <AgregarViviendaDialog
                    residente={dialogo.residente}
                    titulo={`Agregar vivienda a ${dialogo.residente.nombres}`}
                    descripcion="El residente seguirá en sus viviendas actuales y quedará asociado también a la que elijas."
                    viviendas={opcionesViviendas.filter(
                        (opcion) =>
                            opcion.id !== dialogo.residente.vivienda_id &&
                            !dialogo.residente.otras_viviendas.includes(
                                opcion.codigo,
                            ),
                    )}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}

Viviendas.layout = {
    breadcrumbs: [
        {
            title: 'Viviendas',
            href: ViviendaController.index(),
        },
    ],
};
