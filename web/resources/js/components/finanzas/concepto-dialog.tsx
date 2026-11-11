import { Form } from '@inertiajs/react';
import ConceptoController from '@/actions/Patronato/Presentacion/Controllers/ConceptoController';
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
import type { Concepto } from '@/types/finanzas';

// Devuelve el mes AAAA-MM sumando meses a una fecha AAAA-MM-DD
const mes = (fecha: string, meses = 0) => {
    const valor = new Date(`${fecha}T00:00:00Z`);
    valor.setUTCMonth(valor.getUTCMonth() + meses);

    return valor.toISOString().slice(0, 7);
};

export default function ConceptoDialog({
    concepto,
    periodoActual,
    onClose,
    onGuardado,
}: {
    concepto?: Concepto;
    periodoActual: string;
    onClose: () => void;
    onGuardado: () => void;
}) {
    const ultimo = concepto?.proximos.at(-1) ?? concepto?.actual;

    const accion = concepto
        ? ConceptoController.update.form(concepto.id)
        : ConceptoController.store.form();

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {concepto ? 'Editar concepto' : 'Nuevo concepto'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Nombre, tipo y monto del concepto de cobro
                </DialogDescription>

                <Form
                    {...accion}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onGuardado}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="nombre">Nombre</Label>
                                    <Input
                                        id="nombre"
                                        name="nombre"
                                        defaultValue={concepto?.nombre}
                                        placeholder="Vigilancia"
                                        maxLength={100}
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.nombre} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="tipo">Tipo</Label>
                                    <Select
                                        name="tipo"
                                        defaultValue={
                                            concepto?.tipo ?? 'recurrente'
                                        }
                                    >
                                        <SelectTrigger
                                            id="tipo"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="recurrente">
                                                Recurrente (cada mes)
                                            </SelectItem>
                                            <SelectItem value="unico">
                                                Único (una sola vez)
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.tipo} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="monto">Monto (L.)</Label>
                                    <Input
                                        id="monto"
                                        name="monto"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        defaultValue={ultimo?.monto}
                                        required
                                    />
                                    <InputError message={errors.monto} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="vigente_desde">
                                        Rige desde
                                    </Label>
                                    <Input
                                        id="vigente_desde"
                                        name="vigente_desde"
                                        type="month"
                                        min={mes(periodoActual)}
                                        defaultValue={mes(
                                            periodoActual,
                                            concepto ? 1 : 0,
                                        )}
                                        required
                                    />
                                    <InputError
                                        message={errors.vigente_desde}
                                    />
                                </div>
                            </div>

                            {concepto && (
                                <p className="text-sm text-muted-foreground">
                                    Si cambias el monto, el nuevo regirá desde
                                    el mes que elijas. Los cargos ya generados
                                    conservan su monto.
                                </p>
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
