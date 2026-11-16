import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban, Clock, FileText, Paperclip } from 'lucide-react';
import ComprobanteController from '@/actions/Patronato/Presentacion/Controllers/ComprobanteController';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import TransferenciaController from '@/actions/Patronato/Presentacion/Controllers/TransferenciaController';
import EstadoPago from '@/components/finanzas/estado-pago';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFecha, formatoFechaHora, formatoMonto } from '@/lib/formato';

type Solicitud = {
    id: number;
    estado: 'pendiente' | 'aprobada' | 'rechazada';
    motivo: string;
    solicitada_por: string;
    solicitada_en: string;
    resuelta_por: string | null;
    resuelta_en: string | null;
    motivo_rechazo: string | null;
};

type DetallePago = {
    id: number;
    numero_recibo: string | null;
    fecha_pago: string;
    registrado_en: string;
    vivienda: string;
    monto: string;
    forma_pago: string;
    estado: string;
    origen: string;
    cobrador: string | null;
    transferencia: {
        banco: string | null;
        numero_referencia: string | null;
        cuenta_receptora: string | null;
        tiene_comprobante: boolean;
    } | null;
    caja_cerrada: boolean;
    saldo_vivienda: string;
    anulacion: {
        solicitada_por: string | null;
        aprobada_por: string | null;
        anulado_en: string | null;
        motivo: string | null;
    } | null;
    solicitudes: Solicitud[];
    puede_solicitar: boolean;
};

const ESTADOS_SOLICITUD = {
    pendiente: 'Pendiente de aprobación',
    aprobada: 'Aprobada',
    rechazada: 'Rechazada',
};

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function Pago({ pago }: { pago: DetallePago }) {
    const pendiente = pago.solicitudes.find(
        (solicitud) => solicitud.estado === 'pendiente',
    );

    // Una transferencia recibe su número de recibo cuando se confirma
    const titulo = pago.numero_recibo
        ? `Recibo N.º ${pago.numero_recibo}`
        : `Transferencia ref. ${pago.transferencia?.numero_referencia ?? ''}`;

    return (
        <>
            <Head title={titulo} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={PagoController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Cobros
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            {titulo}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Vivienda {pago.vivienda} ·{' '}
                            {formatoFecha(pago.fecha_pago)}
                        </p>
                    </div>
                    <EstadoPago estado={pago.estado} />
                </div>

                {pago.anulacion && (
                    <Alert variant="destructive">
                        <Ban />
                        <AlertTitle>Pago anulado</AlertTitle>
                        <AlertDescription>
                            <p>
                                Solicitó {pago.anulacion.solicitada_por} ·
                                aprobó {pago.anulacion.aprobada_por}
                                {pago.anulacion.anulado_en &&
                                    ` · ${formatoFechaHora(pago.anulacion.anulado_en)}`}
                            </p>
                            <p>Motivo: {pago.anulacion.motivo}</p>
                        </AlertDescription>
                    </Alert>
                )}

                {(pago.estado === 'pendiente_verificacion' ||
                    pago.estado === 'reportado_por_vecino') && (
                    <Alert>
                        <Clock />
                        <AlertTitle>Pendiente de verificación</AlertTitle>
                        <AlertDescription>
                            No reduce el saldo de la vivienda hasta que se
                            confirme que el dinero ingresó a la cuenta.
                        </AlertDescription>
                    </Alert>
                )}

                {pendiente && (
                    <Alert>
                        <Clock />
                        <AlertTitle>
                            Anulación pendiente de aprobación
                        </AlertTitle>
                        <AlertDescription>
                            Solicitada por {pendiente.solicitada_por}. El pago
                            sigue confirmado hasta que otro directivo la
                            apruebe.
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Monto</dt>
                    <dd
                        className={
                            pago.anulacion
                                ? 'font-semibold text-muted-foreground line-through'
                                : 'font-semibold'
                        }
                    >
                        L {formatoMonto(pago.monto)}
                    </dd>
                    <dt className="text-muted-foreground">Forma de pago</dt>
                    <dd className="font-semibold capitalize">
                        {pago.forma_pago}
                    </dd>
                    {pago.transferencia && (
                        <>
                            <dt className="text-muted-foreground">Banco</dt>
                            <dd className="font-semibold">
                                {pago.transferencia.banco}
                            </dd>
                            <dt className="text-muted-foreground">
                                Referencia
                            </dt>
                            <dd className="font-semibold">
                                {pago.transferencia.numero_referencia}
                            </dd>
                            <dt className="text-muted-foreground">
                                Cuenta receptora
                            </dt>
                            <dd className="font-semibold">
                                {pago.transferencia.cuenta_receptora}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">
                        {pago.origen === 'portal'
                            ? 'Reportó'
                            : pago.transferencia
                              ? 'Registró'
                              : 'Cobrador'}
                    </dt>
                    <dd className="font-semibold">{pago.cobrador ?? '—'}</dd>
                    <dt className="text-muted-foreground">Registrado</dt>
                    <dd className="font-semibold">
                        {formatoFechaHora(pago.registrado_en)}
                        {pago.caja_cerrada && ' · caja cerrada'}
                    </dd>
                    <dt className="text-muted-foreground">
                        Saldo de la vivienda
                    </dt>
                    <dd className="font-semibold">
                        L {formatoMonto(pago.saldo_vivienda)}
                    </dd>
                </dl>

                <div className="flex flex-wrap gap-3">
                    {pago.numero_recibo && (
                        <Button variant="outline" asChild>
                            <Link
                                href={ComprobanteController.show.url(pago.id)}
                            >
                                <FileText />
                                Ver comprobante
                            </Link>
                        </Button>
                    )}
                    {pago.transferencia?.tiene_comprobante && (
                        <Button variant="outline" asChild>
                            <a
                                href={TransferenciaController.comprobante.url(
                                    pago.id,
                                )}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Paperclip />
                                Ver comprobante de la transferencia
                            </a>
                        </Button>
                    )}
                </div>

                {pago.puede_solicitar && (
                    <Form
                        {...PagoController.solicitarAnulacion.form(pago.id)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 rounded-xl border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div>
                                    <h2 className="font-semibold">
                                        Solicitar anulación
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Otro directivo, como el presidente o el
                                        fiscal, debe aprobarla. El pago no se
                                        borra: queda visible como anulado.
                                        {pago.caja_cerrada &&
                                            ' La caja ya está cerrada y conserva su total.'}
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
                                    Solicitar anulación
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {pago.solicitudes.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">
                            Solicitudes de anulación
                        </h2>
                        <ul className="divide-y rounded-xl border">
                            {pago.solicitudes.map((solicitud) => (
                                <li
                                    key={solicitud.id}
                                    className="space-y-1 p-4 text-sm"
                                >
                                    <p className="font-medium">
                                        {ESTADOS_SOLICITUD[solicitud.estado]}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {solicitud.solicitada_por} ·{' '}
                                        {formatoFechaHora(
                                            solicitud.solicitada_en,
                                        )}
                                        : {solicitud.motivo}
                                    </p>
                                    {solicitud.resuelta_por && (
                                        <p className="text-muted-foreground">
                                            Resolvió {solicitud.resuelta_por}
                                            {solicitud.resuelta_en &&
                                                ` · ${formatoFechaHora(solicitud.resuelta_en)}`}
                                            {solicitud.motivo_rechazo &&
                                                `: ${solicitud.motivo_rechazo}`}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
