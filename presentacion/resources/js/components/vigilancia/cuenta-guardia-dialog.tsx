import { Form } from '@inertiajs/react';
import GuardiaController from '@/actions/Patronato/Presentacion/Controllers/GuardiaController';
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
import type { Guardia } from '@/types/vigilancia';

// Crea la cuenta del guardia con el rol guardia y una contraseña temporal
export default function CuentaGuardiaDialog({
    guardia,
    onClose,
}: {
    guardia: Guardia;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-md">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Crear cuenta de {guardia.nombre}
                </DialogTitle>
                <DialogDescription>
                    La cuenta tendrá el rol guardia. Se envía una contraseña
                    temporal al correo, que debe cambiar al entrar por primera
                    vez.
                </DialogDescription>
                <Form
                    {...GuardiaController.crearCuenta.form(guardia.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Correo</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    maxLength={255}
                                    placeholder="guardia@correo.com"
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.email} />
                            </div>
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Crear cuenta
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
