import { Form } from '@inertiajs/react';
import ReglaAccesoPagoController from '@/actions/Patronato/Presentacion/Controllers/ReglaAccesoPagoController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type ReglaAccesoPago = {
    acceso_depende_pago: boolean;
    meses_tolerancia_acceso: number;
    pendiente_cuenta_al_dia: boolean;
};

export default function ReglaAccesoPagoSeccion({
    regla,
}: {
    regla: ReglaAccesoPago;
}) {
    return (
        <section className="space-y-4">
            <h2 className="text-2xl font-bold tracking-tight">
                Regla de acceso por estado de pago
            </h2>
            <Form
                {...ReglaAccesoPagoController.update.form()}
                options={{ preserveScroll: true }}
                className="grid gap-5 rounded-2xl border bg-card p-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="acceso_depende_pago">
                                Estado de la regla
                            </Label>
                            <select
                                id="acceso_depende_pago"
                                name="acceso_depende_pago"
                                defaultValue={
                                    regla.acceso_depende_pago ? '1' : '0'
                                }
                                className="w-full max-w-xs rounded-md border bg-background p-2 text-sm"
                            >
                                <option value="0">Desactivada</option>
                                <option value="1">Activada</option>
                            </select>
                            <InputError message={errors.acceso_depende_pago} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="meses_tolerancia_acceso">
                                Meses de tolerancia para el ingreso
                            </Label>
                            <Input
                                id="meses_tolerancia_acceso"
                                name="meses_tolerancia_acceso"
                                type="number"
                                min={0}
                                max={12}
                                required
                                defaultValue={regla.meses_tolerancia_acceso}
                                className="w-28"
                            />
                            <InputError
                                message={errors.meses_tolerancia_acceso}
                            />
                            <p className="text-sm text-muted-foreground">
                                Se aplica solo al escaneo de acceso. Usa los
                                días de gracia de la regla de mora.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pendiente_cuenta_al_dia">
                                Contar pagos pendientes de verificación
                            </Label>
                            <select
                                id="pendiente_cuenta_al_dia"
                                name="pendiente_cuenta_al_dia"
                                defaultValue={
                                    regla.pendiente_cuenta_al_dia ? '1' : '0'
                                }
                                className="w-full max-w-xs rounded-md border bg-background p-2 text-sm"
                            >
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                            <InputError
                                message={errors.pendiente_cuenta_al_dia}
                            />
                            <p className="text-sm text-muted-foreground">
                                Se cuentan para el acceso los meses que esos
                                pagos cubrirían, empezando por el más antiguo.
                            </p>
                        </div>
                        <Button
                            disabled={processing}
                            className="justify-self-start"
                        >
                            Guardar regla de acceso
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
