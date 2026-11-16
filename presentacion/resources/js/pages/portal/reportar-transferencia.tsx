import { Form, Head } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import PortalTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/PortalTransferenciaController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type OpcionVivienda = {
    id: number;
    codigo: string;
};

const claseSelect =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function ReportarTransferencia({
    viviendas,
    hoy,
    tamanoMaximoMb,
}: {
    viviendas: OpcionVivienda[];
    hoy: string;
    tamanoMaximoMb: number;
}) {
    return (
        <>
            <Head title="Reportar transferencia" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Reportar transferencia
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Avisa a tesorería de una transferencia que hiciste
                    </p>
                </div>

                <Alert>
                    <CircleAlert />
                    <AlertTitle>Queda pendiente de verificación</AlertTitle>
                    <AlertDescription>
                        No reduce el saldo de la vivienda hasta que tesorería
                        confirme que el dinero ingresó a la cuenta.
                    </AlertDescription>
                </Alert>

                {viviendas.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-muted-foreground">
                        No tienes una vivienda activa registrada. Comunícate con
                        el patronato.
                    </p>
                ) : (
                    <Form
                        {...PortalTransferenciaController.store.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {viviendas.length === 1 ? (
                                    <div className="grid gap-2">
                                        <Label>Vivienda</Label>
                                        <p className="font-semibold">
                                            {viviendas[0].codigo}
                                        </p>
                                        <input
                                            type="hidden"
                                            name="vivienda_id"
                                            value={viviendas[0].id}
                                        />
                                        <InputError
                                            message={errors.vivienda_id}
                                        />
                                    </div>
                                ) : (
                                    <div className="grid gap-2">
                                        <Label htmlFor="vivienda_id">
                                            Vivienda (obligatoria)
                                        </Label>
                                        <select
                                            id="vivienda_id"
                                            name="vivienda_id"
                                            required
                                            defaultValue=""
                                            className={claseSelect}
                                        >
                                            <option value="" disabled>
                                                Elige la vivienda
                                            </option>
                                            {viviendas.map((vivienda) => (
                                                <option
                                                    key={vivienda.id}
                                                    value={vivienda.id}
                                                >
                                                    {vivienda.codigo}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.vivienda_id}
                                        />
                                    </div>
                                )}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="monto">
                                            Monto transferido (obligatorio)
                                        </Label>
                                        <Input
                                            id="monto"
                                            name="monto"
                                            type="number"
                                            inputMode="decimal"
                                            step="0.01"
                                            min="0.01"
                                            required
                                        />
                                        <InputError message={errors.monto} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="fecha_pago">
                                            Fecha de la transferencia
                                            (obligatoria)
                                        </Label>
                                        <Input
                                            id="fecha_pago"
                                            name="fecha_pago"
                                            type="date"
                                            max={hoy}
                                            defaultValue={hoy}
                                            required
                                        />
                                        <InputError
                                            message={errors.fecha_pago}
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="banco">
                                        Banco (obligatorio)
                                    </Label>
                                    <Input
                                        id="banco"
                                        name="banco"
                                        maxLength={100}
                                        required
                                    />
                                    <InputError message={errors.banco} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="numero_referencia">
                                        Número de referencia (obligatorio)
                                    </Label>
                                    <Input
                                        id="numero_referencia"
                                        name="numero_referencia"
                                        maxLength={60}
                                        required
                                    />
                                    <InputError
                                        message={errors.numero_referencia}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="cuenta_receptora">
                                        Cuenta receptora (opcional)
                                    </Label>
                                    <Input
                                        id="cuenta_receptora"
                                        name="cuenta_receptora"
                                        maxLength={60}
                                    />
                                    <InputError
                                        message={errors.cuenta_receptora}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="comprobante">
                                        Comprobante (obligatorio)
                                    </Label>
                                    <Input
                                        id="comprobante"
                                        name="comprobante"
                                        type="file"
                                        accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                                        required
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Imagen JPG o PNG, o PDF, de hasta{' '}
                                        {tamanoMaximoMb} MB.
                                    </p>
                                    <InputError message={errors.comprobante} />
                                </div>

                                <Button
                                    className="h-12 w-full text-base"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Reportar transferencia
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
