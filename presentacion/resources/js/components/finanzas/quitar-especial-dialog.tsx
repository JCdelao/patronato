import { Form } from '@inertiajs/react';
import TarifaEspecialController from '@/actions/Patronato/Presentacion/Controllers/TarifaEspecialController';
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
import type { TarifaEspecial } from '@/types/finanzas';

// Devuelve el mes AAAA-MM sumando meses a una fecha AAAA-MM-DD
const mes = (fecha: string, meses = 0) => {
    const valor = new Date(`${fecha}T00:00:00Z`);
    valor.setUTCMonth(valor.getUTCMonth() + meses);

    return valor.toISOString().slice(0, 7);
};

export default function QuitarEspecialDialog({
    especial,
    periodoActual,
    onClose,
    onGuardado,
}: {
    especial: TarifaEspecial;
    periodoActual: string;
    onClose: () => void;
    onGuardado: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    ¿Quitar la cuota especial de {especial.codigo}?
                </DialogTitle>
                <DialogDescription>
                    Desde el mes que elijas, la vivienda pagará la cuota de su
                    tipo. Los cargos ya generados no cambian.
                </DialogDescription>

                <Form
                    {...TarifaEspecialController.quitar.form(
                        especial.vivienda_id,
                    )}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onGuardado}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="quitar_desde">
                                    Paga la cuota de su tipo desde
                                </Label>
                                <Input
                                    id="quitar_desde"
                                    name="vigente_desde"
                                    type="month"
                                    min={mes(periodoActual)}
                                    defaultValue={mes(periodoActual, 1)}
                                    required
                                />
                                <InputError message={errors.vigente_desde} />
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
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Quitar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
