import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ViviendaController from '@/actions/Patronato/Presentacion/Controllers/ViviendaController';
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
import type {
    OpcionTipoVivienda,
    OpcionVivienda,
    Vivienda,
} from '@/types/padron';

// Valor del selector cuando la vivienda no pertenece a otra
const NINGUNA = 'ninguna';

export default function ViviendaDialog({
    vivienda,
    tipos,
    viviendas,
    onClose,
    onGuardado,
}: {
    vivienda?: Vivienda;
    tipos: OpcionTipoVivienda[];
    viviendas: OpcionVivienda[];
    onClose: () => void;
    onGuardado: () => void;
}) {
    const [padre, setPadre] = useState(
        vivienda?.vivienda_padre_id
            ? String(vivienda.vivienda_padre_id)
            : NINGUNA,
    );

    // Solo hay un nivel: se puede pertenecer a una vivienda que no pertenece a otra
    const posiblesPadres = viviendas.filter(
        (opcion) =>
            opcion.vivienda_padre_id === null && opcion.id !== vivienda?.id,
    );

    const accion = vivienda
        ? ViviendaController.update.form(vivienda.id)
        : ViviendaController.store.form();

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {vivienda ? 'Editar vivienda' : 'Nueva vivienda'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Datos de la vivienda
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
                                    <Label htmlFor="tipo_vivienda_id">
                                        Tipo
                                    </Label>
                                    <Select
                                        name="tipo_vivienda_id"
                                        defaultValue={
                                            vivienda
                                                ? String(
                                                      vivienda.tipo_vivienda_id,
                                                  )
                                                : undefined
                                        }
                                    >
                                        <SelectTrigger
                                            id="tipo_vivienda_id"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Elige el tipo" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {vivienda &&
                                                !tipos.some(
                                                    (tipo) =>
                                                        tipo.id ===
                                                        vivienda.tipo_vivienda_id,
                                                ) && (
                                                    <SelectItem
                                                        value={String(
                                                            vivienda.tipo_vivienda_id,
                                                        )}
                                                    >
                                                        {vivienda.tipo}{' '}
                                                        (inactivo)
                                                    </SelectItem>
                                                )}
                                            {tipos.map((tipo) => (
                                                <SelectItem
                                                    key={tipo.id}
                                                    value={String(tipo.id)}
                                                >
                                                    {tipo.nombre}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.tipo_vivienda_id}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="vivienda_padre_id">
                                    Pertenece a (opcional)
                                </Label>
                                <Select value={padre} onValueChange={setPadre}>
                                    <SelectTrigger
                                        id="vivienda_padre_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NINGUNA}>
                                            Ninguna
                                        </SelectItem>
                                        {posiblesPadres.map((opcion) => (
                                            <SelectItem
                                                key={opcion.id}
                                                value={String(opcion.id)}
                                            >
                                                {opcion.codigo}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="vivienda_padre_id"
                                    value={padre === NINGUNA ? '' : padre}
                                />
                                <p className="text-sm text-muted-foreground">
                                    Por ejemplo, una habitación que pertenece a
                                    un apartamento. Cada una paga su propia
                                    cuota.
                                </p>
                                <InputError
                                    message={errors.vivienda_padre_id}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="calle_bloque">
                                        Calle o bloque
                                    </Label>
                                    <Input
                                        id="calle_bloque"
                                        name="calle_bloque"
                                        defaultValue={
                                            vivienda?.calle_bloque ?? ''
                                        }
                                        placeholder="Bloque A"
                                        maxLength={60}
                                    />
                                    <InputError message={errors.calle_bloque} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="edificio_torre">
                                        Edificio o torre
                                    </Label>
                                    <Input
                                        id="edificio_torre"
                                        name="edificio_torre"
                                        defaultValue={
                                            vivienda?.edificio_torre ?? ''
                                        }
                                        placeholder="Torre B"
                                        maxLength={60}
                                    />
                                    <InputError
                                        message={errors.edificio_torre}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="numero">Número</Label>
                                    <Input
                                        id="numero"
                                        name="numero"
                                        defaultValue={vivienda?.numero ?? ''}
                                        placeholder="304"
                                        maxLength={20}
                                        required
                                    />
                                    <InputError message={errors.numero} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="estado_ocupacion">
                                        Ocupación
                                    </Label>
                                    <Select
                                        name="estado_ocupacion"
                                        defaultValue={
                                            vivienda?.estado_ocupacion ??
                                            'ocupada'
                                        }
                                    >
                                        <SelectTrigger
                                            id="estado_ocupacion"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="ocupada">
                                                Ocupada
                                            </SelectItem>
                                            <SelectItem value="desocupada">
                                                Desocupada
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.estado_ocupacion}
                                    />
                                </div>
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
