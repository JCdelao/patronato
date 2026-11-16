import { Form, Head, router } from '@inertiajs/react';
import { CircleAlert, Search, X } from 'lucide-react';
import { useRef } from 'react';
import TransferenciaController from '@/actions/Patronato/Presentacion/Controllers/TransferenciaController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type OpcionVivienda = {
    id: number;
    codigo: string;
    responsable: string | null;
};

export default function Transferencia({
    buscar,
    viviendas,
    vivienda,
    hoy,
    tamanoMaximoMb,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
    vivienda: OpcionVivienda | null;
    hoy: string;
    tamanoMaximoMb: number;
}) {
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                TransferenciaController.create.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    const elegirVivienda = (id: number | null) =>
        router.get(
            TransferenciaController.create.url(),
            id === null ? {} : { vivienda: id },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Registrar transferencia" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Registrar transferencia
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Pago por transferencia bancaria
                    </p>
                </div>

                <Alert>
                    <CircleAlert />
                    <AlertTitle>Queda pendiente de verificación</AlertTitle>
                    <AlertDescription>
                        No reduce el saldo de la vivienda hasta que se confirme
                        que el dinero ingresó a la cuenta.
                    </AlertDescription>
                </Alert>

                {vivienda === null ? (
                    <div className="space-y-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                aria-label="Buscar vivienda"
                                placeholder="Código de vivienda o responsable"
                                defaultValue={buscar}
                                onChange={(evento) =>
                                    alBuscar(evento.target.value)
                                }
                                className="h-12 rounded-full pl-11"
                            />
                        </div>

                        {buscar !== '' && viviendas.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No se encontraron viviendas activas.
                            </p>
                        )}

                        <div className="grid gap-2">
                            {viviendas.map((opcion) => (
                                <button
                                    key={opcion.id}
                                    type="button"
                                    onClick={() => elegirVivienda(opcion.id)}
                                    className="rounded-xl border p-4 text-left hover:bg-muted"
                                >
                                    <span className="block font-semibold">
                                        {opcion.codigo}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {opcion.responsable ??
                                            'Sin responsable de pago'}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between rounded-xl border p-4">
                            <div>
                                <p className="font-semibold">
                                    {vivienda.codigo}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {vivienda.responsable ??
                                        'Sin responsable de pago'}
                                </p>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Cambiar vivienda"
                                onClick={() => elegirVivienda(null)}
                            >
                                <X />
                            </Button>
                        </div>

                        <Form
                            {...TransferenciaController.store.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="vivienda_id"
                                        value={vivienda.id}
                                    />

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
                                            <InputError
                                                message={errors.monto}
                                            />
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
                                            Cuenta receptora (obligatoria)
                                        </Label>
                                        <Input
                                            id="cuenta_receptora"
                                            name="cuenta_receptora"
                                            maxLength={60}
                                            required
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
                                        <InputError
                                            message={errors.comprobante}
                                        />
                                    </div>

                                    <InputError message={errors.vivienda_id} />

                                    <Button
                                        className="h-12 w-full text-base"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        Registrar transferencia
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}
            </div>
        </>
    );
}
