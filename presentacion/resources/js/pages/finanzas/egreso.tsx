import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban, Paperclip } from 'lucide-react';
import EgresoController from '@/actions/Patronato/Presentacion/Controllers/EgresoController';
import EstadoMovimiento from '@/components/finanzas/estado-movimiento';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    FORMAS_PAGO,
    formatoFecha,
    formatoFechaHora,
    formatoMonto,
} from '@/lib/formato';
import type { DetalleEgreso } from '@/types/finanzas';

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function Egreso({ egreso }: { egreso: DetalleEgreso }) {
    return (
        <>
            <Head title={egreso.concepto} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={EgresoController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Egresos
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            {egreso.concepto}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            {egreso.categoria} · {formatoFecha(egreso.fecha)}
                        </p>
                    </div>
                    <EstadoMovimiento estado={egreso.estado} />
                </div>

                {egreso.anulacion && (
                    <Alert variant="destructive">
                        <Ban />
                        <AlertTitle>Egreso anulado</AlertTitle>
                        <AlertDescription>
                            {egreso.anulacion.anulado_por && (
                                <p>
                                    Anuló {egreso.anulacion.anulado_por}
                                    {egreso.anulacion.anulado_en &&
                                        ` · ${formatoFechaHora(egreso.anulacion.anulado_en)}`}
                                </p>
                            )}
                            <p>Motivo: {egreso.anulacion.motivo}</p>
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Monto</dt>
                    <dd
                        className={
                            egreso.anulacion
                                ? 'font-semibold text-muted-foreground line-through'
                                : 'font-semibold'
                        }
                    >
                        L {formatoMonto(egreso.monto)}
                    </dd>
                    <dt className="text-muted-foreground">Proveedor</dt>
                    <dd className="font-semibold">{egreso.proveedor ?? '—'}</dd>
                    <dt className="text-muted-foreground">Forma de pago</dt>
                    <dd className="font-semibold">
                        {egreso.forma_pago
                            ? (FORMAS_PAGO[egreso.forma_pago] ??
                              egreso.forma_pago)
                            : '—'}
                    </dd>
                    <dt className="text-muted-foreground">
                        N.º de comprobante
                    </dt>
                    <dd className="font-semibold">
                        {egreso.numero_comprobante}
                    </dd>
                    <dt className="text-muted-foreground">Registrado</dt>
                    <dd className="font-semibold">
                        {egreso.registrado_por && `${egreso.registrado_por} · `}
                        {formatoFechaHora(egreso.registrado_en)}
                    </dd>
                </dl>

                {egreso.tiene_factura && (
                    <Button variant="outline" asChild>
                        <a
                            href={EgresoController.factura.url(egreso.id)}
                            target="_blank"
                            rel="noopener"
                        >
                            <Paperclip />
                            Ver factura
                        </a>
                    </Button>
                )}

                {egreso.puede_anular && (
                    <Form
                        {...EgresoController.anular.form(egreso.id)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 rounded-xl border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div>
                                    <h2 className="font-semibold">
                                        Anular egreso
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        El egreso no se borra: queda visible
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
                                    Anular egreso
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
