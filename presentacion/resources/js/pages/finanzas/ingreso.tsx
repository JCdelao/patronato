import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban, Info } from 'lucide-react';
import IngresoController from '@/actions/Patronato/Presentacion/Controllers/IngresoController';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import EstadoMovimiento from '@/components/finanzas/estado-movimiento';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFecha, formatoFechaHora, formatoMonto } from '@/lib/formato';
import type { DetalleIngreso } from '@/types/finanzas';

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function Ingreso({ ingreso }: { ingreso: DetalleIngreso }) {
    return (
        <>
            <Head title={ingreso.concepto} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={IngresoController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Ingresos
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            {ingreso.concepto}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            {ingreso.categoria} · {formatoFecha(ingreso.fecha)}
                        </p>
                    </div>
                    <EstadoMovimiento estado={ingreso.estado} />
                </div>

                {ingreso.anulacion && (
                    <Alert variant="destructive">
                        <Ban />
                        <AlertTitle>Ingreso anulado</AlertTitle>
                        <AlertDescription>
                            {ingreso.anulacion.anulado_por && (
                                <p>
                                    Anuló {ingreso.anulacion.anulado_por}
                                    {ingreso.anulacion.anulado_en &&
                                        ` · ${formatoFechaHora(ingreso.anulacion.anulado_en)}`}
                                </p>
                            )}
                            <p>Motivo: {ingreso.anulacion.motivo}</p>
                        </AlertDescription>
                    </Alert>
                )}

                {ingreso.pago && (
                    <Alert>
                        <Info />
                        <AlertTitle>Viene de un cobro</AlertTitle>
                        <AlertDescription>
                            <p>
                                El sistema lo registró al confirmarse el recibo
                                N.º {ingreso.pago.numero_recibo}. Para anularlo
                                se solicita la anulación del pago.
                            </p>
                            {ingreso.pago.visible && (
                                <Link
                                    href={PagoController.show.url(
                                        ingreso.pago.id,
                                    )}
                                    className="font-medium underline underline-offset-4"
                                >
                                    Ver el pago
                                </Link>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Monto</dt>
                    <dd
                        className={
                            ingreso.anulacion
                                ? 'font-semibold text-muted-foreground line-through'
                                : 'font-semibold'
                        }
                    >
                        L {formatoMonto(ingreso.monto)}
                    </dd>
                    <dt className="text-muted-foreground">
                        N.º de comprobante
                    </dt>
                    <dd className="font-semibold">
                        {ingreso.numero_comprobante ?? '—'}
                    </dd>
                    <dt className="text-muted-foreground">Registrado</dt>
                    <dd className="font-semibold">
                        {ingreso.registrado_por &&
                            `${ingreso.registrado_por} · `}
                        {formatoFechaHora(ingreso.registrado_en)}
                    </dd>
                </dl>

                {ingreso.puede_anular && (
                    <Form
                        {...IngresoController.anular.form(ingreso.id)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 rounded-xl border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div>
                                    <h2 className="font-semibold">
                                        Anular ingreso
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        El ingreso no se borra: queda visible
                                        como anulado y el motivo se guarda en la
                                        bitácora.
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="motivo">Motivo</Label>
                                    <textarea
                                        id="motivo"
                                        name="motivo"
                                        rows={3}
                                        maxLength={1000}
                                        required
                                        className={claseTexto}
                                    />
                                    <InputError message={errors.motivo} />
                                </div>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    className="justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Anular ingreso
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
