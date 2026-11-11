import { Form } from '@inertiajs/react';
import CuentaController from '@/actions/Patronato/Presentacion/Controllers/CuentaController';
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
import { Spinner } from '@/components/ui/spinner';
import type { ParametrosAcceso } from '@/types/identidad';

const CAMPOS: {
    nombre: keyof ParametrosAcceso;
    etiqueta: string;
    min: number;
    max: number;
}[] = [
    {
        nombre: 'max_intentos_login',
        etiqueta: 'Intentos fallidos antes de bloquear',
        min: 1,
        max: 20,
    },
    {
        nombre: 'minutos_bloqueo_login',
        etiqueta: 'Minutos de bloqueo',
        min: 1,
        max: 1440,
    },
    {
        nombre: 'minutos_inactividad_sesion',
        etiqueta: 'Minutos de inactividad antes de cerrar sesión',
        min: 5,
        max: 1440,
    },
];

export default function ParametrosDialog({
    parametros,
    onClose,
    onGuardado,
}: {
    parametros: ParametrosAcceso;
    onClose: () => void;
    onGuardado: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Parámetros de acceso
                </DialogTitle>
                <DialogDescription>
                    Se aplican a todas las cuentas de este residencial.
                </DialogDescription>

                <Form
                    {...CuentaController.parametros.form()}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onGuardado}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {CAMPOS.map((campo) => (
                                <div key={campo.nombre} className="grid gap-2">
                                    <Label htmlFor={campo.nombre}>
                                        {campo.etiqueta}
                                    </Label>
                                    <Input
                                        id={campo.nombre}
                                        name={campo.nombre}
                                        type="number"
                                        min={campo.min}
                                        max={campo.max}
                                        defaultValue={parametros[campo.nombre]}
                                        required
                                    />
                                    <InputError
                                        message={errors[campo.nombre]}
                                    />
                                </div>
                            ))}

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
