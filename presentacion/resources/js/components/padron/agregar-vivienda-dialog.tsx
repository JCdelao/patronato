import { Form } from '@inertiajs/react';
import ResidenteController from '@/actions/Patronato/Presentacion/Controllers/ResidenteController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { OpcionVivienda } from '@/types/padron';

export default function AgregarViviendaDialog({
    residente,
    titulo,
    descripcion,
    viviendas,
    onClose,
    onGuardado,
}: {
    residente: { id: number };
    titulo: string;
    descripcion: string;
    viviendas: OpcionVivienda[];
    onClose: () => void;
    onGuardado: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-lg">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {titulo}
                </DialogTitle>
                <DialogDescription>{descripcion}</DialogDescription>

                <Form
                    {...ResidenteController.agregarVivienda.form(residente.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onGuardado}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="agregar_vivienda_id">
                                    Vivienda
                                </Label>
                                <Select name="vivienda_id">
                                    <SelectTrigger
                                        id="agregar_vivienda_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Seleccionar" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {viviendas.map((vivienda) => (
                                            <SelectItem
                                                key={vivienda.id}
                                                value={String(vivienda.id)}
                                            >
                                                {vivienda.codigo}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.vivienda_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="agregar_tipo">Tipo</Label>
                                <Select name="tipo" defaultValue="propietario">
                                    <SelectTrigger
                                        id="agregar_tipo"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="propietario">
                                            Propietario
                                        </SelectItem>
                                        <SelectItem value="inquilino">
                                            Inquilino
                                        </SelectItem>
                                        <SelectItem value="familiar">
                                            Familiar
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.tipo} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="agregar_responsable"
                                    name="es_responsable_pago"
                                    value="1"
                                />
                                <Label htmlFor="agregar_responsable">
                                    Responsable de pago
                                </Label>
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
