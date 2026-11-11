import { Form } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

export default function ConfirmarDialog({
    titulo,
    descripcion,
    confirmar,
    variante = 'destructive',
    accion,
    onClose,
    onGuardado,
}: {
    titulo: string;
    descripcion: string;
    confirmar: string;
    variante?: 'default' | 'destructive';
    accion: Pick<ComponentProps<typeof Form>, 'action' | 'method'>;
    onClose: () => void;
    onGuardado: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {titulo}
                </DialogTitle>
                <DialogDescription>{descripcion}</DialogDescription>

                <Form
                    {...accion}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onGuardado}
                    className="flex justify-end gap-2"
                >
                    {({ processing }) => (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Cancelar
                            </Button>
                            <Button variant={variante} disabled={processing}>
                                {processing && <Spinner />}
                                {confirmar}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
