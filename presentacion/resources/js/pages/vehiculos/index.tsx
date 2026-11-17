import { Head, Link, router, usePage } from '@inertiajs/react';
import { Ban, Car, Ellipsis, Pencil, Plus, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import VehiculoController from '@/actions/Patronato/Presentacion/Controllers/VehiculoController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import BajaDialog from '@/components/vehiculos/baja-dialog';
import VehiculoDialog from '@/components/vehiculos/vehiculo-dialog';
import type { OpcionVivienda, TipoVehiculo, Vehiculo } from '@/types/vehiculos';
import { TIPOS_VEHICULO } from '@/types/vehiculos';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Dialogo =
    | { tipo: 'vehiculo'; vehiculo?: Vehiculo }
    | { tipo: 'baja'; vehiculo: Vehiculo };

export default function Vehiculos({
    estado,
    buscar,
    vehiculos,
    viviendas,
    tipos,
    nuevoPara,
}: {
    estado: 'activos' | 'bajas';
    buscar: string;
    vehiculos: Paginado<Vehiculo>;
    viviendas: OpcionVivienda[];
    tipos: TipoVehiculo[];
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
                                    <Car className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0">
                                        <p className="font-semibold tracking-wide">
                                            {vehiculo.placa}
                                            <span className="ml-2 text-sm font-normal text-muted-foreground">
                                                {TIPOS_VEHICULO[vehiculo.tipo]}
                                            </span>
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
                                    </div>
                                </div>
                                {activos && (puedeRegistrar || puedeAnular) && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Acciones de ${vehiculo.placa}`}
                                            >
                                                <Ellipsis />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
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
        </>
    );
}
