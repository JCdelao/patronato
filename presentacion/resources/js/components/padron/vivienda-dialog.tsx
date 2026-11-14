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
    const [tipo, setTipo] = useState<Vivienda['tipo']>(
        vivienda?.tipo ?? 'casa',
    );
    const esApartamento = tipo === 'apartamento';

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
                                    <Label htmlFor="tipo">Tipo</Label>
                                    <Select
                                        name="tipo"
                                        value={tipo}
                                        onValueChange={(valor) =>
                                            setTipo(valor as Vivienda['tipo'])
                                        }
                                    >
                                        <SelectTrigger
                                            id="tipo"
                                            className="w-full"
                                        >
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
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                {esApartamento ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="edificio_torre">
                                            Edificio o torre
                                        </Label>
                                        <Input
                                            key="edificio_torre"
                                            id="edificio_torre"
                                            name="edificio_torre"
                                            defaultValue={
                                                vivienda?.edificio_torre ?? ''
                                            }
                                            placeholder="Torre B"
                                            maxLength={60}
                                            required
                                        />
                                        <InputError
                                            message={errors.edificio_torre}
                                        />
                                    </div>
                                ) : (
                                    <div className="grid gap-2">
                                        <Label htmlFor="calle_bloque">
                                            Calle o bloque
                                        </Label>
                                        <Input
                                            key="calle_bloque"
                                            id="calle_bloque"
                                            name="calle_bloque"
                                            defaultValue={
                                                vivienda?.calle_bloque ?? ''
                                            }
                                            placeholder="Bloque A"
                                            maxLength={60}
                                            required
                                        />
                                        <InputError
                                            message={errors.calle_bloque}
                                        />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="numero">Número</Label>
                                    <Input
                                        id="numero"
                                        name="numero"
                                        defaultValue={vivienda?.numero ?? ''}
                                        placeholder={
                                            esApartamento ? '304' : '12'
                                        }
                                        maxLength={20}
                                        required
                                    />
                                    <InputError message={errors.numero} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
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
