import { Form } from '@inertiajs/react';
import ResidenteController from '@/actions/Patronato/Modules/Padron/Controllers/ResidenteController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { OpcionVivienda, Residente } from '@/types/padron';

export default function ResidenteDialog({
    residente,
    viviendas,
    onClose,
    onGuardado,
}: {
    residente?: Residente;
    viviendas: OpcionVivienda[];
    onClose: () => void;
    onGuardado: () => void;
}) {
    const accion = residente
        ? ResidenteController.update.form(residente.id)
        : ResidenteController.store.form();

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {residente ? 'Editar residente' : 'Nuevo residente'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Datos del residente y la vivienda donde vive
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
                                <Label htmlFor="vivienda_id">Vivienda</Label>
                                <Select
                                    name="vivienda_id"
                                    defaultValue={
                                        residente
                                            ? String(residente.vivienda_id)
                                            : undefined
                                    }
                                >
                                    <SelectTrigger
                                        id="vivienda_id"
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

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="nombres">Nombre</Label>
                                    <Input
                                        id="nombres"
                                        name="nombres"
                                        defaultValue={residente?.nombres}
                                        maxLength={100}
                                        required
                                    />
                                    <InputError message={errors.nombres} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="apellidos">Apellido</Label>
                                    <Input
                                        id="apellidos"
                                        name="apellidos"
                                        defaultValue={residente?.apellidos}
                                        maxLength={100}
                                        required
                                    />
                                    <InputError message={errors.apellidos} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="identidad">Identidad</Label>
                                <Input
                                    id="identidad"
                                    name="identidad"
                                    defaultValue={residente?.identidad ?? ''}
                                    maxLength={30}
                                />
                                <InputError message={errors.identidad} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="telefono">Teléfono</Label>
                                    <Input
                                        id="telefono"
                                        name="telefono"
                                        type="tel"
                                        defaultValue={residente?.telefono ?? ''}
                                        maxLength={30}
                                    />
                                    <InputError message={errors.telefono} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="correo">Correo</Label>
                                    <Input
                                        id="correo"
                                        name="correo"
                                        type="email"
                                        defaultValue={residente?.correo ?? ''}
                                        maxLength={255}
                                    />
                                    <InputError message={errors.correo} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tipo_residente">Tipo</Label>
                                <Select
                                    name="tipo"
                                    defaultValue={
                                        residente?.tipo ?? 'propietario'
                                    }
                                >
                                    <SelectTrigger
                                        id="tipo_residente"
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
                                    id="es_responsable_pago"
                                    name="es_responsable_pago"
                                    value="1"
                                    defaultChecked={
                                        residente?.es_responsable_pago
                                    }
                                />
                                <Label htmlFor="es_responsable_pago">
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
