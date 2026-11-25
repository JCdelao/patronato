import { Form } from '@inertiajs/react';
import ExcepcionAccesoController from '@/actions/Patronato/Presentacion/Controllers/ExcepcionAccesoController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OpcionVivienda } from '@/types/padron';

export default function ExcepcionAccesoSeccion({
    viviendas,
}: {
    viviendas: OpcionVivienda[];
}) {
    return (
        <section className="space-y-4">
            <h2 className="text-2xl font-bold tracking-tight">
                Excepción temporal de acceso
            </h2>
            <Form
                {...ExcepcionAccesoController.store.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-5 rounded-2xl border bg-card p-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="excepcion-vivienda">Vivienda</Label>
                            <select
                                id="excepcion-vivienda"
                                name="vivienda_id"
                                defaultValue=""
                                required
                                className="w-full max-w-xs rounded-md border bg-background p-2 text-sm"
                            >
                                <option value="" disabled>
                                    Selecciona una vivienda
                                </option>
                                {viviendas.map(({ id, codigo }) => (
                                    <option key={id} value={id}>
                                        {codigo}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.vivienda_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="excepcion-vence">
                                Vencimiento (hora de Honduras)
                            </Label>
                            <Input
                                id="excepcion-vence"
                                name="vence_en"
                                type="datetime-local"
                                required
                                className="w-full max-w-xs"
                            />
                            <InputError message={errors.vence_en} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="excepcion-motivo">Motivo</Label>
                            <textarea
                                id="excepcion-motivo"
                                name="motivo"
                                rows={3}
                                required
                                maxLength={1000}
                                className="w-full rounded-md border bg-background p-2 text-sm"
                            />
                            <InputError message={errors.motivo} />
                        </div>
                        <Button
                            disabled={processing}
                            className="justify-self-start"
                        >
                            Conceder excepción
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
