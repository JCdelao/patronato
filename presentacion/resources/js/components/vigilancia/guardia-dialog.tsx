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
import type { Guardia, Turno } from '@/types/vigilancia';
import { TURNOS } from '@/types/vigilancia';

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// Registra un guardia o corrige uno activo
export default function GuardiaDialog({
    guardia,
    turnos,
    onClose,
}: {
    guardia?: Guardia;
    turnos: Turno[];
    onClose: () => void;
}) {
    const accion = guardia
        ? GuardiaController.update.form(guardia.id)
        : GuardiaController.store.form();

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {guardia ? `Editar ${guardia.nombre}` : 'Agregar guardia'}
                </DialogTitle>
                <DialogDescription>
                    El turno es el habitual del guardia. Las fechas de cada
                    turno se programan en el calendario.
                </DialogDescription>
                <Form
                    {...accion}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="nombre">Nombre</Label>
                                <Input
                                    id="nombre"
                                    name="nombre"
                                    defaultValue={guardia?.nombre}
                                    maxLength={150}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.nombre} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="identidad">
                                        Número de identidad
                                    </Label>
                                    <Input
                                        id="identidad"
                                        name="identidad"
                                        defaultValue={guardia?.identidad}
                                        maxLength={30}
                                        placeholder="0801-1990-12345"
                                        required
                                    />
                                    <InputError message={errors.identidad} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="telefono">Teléfono</Label>
                                    <Input
                                        id="telefono"
                                        name="telefono"
                                        type="tel"
                                        defaultValue={guardia?.telefono ?? ''}
                                        maxLength={30}
                                        placeholder="9999-9999"
                                        required
                                    />
                                    <InputError message={errors.telefono} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="turno">Turno</Label>
                                <select
                                    id="turno"
                                    name="turno"
                                    defaultValue={guardia?.turno ?? 'dia'}
                                    className={claseSelect}
                                >
                                    {turnos.map((turno) => (
                                        <option key={turno} value={turno}>
                                            {TURNOS[turno]}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.turno} />
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
