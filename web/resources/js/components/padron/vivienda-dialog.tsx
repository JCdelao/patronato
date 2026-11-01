import { Form } from '@inertiajs/react';
import ViviendaController from '@/actions/Patronato/Modules/Padron/Controllers/ViviendaController';
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
import type { Vivienda } from '@/types/padron';

export default function ViviendaDialog({
    vivienda,
    onClose,
    onGuardado,
}: {
    vivienda?: Vivienda;
    onClose: () => void;
    onGuardado: () => void;
}) {
    const accion = vivienda
        ? ViviendaController.update.form(vivienda.id)
        : ViviendaController.store.form();

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {vivienda ? 'Editar vivienda' : 'Nueva vivienda'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Datos de la vivienda y su cuota mensual
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
                                <Label htmlFor="codigo">Código</Label>
                                <Input
                                    id="codigo"
                                    name="codigo"
                                    defaultValue={vivienda?.codigo}
                                    placeholder="A-03"
                                    maxLength={30}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.codigo} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="direccion">Dirección</Label>
                                <Input
                                    id="direccion"
                                    name="direccion"
                                    defaultValue={vivienda?.direccion ?? ''}
                                    maxLength={60}
                                    required
                                />
                                <InputError message={errors.direccion} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Tipo</Label>
                                <Select
                                    name="tipo"
                                    defaultValue={vivienda?.tipo ?? 'casa'}
                                >
                                    <SelectTrigger id="tipo" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="casa">
                                            Casa
                                        </SelectItem>
                                        <SelectItem value="apartamento">
                                            Apartamento
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.tipo} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="cuota">
                                    Cuota mensual (L.)
                                </Label>
                                <Input
                                    id="cuota"
                                    name="cuota"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    defaultValue={vivienda?.cuota ?? '500'}
                                    required
                                />
                                <InputError message={errors.cuota} />
                            </div>

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
