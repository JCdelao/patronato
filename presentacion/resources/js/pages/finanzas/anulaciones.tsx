import { Form, Head } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import AnulacionController from '@/actions/Patronato/Presentacion/Controllers/AnulacionController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFecha, formatoFechaHora, formatoMonto } from '@/lib/formato';

type Solicitud = {
    id: number;
    motivo: string;
    solicitada_por: string;
    solicitada_en: string;
    pago: {
        id: number;
        numero_recibo: string | null;
        fecha_pago: string;
        vivienda: string;
        monto: string;
        cobrador: string | null;
    };
    puede_resolver: boolean;
    motivo_no_resolver: string | null;
};

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function Anulaciones({
    solicitudes,
}: {
    solicitudes: Solicitud[];
}) {
    return (
        <>
            <Head title="Anulaciones por aprobar" />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Anulaciones por aprobar
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Al aprobar, el pago queda anulado y deja de reducir el
                        saldo de la vivienda. No se borra.
                    </p>
                </div>

                {solicitudes.length === 0 ? (
                    <p className="text-muted-foreground">
                        No hay solicitudes pendientes.
                    </p>
                ) : (
                    <ul className="space-y-4">
                        {solicitudes.map((solicitud) => (
                            <SolicitudPendiente
                                key={solicitud.id}
                                solicitud={solicitud}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function SolicitudPendiente({ solicitud }: { solicitud: Solicitud }) {
    const [rechazando, setRechazando] = useState(false);

    return (
        <li className="space-y-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">
                        Recibo N.º {solicitud.pago.numero_recibo} ·{' '}
                        {solicitud.pago.vivienda}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {formatoFecha(solicitud.pago.fecha_pago)}
                        {solicitud.pago.cobrador &&
                            ` · cobró ${solicitud.pago.cobrador}`}
                    </p>
                </div>
                <p className="text-lg font-semibold">
                    L {formatoMonto(solicitud.pago.monto)}
                </p>
            </div>

            <div className="rounded-lg bg-muted/50 p-3 text-sm">
                <p className="text-muted-foreground">
                    Solicitó {solicitud.solicitada_por} ·{' '}
                    {formatoFechaHora(solicitud.solicitada_en)}
                </p>
                <p className="mt-1">{solicitud.motivo}</p>
            </div>

            {!solicitud.puede_resolver ? (
                <p className="text-sm text-muted-foreground">
                    {solicitud.motivo_no_resolver}
                </p>
            ) : rechazando ? (
                <Form
                    {...AnulacionController.rechazar.form(solicitud.id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`rechazo-${solicitud.id}`}>
                                    Motivo del rechazo
                                </Label>
                                <textarea
                                    id={`rechazo-${solicitud.id}`}
                                    name="motivo"
                                    rows={2}
                                    maxLength={1000}
                                    required
                                    className={claseTexto}
                                />
                                <InputError message={errors.motivo} />
                                <InputError message={errors.solicitud} />
                            </div>
                            <div className="flex gap-2">
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Rechazar solicitud
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setRechazando(false)}
                                >
                                    Cancelar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <Form
                    {...AnulacionController.aprobar.form(solicitud.id)}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-center gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Button variant="destructive" disabled={processing}>
                                {processing ? <Spinner /> : <Check />}
                                Aprobar y anular el pago
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRechazando(true)}
                            >
                                <X />
                                Rechazar
                            </Button>
                            <InputError message={errors.solicitud} />
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}
