import { Form } from '@inertiajs/react';
import ResidencialController from '@/actions/Patronato/Presentacion/Controllers/ResidencialController';
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

export default function EstadoResidencialDialog({
    residencial,
    onClose,
    onGuardado,
}: {
    residencial: { id: number; nombre: string; estado: string };
    onClose: () => void;
    onGuardado: () => void;
}) {
    const suspender = residencial.estado !== 'suspendido';
    const accion = suspender
        ? ResidencialController.suspender.form(residencial.id)
        : ResidencialController.reactivar.form(residencial.id);

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {suspender ? 'Suspender' : 'Reactivar'} {residencial.nombre}
                </DialogTitle>
                <DialogDescription>
                    {suspender
                        ? 'Sus usuarios no podrán entrar, y quienes estén dentro saldrán de su sesión. Sus datos se conservan.'
                        : 'Sus usuarios volverán a entrar con todos sus datos intactos.'}
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
                                <Label htmlFor="motivo">Motivo</Label>
                                <Input
                                    id="motivo"
                                    name="motivo"
                                    maxLength={500}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.motivo} />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    variant={
                                        suspender ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {suspender ? 'Suspender' : 'Reactivar'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
