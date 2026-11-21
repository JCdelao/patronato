import { Head, Link, router, usePage } from '@inertiajs/react';
import { Ban, Ellipsis, Pencil, Plus, Search, Tag } from 'lucide-react';
import { useRef, useState } from 'react';
import { formatoFecha } from '@/lib/formato';
import VehiculoController from '@/actions/Patronato/Presentacion/Controllers/VehiculoController';
import EstadoCredencial from '@/components/credenciales/estado-credencial';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import BajaDialog from '@/components/vehiculos/baja-dialog';
import IconoVehiculo from '@/components/vehiculos/icono-vehiculo';
import StickersDialog from '@/components/vehiculos/stickers-dialog';
import VehiculoDialog from '@/components/vehiculos/vehiculo-dialog';
import type { OpcionVivienda, TipoVehiculo, Vehiculo } from '@/types/vehiculos';
import { CREDENCIAL_VIGENTE } from '@/types/credenciales';
import { identificador, TIPOS_VEHICULO } from '@/types/vehiculos';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Dialogo =
    | { tipo: 'vehiculo'; vehiculo?: Vehiculo }
    | { tipo: 'baja'; vehiculo: Vehiculo }
    | { tipo: 'stickers'; vehiculoId: number };

export default function Vehiculos({
    estado,
    buscar,
    vehiculos,
    viviendas,
    tipos,
    tiposComprobante,
    hoy,
    nuevoPara,
}: {
    estado: 'activos' | 'bajas';
    buscar: string;
    vehiculos: Paginado<Vehiculo>;
    viviendas: OpcionVivienda[];
    tipos: TipoVehiculo[];
    tiposComprobante: Record<string, string>;
    hoy: string;
    nuevoPara: number | null;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('vehiculos.registrar');
    const puedeAnular = auth.permisos.includes('vehiculos.anular');
    const activos = estado === 'activos';
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    // Al llegar desde una vivienda se abre el formulario con ella elegida
    const [dialogo, setDialogo] = useState<Dialogo | null>(
        nuevoPara !== null && puedeRegistrar ? { tipo: 'vehiculo' } : null,
    );

    // Se lee de los datos de la página para ver cada cambio sin cerrar el diálogo
    const stickersDe =
        dialogo?.tipo === 'stickers'
            ? vehiculos.data.find(
                  (vehiculo) => vehiculo.id === dialogo.vehiculoId,
              )
            : undefined;

    const consulta = (valor: string) => ({
        ...(activos ? {} : { estado: 'bajas' }),
        ...(valor ? { buscar: valor } : {}),
    });

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(VehiculoController.index.url(), consulta(valor), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);
    };

    return (
        <>
            <Head title="Vehículos" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Vehículos
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Vehículos de cada vivienda y quiénes pueden
                            conducirlos
                        </p>
                    </div>
                    {puedeRegistrar && activos && (
                        <Button
                            onClick={() => setDialogo({ tipo: 'vehiculo' })}
                        >
                            <Plus />
                            Agregar vehículo
                        </Button>
                    )}
                </div>

                <nav
                    aria-label="Estado de los vehículos"
                    className="flex w-fit gap-1 rounded-xl border bg-card p-1"
                >
                    {(
                        [
                            ['activos', 'Activos', {}],
                            ['bajas', 'Dados de baja', { estado: 'bajas' }],
                        ] as const
                    ).map(([clave, texto, query]) => (
                        <Button
                            key={clave}
                            asChild
                            size="sm"
                            variant={clave === estado ? 'secondary' : 'ghost'}
                        >
                            <Link
                                href={VehiculoController.index.url({ query })}
                                aria-current={
                                    clave === estado ? 'page' : undefined
                                }
                            >
                                {texto}
                            </Link>
                        </Button>
                    ))}
                </nav>

                <div className="relative w-full max-w-md">
                    <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        defaultValue={buscar}
                        onChange={(evento) => alBuscar(evento.target.value)}
                        placeholder="Placa, marca, color o vivienda"
                        aria-label="Buscar por placa, marca, modelo, color o vivienda"
                        className="h-11 pl-11"
                    />
                </div>

                {vehiculos.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {buscar
                            ? 'No hay vehículos con esa búsqueda.'
                            : activos
                              ? 'Todavía no hay vehículos registrados.'
                              : 'No hay vehículos dados de baja.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {vehiculos.data.map((vehiculo) => (
                            <li
                                key={vehiculo.id}
                                className="flex items-start justify-between gap-4 p-4"
                            >
                                <div className="flex min-w-0 gap-3">
                                    <IconoVehiculo
                                        tipo={vehiculo.tipo}
                                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                    />
                                    <div className="min-w-0">
                                        <p className="flex flex-wrap items-center gap-2 font-semibold tracking-wide">
                                            {identificador(vehiculo)}
                                            <span className="text-sm font-normal text-muted-foreground">
                                                {TIPOS_VEHICULO[vehiculo.tipo]}
                                            </span>
                                            {vehiculo.placa === null &&
                                                (vehiculo.comprobante_vencido ? (
                                                    <Badge variant="destructive">
                                                        Comprobante vencido
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        Placa en trámite
                                                    </Badge>
                                                ))}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                vehiculo.marca,
                                                vehiculo.modelo,
                                                vehiculo.color,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || 'Sin detalles'}
                                            {' · '}Vivienda {vehiculo.vivienda}
                                        </p>
                                        {vehiculo.conductores.length > 0 && (
                                            <p className="text-sm">
                                                Conductores:{' '}
                                                {vehiculo.conductores
                                                    .map((c) => c.nombre)
                                                    .join(', ')}
                                            </p>
                                        )}
                                        <StickerVigente vehiculo={vehiculo} />
                                        {vehiculo.comprobante && (
                                            <ComprobanteVehiculo
                                                vehiculo={vehiculo}
                                                tipos={tiposComprobante}
                                            />
                                        )}
                                    </div>
                                </div>
                                {activos && (puedeRegistrar || puedeAnular) && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Acciones de ${identificador(vehiculo)}`}
                                            >
                                                <Ellipsis />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setDialogo({
                                                        tipo: 'stickers',
                                                        vehiculoId: vehiculo.id,
                                                    })
                                                }
                                            >
                                                <Tag />
                                                Stickers
                                            </DropdownMenuItem>
                                            {puedeRegistrar && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'vehiculo',
                                                            vehiculo,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                    Editar
                                                </DropdownMenuItem>
                                            )}
                                            {puedeAnular && (
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'baja',
                                                            vehiculo,
                                                        })
                                                    }
                                                >
                                                    <Ban />
                                                    Dar de baja
                                                </DropdownMenuItem>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {(vehiculos.prev_page_url || vehiculos.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {vehiculos.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={vehiculos.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {vehiculos.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={vehiculos.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {dialogo?.tipo === 'vehiculo' && (
                <VehiculoDialog
                    vehiculo={dialogo.vehiculo}
                    viviendas={viviendas}
                    tipos={tipos}
                    tiposComprobante={tiposComprobante}
                    hoy={hoy}
                    viviendaInicial={dialogo.vehiculo ? null : nuevoPara}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo?.tipo === 'baja' && (
                <BajaDialog
                    vehiculo={dialogo.vehiculo}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo?.tipo === 'stickers' && stickersDe && (
                <StickersDialog
                    vehiculo={stickersDe}
                    onClose={() => setDialogo(null)}
                />
            )}
        </>
    );
}

function StickerVigente({ vehiculo }: { vehiculo: Vehiculo }) {
    const vigente = vehiculo.stickers.find((sticker) =>
        CREDENCIAL_VIGENTE.includes(sticker.estado),
    );

    return (
        <p className="mt-1 flex items-center gap-2 text-sm">
            {vigente ? (
                <>
                    <span>Sticker N.º {vigente.numero}</span>
                    {vigente.estado !== 'activo' && (
                        <EstadoCredencial estado={vigente.estado} />
                    )}
                </>
            ) : (
                <span className="text-muted-foreground">Sin sticker</span>
            )}
        </p>
    );
}

// Comprobante de circulación; queda como historial cuando el vehículo ya tiene placa
function ComprobanteVehiculo({
    vehiculo,
    tipos,
}: {
    vehiculo: Vehiculo;
    tipos: Record<string, string>;
}) {
    const comprobante = vehiculo.comprobante;

    if (!comprobante) {
        return null;
    }

    const tipo =
        comprobante.tipo === 'otro' && comprobante.descripcion
            ? comprobante.descripcion
            : (tipos[comprobante.tipo ?? ''] ?? comprobante.tipo);

    return (
        <p className="mt-1 text-sm text-muted-foreground">
            {vehiculo.placa ? 'Circuló con ' : ''}
            {tipo} N.º {comprobante.numero}
            {comprobante.vence &&
                ` · vale hasta el ${formatoFecha(comprobante.vence)}`}
            {comprobante.tiene_archivo && (
                <>
                    {' · '}
                    <a
                        href={VehiculoController.comprobante.url(vehiculo.id)}
                        target="_blank"
                        rel="noopener"
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        Ver comprobante
                    </a>
                </>
            )}
        </p>
    );
}
