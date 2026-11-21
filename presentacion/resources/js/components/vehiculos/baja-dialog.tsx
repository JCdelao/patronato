import { Form } from '@inertiajs/react';
import VehiculoController from '@/actions/Patronato/Presentacion/Controllers/VehiculoController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Vehiculo } from '@/types/vehiculos';
import { identificador } from '@/types/vehiculos';

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// Da de baja un vehículo con su motivo; el registro se conserva
export default function BajaDialog({
    vehiculo,
    onClose,
}: {
    vehiculo: Vehiculo;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-md">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Dar de baja {identificador(vehiculo)}
                </DialogTitle>
                <DialogDescription>
                    El vehículo deja de estar activo y su placa queda libre. El
                    registro y el motivo se conservan en la bitácora.
                </DialogDescription>
                <Form
                    {...VehiculoController.baja.form(vehiculo.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="motivo">Motivo</Label>
                                <textarea
                                    id="motivo"
                                    name="motivo"
                                    rows={3}
                                    maxLength={1000}
                                    placeholder="Se vendió el vehículo"
                                    required
                                    autoFocus
                                    className={claseTexto}
                                />
                                <InputError message={errors.motivo} />
                            </div>
                            <Button variant="destructive" disabled={processing}>
                                {processing && <Spinner />}
                                Dar de baja
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
