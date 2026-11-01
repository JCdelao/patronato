import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CuentaController from '@/actions/Patronato/Modules/Identidad/Controllers/CuentaController';
import PermisoController from '@/actions/Patronato/Modules/Identidad/Controllers/PermisoController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

type Rol = { id: number; nombre: string; permisos: string[] };
type Modulo = { id: string; etiqueta: string; acciones: string[] };

const ACCIONES: Record<string, string> = {
    ver: 'Ver',
    registrar: 'Registrar',
    verificar: 'Verificar',
    aprobar: 'Aprobar',
    anular: 'Anular',
};

export default function Permisos({
    roles,
    modulos,
}: {
    roles: Rol[];
    modulos: Modulo[];
}) {
    const [rolId, setRolId] = useState(roles[0]?.id.toString());
    const rol = roles.find((opcion) => opcion.id.toString() === rolId);

    return (
        <>
            <Head title="Permisos" />

            <div className="space-y-6 p-6 md:p-10">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-4xl font-bold tracking-tight">
                            Permisos
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            Qué puede hacer cada rol en este residencial
                        </p>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={CuentaController.index()}>
                            Volver a Usuarios
                        </Link>
                    </Button>
                </div>

                <div className="grid max-w-sm gap-2">
                    <Label htmlFor="rol">Rol</Label>
                    <Select value={rolId} onValueChange={setRolId}>
                        <SelectTrigger id="rol" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((opcion) => (
                                <SelectItem
                                    key={opcion.id}
                                    value={opcion.id.toString()}
                                >
                                    {opcion.nombre}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {rol && (
                    <Form
                        key={`${rol.id}:${rol.permisos.join()}`}
                        {...PermisoController.update.form(rol.id)}
                        options={{ preserveScroll: true, preserveState: true }}
                        onSuccess={() => router.reload({ only: ['roles'] })}
                        className="space-y-6"
                    >
                        {({ processing }) => (
                            <>
                                <ul className="divide-y rounded-2xl border bg-card">
                                    {modulos.map((modulo) => (
                                        <li
                                            key={modulo.id}
                                            className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 p-4"
                                        >
                                            <p className="font-medium">
                                                {modulo.etiqueta}
                                            </p>
                                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                                {modulo.acciones.map(
                                                    (accion) => {
                                                        const permiso = `${modulo.id}.${accion}`;

                                                        return (
                                                            <div
                                                                key={permiso}
                                                                className="flex items-center gap-2"
                                                            >
                                                                <Checkbox
                                                                    id={permiso}
                                                                    name="permisos[]"
                                                                    value={
                                                                        permiso
                                                                    }
                                                                    defaultChecked={rol.permisos.includes(
                                                                        permiso,
                                                                    )}
                                                                />
                                                                <Label
                                                                    htmlFor={
                                                                        permiso
                                                                    }
                                                                >
                                                                    {
                                                                        ACCIONES[
                                                                            accion
                                                                        ]
                                                                    }
                                                                </Label>
                                                            </div>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>

                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Guardar permisos
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

Permisos.layout = {
    breadcrumbs: [
        {
            title: 'Permisos',
            href: PermisoController.index(),
        },
    ],
};
