import { Form } from '@inertiajs/react';
import { useState } from 'react';
import CuentaController from '@/actions/Patronato/Modules/Identidad/Controllers/CuentaController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Cuenta, ResidenteOpcion, RolOpcion } from '@/types/identidad';

export default function CuentaDialog({
    cuenta,
    roles,
    residentes,
    onClose,
    onGuardado,
}: {
    cuenta?: Cuenta;
    roles: RolOpcion[];
    residentes: ResidenteOpcion[];
    onClose: () => void;
    onGuardado: () => void;
}) {
    const [rolId, setRolId] = useState(cuenta?.rol_id?.toString());

    const accion = cuenta
        ? CuentaController.update.form(cuenta.id)
        : CuentaController.store.form();

    const esResidente =
        roles.find((rol) => rol.id.toString() === rolId)?.codigo ===
        'residente';

    const disponibles = residentes.filter(
        (residente) =>
            residente.user_id === null || residente.user_id === cuenta?.id,
    );

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {cuenta ? 'Editar cuenta' : 'Nueva cuenta'}
                </DialogTitle>
                <DialogDescription>
                    {cuenta
                        ? 'Cambia los datos de acceso de esta persona.'
                        : 'Se generará una contraseña temporal que debe cambiar en su primer inicio de sesión.'}
                </DialogDescription>

                <Form
                    {...accion}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onGuardado}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={cuenta?.name}
                                    maxLength={255}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Correo</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    defaultValue={cuenta?.email}
                                    maxLength={255}
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="rol_id">Rol</Label>
                                <Select
                                    name="rol_id"
                                    value={rolId}
                                    onValueChange={setRolId}
                                >
                                    <SelectTrigger
                                        id="rol_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Seleccionar" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((rol) => (
                                            <SelectItem
                                                key={rol.id}
                                                value={rol.id.toString()}
                                            >
                                                {rol.nombre}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.rol_id} />
                            </div>

                            {esResidente && (
                                <div className="grid gap-2">
                                    <Label htmlFor="residente_id">
                                        Residente
                                    </Label>
                                    <Select
                                        name="residente_id"
                                        defaultValue={cuenta?.residente_id?.toString()}
                                    >
                                        <SelectTrigger
                                            id="residente_id"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Seleccionar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {disponibles.map((residente) => (
                                                <SelectItem
                                                    key={residente.id}
                                                    value={residente.id.toString()}
                                                >
                                                    {residente.nombre}
                                                    {residente.vivienda &&
                                                        ` · ${residente.vivienda}`}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.residente_id} />
                                </div>
                            )}

                            <Button className="w-full" disabled={processing}>
                                {processing && <Spinner />}
                                Guardar
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
