import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Settings2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import CuentaController from '@/actions/Patronato/Modules/Identidad/Controllers/CuentaController';
import PermisoController from '@/actions/Patronato/Modules/Identidad/Controllers/PermisoController';
import CuentaDialog from '@/components/identidad/cuenta-dialog';
import ParametrosDialog from '@/components/identidad/parametros-dialog';
import ConfirmarDialog from '@/components/padron/confirmar-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    Cuenta,
    ParametrosAcceso,
    ResidenteOpcion,
    RolOpcion,
} from '@/types/identidad';

type Dialogo =
    | { tipo: 'cuenta'; cuenta?: Cuenta }
    | { tipo: 'estado'; cuenta: Cuenta }
    | { tipo: 'parametros' };

export default function Usuarios({
    cuentas,
    roles,
    residentes,
    parametros,
    puedeConfigurarAccesos,
    credenciales,
}: {
    cuentas: Cuenta[];
    roles: RolOpcion[];
    residentes: ResidenteOpcion[];
    parametros: ParametrosAcceso;
    puedeConfigurarAccesos: boolean;
    credenciales: { correo: string; password_temporal: string } | null;
}) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);

    const cerrar = () => setDialogo(null);

    const alGuardar = () => {
        cerrar();
        router.reload({ only: ['cuentas', 'residentes', 'parametros'] });
    };

    return (
        <>
            <Head title="Usuarios" />

            <div className="space-y-6 p-6 md:p-10">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-4xl font-bold tracking-tight">
                            Usuarios
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            Cuentas de acceso de este residencial
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-3">
                        {puedeConfigurarAccesos && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={PermisoController.index()}>
                                        <ShieldCheck />
                                        Permisos
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setDialogo({ tipo: 'parametros' })
                                    }
                                >
                                    <Settings2 />
                                    Parámetros
                                </Button>
                            </>
                        )}
                        <Button onClick={() => setDialogo({ tipo: 'cuenta' })}>
                            <Plus />
                            Cuenta
                        </Button>
                    </div>
                </div>

                {credenciales && (
                    <Alert>
                        <KeyRound />
                        <AlertTitle>Cuenta creada</AlertTitle>
                        <AlertDescription>
                            <p>
                                Copia estos datos y entrégalos a la persona. La
                                contraseña temporal no se volverá a mostrar.
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

                <ul className="divide-y rounded-2xl border bg-card">
                    {cuentas.map((cuenta) => (
                        <li
                            key={cuenta.id}
                            className="flex flex-wrap items-center gap-3 p-4"
                        >
                            <div className="min-w-0 flex-1 basis-56">
                                <p className="truncate font-medium">
                                    {cuenta.name}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    {[cuenta.email, cuenta.vivienda]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>

                            {cuenta.rol && (
                                <Badge variant="secondary">{cuenta.rol}</Badge>
                            )}

                            {!cuenta.activo && (
                                <Badge variant="destructive">Desactivada</Badge>
                            )}

                            {cuenta.editable && (
                                <div className="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Editar a ${cuenta.name}`}
                                        onClick={() =>
                                            setDialogo({
                                                tipo: 'cuenta',
                                                cuenta,
                                            })
                                        }
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setDialogo({
                                                tipo: 'estado',
                                                cuenta,
                                            })
                                        }
                                    >
                                        {cuenta.activo
                                            ? 'Desactivar'
                                            : 'Activar'}
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </div>

            {dialogo?.tipo === 'cuenta' && (
                <CuentaDialog
                    cuenta={dialogo.cuenta}
                    roles={roles}
                    residentes={residentes}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'parametros' && (
                <ParametrosDialog
                    parametros={parametros}
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}

            {dialogo?.tipo === 'estado' && (
                <ConfirmarDialog
                    titulo={`¿${dialogo.cuenta.activo ? 'Desactivar' : 'Activar'} a ${dialogo.cuenta.name}?`}
                    descripcion={
                        dialogo.cuenta.activo
                            ? 'No podrá iniciar sesión y, si está dentro, saldrá de su sesión. Sus registros se conservan.'
                            : 'Volverá a poder iniciar sesión con su contraseña actual.'
                    }
                    confirmar={dialogo.cuenta.activo ? 'Desactivar' : 'Activar'}
                    variante={dialogo.cuenta.activo ? 'destructive' : 'default'}
                    accion={
                        dialogo.cuenta.activo
                            ? CuentaController.desactivar.form(
                                  dialogo.cuenta.id,
                              )
                            : CuentaController.activar.form(dialogo.cuenta.id)
                    }
                    onClose={cerrar}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}

Usuarios.layout = {
    breadcrumbs: [
        {
            title: 'Usuarios',
            href: CuentaController.index(),
        },
    ],
};
