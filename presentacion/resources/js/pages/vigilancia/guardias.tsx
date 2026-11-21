import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Ellipsis,
    KeyRound,
    Pencil,
    Plus,
    Power,
    Search,
    UserPlus,
} from 'lucide-react';
import { useRef, useState } from 'react';
import GuardiaController from '@/actions/Patronato/Presentacion/Controllers/GuardiaController';
import ConfirmarDialog from '@/components/padron/confirmar-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import CuentaGuardiaDialog from '@/components/vigilancia/cuenta-guardia-dialog';
import GuardiaDialog from '@/components/vigilancia/guardia-dialog';
import type { Guardia, Turno } from '@/types/vigilancia';
import { TURNOS } from '@/types/vigilancia';

type Paginado<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Dialogo =
    | { tipo: 'guardia'; guardia?: Guardia }
    | { tipo: 'cuenta'; guardia: Guardia }
    | { tipo: 'estado'; guardia: Guardia };

export default function Guardias({
    estado,
    buscar,
    guardias,
    turnos,
    credenciales,
}: {
    estado: 'activos' | 'inactivos';
    buscar: string;
    guardias: Paginado<Guardia>;
    turnos: Turno[];
    credenciales: { correo: string; password_temporal: string } | null;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('guardias.registrar');
    const activos = estado === 'activos';
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);

    const consulta = (valor: string) => ({
        ...(activos ? {} : { estado: 'inactivos' }),
        ...(valor ? { buscar: valor } : {}),
    });

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(GuardiaController.index.url(), consulta(valor), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);
    };

    return (
        <>
            <Head title="Guardias" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Guardias
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Personal de vigilancia con su turno y su cuenta
                        </p>
                    </div>
                    {puedeRegistrar && activos && (
                        <Button onClick={() => setDialogo({ tipo: 'guardia' })}>
                            <Plus />
                            Agregar guardia
                        </Button>
                    )}
                </div>

                <nav
                    aria-label="Estado de los guardias"
                    className="flex w-fit gap-1 rounded-xl border bg-card p-1"
                >
                    {(
                        [
                            ['activos', 'Activos', {}],
                            ['inactivos', 'Inactivos', { estado: 'inactivos' }],
                        ] as const
                    ).map(([clave, texto, query]) => (
                        <Button
                            key={clave}
                            asChild
                            size="sm"
                            variant={clave === estado ? 'secondary' : 'ghost'}
                        >
                            <Link
                                href={GuardiaController.index.url({ query })}
                                aria-current={
                                    clave === estado ? 'page' : undefined
                                }
                            >
                                {texto}
                            </Link>
                        </Button>
                    ))}
                </nav>

                {credenciales && (
                    <Alert>
                        <KeyRound />
                        <AlertTitle>Cuenta creada</AlertTitle>
                        <AlertDescription>
                            <p>
                                No se pudo enviar el correo. Copia estos datos y
                                entrégalos al guardia. La contraseña temporal no
                                se volverá a mostrar.
                            </p>
                            <p>
                                Correo: <strong>{credenciales.correo}</strong>
                            </p>
                            <p>
                                Contraseña temporal:{' '}
                                <code className="font-mono font-semibold break-all text-foreground">
                                    {credenciales.password_temporal}
                                </code>
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="relative w-full max-w-md">
                    <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        defaultValue={buscar}
                        onChange={(evento) => alBuscar(evento.target.value)}
                        placeholder="Nombre, identidad o teléfono"
                        aria-label="Buscar por nombre, identidad o teléfono"
                        className="h-11 pl-11"
                    />
                </div>

                {guardias.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {buscar
                            ? 'No hay guardias con esa búsqueda.'
                            : activos
                              ? 'Todavía no hay guardias registrados.'
                              : 'No hay guardias inactivos.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {guardias.data.map((guardia) => (
                            <li
                                key={guardia.id}
                                className="flex items-start justify-between gap-4 p-4"
                            >
                                <div className="min-w-0">
                                    <p className="font-semibold">
                                        {guardia.nombre}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Identidad {guardia.identidad}
                                        {guardia.telefono &&
                                            ` · Tel. ${guardia.telefono}`}
                                    </p>
                                    <p className="text-sm">
                                        Turno{' '}
                                        {TURNOS[guardia.turno].toLowerCase()}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {guardia.cuenta
                                            ? `Cuenta: ${guardia.cuenta.email}${guardia.cuenta.activa ? '' : ' (desactivada)'}`
                                            : 'Sin cuenta'}
                                    </p>
                                </div>
                                {puedeRegistrar && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Acciones de ${guardia.nombre}`}
                                            >
                                                <Ellipsis />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {activos && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'guardia',
                                                            guardia,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                    Editar
                                                </DropdownMenuItem>
                                            )}
                                            {activos && !guardia.cuenta && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialogo({
                                                            tipo: 'cuenta',
                                                            guardia,
                                                        })
                                                    }
                                                >
                                                    <UserPlus />
                                                    Crear cuenta
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuItem
                                                variant={
                                                    activos
                                                        ? 'destructive'
                                                        : 'default'
                                                }
                                                onSelect={() =>
                                                    setDialogo({
                                                        tipo: 'estado',
                                                        guardia,
                                                    })
                                                }
                                            >
                                                <Power />
                                                {activos
                                                    ? 'Desactivar'
                                                    : 'Activar'}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {(guardias.prev_page_url || guardias.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {guardias.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={guardias.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {guardias.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={guardias.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {dialogo?.tipo === 'guardia' && (
                <GuardiaDialog
                    guardia={dialogo.guardia}
                    turnos={turnos}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo?.tipo === 'cuenta' && (
                <CuentaGuardiaDialog
                    guardia={dialogo.guardia}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo?.tipo === 'estado' &&
                (activos ? (
                    <ConfirmarDialog
                        titulo={`¿Desactivar a ${dialogo.guardia.nombre}?`}
                        descripcion="Pasará a Inactivos y su cuenta quedará desactivada. Sus datos se conservan."
                        confirmar="Desactivar"
                        accion={GuardiaController.desactivar.form(
                            dialogo.guardia.id,
                        )}
                        onClose={() => setDialogo(null)}
                        onGuardado={() => setDialogo(null)}
                    />
                ) : (
                    <ConfirmarDialog
                        titulo={`¿Activar a ${dialogo.guardia.nombre}?`}
                        descripcion="Volverá a Activos y su cuenta, si tiene, se activará de nuevo."
                        confirmar="Activar"
                        variante="default"
                        accion={GuardiaController.activar.form(
                            dialogo.guardia.id,
                        )}
                        onClose={() => setDialogo(null)}
                        onGuardado={() => setDialogo(null)}
                    />
                ))}
        </>
    );
}
