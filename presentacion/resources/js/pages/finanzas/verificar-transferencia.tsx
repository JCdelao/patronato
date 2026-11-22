import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban, Check, CircleAlert, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import VerificacionTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/VerificacionTransferenciaController';
import EstadoPago from '@/components/finanzas/estado-pago';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    centavos,
    formatoFecha,
    formatoFechaHora,
    formatoMonto,
} from '@/lib/formato';

type CargoPendiente = {
    cargo_id: number;
    periodo: string;
    concepto: string;
    monto: string;
    pendiente: string;
};

type Transferencia = {
    id: number;
    vivienda: string;
    monto: string;
    fecha_pago: string;
    banco: string | null;
    numero_referencia: string | null;
    cuenta_receptora: string | null;
    estado: string;
    origen: string;
    registrado_por: string | null;
    registrado_en: string;
    dias_sin_verificar: number | null;
    tiene_comprobante: boolean;
    numero_recibo: string | null;
    verificado_por: string | null;
    verificado_en: string | null;
    motivo_rechazo: string | null;
    por_verificar: boolean;
    motivo_no_confirmar: string | null;
    permite_abono_parcial: boolean;
    permite_saldo_favor: boolean;
    pendientes: CargoPendiente[] | null;
    sugeridos: number[];
    aviso_integracion: string | null;
};

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const formatoPeriodo = (periodo: string) =>
    new Date(`${periodo}T00:00:00`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
    });

export default function VerificarTransferencia({
    transferencia,
}: {
    transferencia: Transferencia;
}) {
    const titulo = `Transferencia ref. ${transferencia.numero_referencia ?? ''}`;

    return (
        <>
            <Head title={titulo} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={VerificacionTransferenciaController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Transferencias por verificar
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            {titulo}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Vivienda {transferencia.vivienda}
                            {transferencia.origen === 'portal' &&
                                ' · reportada desde el portal'}
                        </p>
                    </div>
                    <EstadoPago estado={transferencia.estado} />
                </div>

                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Monto</dt>
                    <dd className="font-semibold">
                        L {formatoMonto(transferencia.monto)}
                    </dd>
                    <dt className="text-muted-foreground">Fecha</dt>
                    <dd className="font-semibold">
                        {formatoFecha(transferencia.fecha_pago)}
                    </dd>
                    <dt className="text-muted-foreground">Banco</dt>
                    <dd className="font-semibold">{transferencia.banco}</dd>
                    <dt className="text-muted-foreground">Referencia</dt>
                    <dd className="font-semibold">
                        {transferencia.numero_referencia}
                    </dd>
                    <dt className="text-muted-foreground">Cuenta receptora</dt>
                    <dd className="font-semibold">
                        {transferencia.cuenta_receptora ?? 'No indicada'}
                    </dd>
                    <dt className="text-muted-foreground">
                        {transferencia.origen === 'portal'
                            ? 'Reportó'
                            : 'Registró'}
                    </dt>
                    <dd className="font-semibold">
                        {transferencia.registrado_por ?? '—'} ·{' '}
                        {formatoFechaHora(transferencia.registrado_en)}
                    </dd>
                    {transferencia.dias_sin_verificar !== null && (
                        <>
                            <dt className="text-muted-foreground">
                                Sin verificar
                            </dt>
                            <dd className="font-semibold">
                                {transferencia.dias_sin_verificar === 1
                                    ? '1 día'
                                    : `${transferencia.dias_sin_verificar} días`}
                            </dd>
                        </>
                    )}
                </dl>

                {transferencia.tiene_comprobante && (
                    <Button variant="outline" asChild>
                        <a
                            href={VerificacionTransferenciaController.comprobante.url(
                                transferencia.id,
                            )}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Paperclip />
                            Ver comprobante de la transferencia
                        </a>
                    </Button>
                )}

                {transferencia.por_verificar ? (
                    <Verificacion transferencia={transferencia} />
                ) : (
                    <Resultado transferencia={transferencia} />
                )}
            </div>
        </>
    );
}

// Resultado de una transferencia ya verificada
function Resultado({ transferencia }: { transferencia: Transferencia }) {
    const quien = [
        transferencia.verificado_por,
        transferencia.verificado_en &&
            formatoFechaHora(transferencia.verificado_en),
    ]
        .filter(Boolean)
        .join(' · ');

    if (transferencia.estado === 'rechazado') {
        return (
            <Alert variant="destructive">
                <Ban />
                <AlertTitle>Transferencia rechazada</AlertTitle>
                <AlertDescription>
                    {quien && <p>Rechazó {quien}</p>}
                    <p>Motivo: {transferencia.motivo_rechazo}</p>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert>
            <Check />
            <AlertTitle>
                {transferencia.numero_recibo
                    ? `Confirmada con el recibo N.º ${transferencia.numero_recibo}`
                    : 'Esta transferencia ya no está pendiente'}
            </AlertTitle>
            {quien && (
                <AlertDescription>
                    <p>Verificó {quien}</p>
                </AlertDescription>
            )}
        </Alert>
    );
}

function Verificacion({ transferencia }: { transferencia: Transferencia }) {
    const [elegidos, setElegidos] = useState<number[]>(transferencia.sugeridos);
    const [rechazando, setRechazando] = useState(false);

    const pendientes = transferencia.pendientes ?? [];
    // Sin meses elegidos, la transferencia se aplica a todos los pendientes
    const sinMeses = elegidos.length === 0;
    const totalElegido = pendientes
        .filter((cargo) => sinMeses || elegidos.includes(cargo.cargo_id))
        .reduce((total, cargo) => total + centavos(cargo.pendiente), 0);
    const monto = centavos(transferencia.monto);

    // Lo que sobra de una transferencia sin meses queda como saldo a favor
    const admiteExcedente = sinMeses && transferencia.permite_saldo_favor;
    const excedente = admiteExcedente ? Math.max(0, monto - totalElegido) : 0;

    const alternarMes = (id: number, marcado: boolean) =>
        setElegidos((actuales) =>
            marcado
                ? [...actuales, id]
                : actuales.filter((elegido) => elegido !== id),
        );

    const avisoMonto =
        monto > totalElegido && !admiteExcedente
            ? sinMeses
                ? 'El monto de la transferencia supera lo pendiente de la vivienda.'
                : 'El monto de la transferencia supera lo pendiente de los meses elegidos. Elige más meses.'
            : monto < totalElegido && !transferencia.permite_abono_parcial
              ? sinMeses
                  ? 'El patronato no permite abonos parciales: sin meses elegidos, el monto debe cubrir todo lo pendiente de la vivienda.'
                  : 'El patronato no permite abonos parciales: el monto debe cubrir los meses elegidos.'
              : null;

    return (
        <div className="space-y-6">
            {transferencia.motivo_no_confirmar ? (
                <Alert>
                    <CircleAlert />
                    <AlertTitle>No puedes confirmarla</AlertTitle>
                    <AlertDescription>
                        {transferencia.motivo_no_confirmar}
                    </AlertDescription>
                </Alert>
            ) : transferencia.aviso_integracion !== null ? (
                <Alert>
                    <CircleAlert />
                    <AlertTitle>Períodos pendientes no disponibles</AlertTitle>
                    <AlertDescription>
                        {transferencia.aviso_integracion}
                    </AlertDescription>
                </Alert>
            ) : pendientes.length === 0 &&
              !transferencia.permite_saldo_favor ? (
                <Alert>
                    <CircleAlert />
                    <AlertTitle>
                        La vivienda no tiene meses pendientes
                    </AlertTitle>
                    <AlertDescription>
                        Todavía no se admite el saldo a favor, así que esta
                        transferencia no se puede aplicar. Puedes rechazarla con
                        su motivo.
                    </AlertDescription>
                </Alert>
            ) : (
                <Form
                    {...VerificacionTransferenciaController.confirmar.form(
                        transferencia.id,
                    )}
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {elegidos.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="cargos[]"
                                    value={id}
                                />
                            ))}

                            {pendientes.length === 0 ? (
                                <p className="text-muted-foreground">
                                    La vivienda no tiene meses pendientes: toda
                                    la transferencia quedará como saldo a favor.
                                </p>
                            ) : (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        Meses a los que se aplica
                                    </legend>
                                    {pendientes.map((cargo) => (
                                        <Label
                                            key={cargo.cargo_id}
                                            className="flex items-center gap-3 rounded-xl border p-4 font-normal"
                                        >
                                            <Checkbox
                                                checked={elegidos.includes(
                                                    cargo.cargo_id,
                                                )}
                                                onCheckedChange={(marcado) =>
                                                    alternarMes(
                                                        cargo.cargo_id,
                                                        marcado === true,
                                                    )
                                                }
                                            />
                                            <span className="flex-1">
                                                <span className="block capitalize">
                                                    {formatoPeriodo(
                                                        cargo.periodo,
                                                    )}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    {cargo.concepto}
                                                </span>
                                            </span>
                                            <span className="font-medium">
                                                L{' '}
                                                {formatoMonto(cargo.pendiente)}
                                            </span>
                                        </Label>
                                    ))}
                                    <InputError message={errors.cargos} />
                                </fieldset>
                            )}

                            {sinMeses && pendientes.length > 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Sin meses elegidos, la transferencia se
                                    aplica a los meses pendientes, del más
                                    antiguo al más reciente.
                                </p>
                            )}

                            <p className="text-sm text-muted-foreground">
                                {sinMeses
                                    ? 'Pendiente de la vivienda:'
                                    : 'Pendiente de los meses elegidos:'}{' '}
                                <span className="font-semibold text-foreground">
                                    L {formatoMonto(totalElegido / 100)}
                                </span>{' '}
                                · Monto de la transferencia:{' '}
                                <span className="font-semibold text-foreground">
                                    L {formatoMonto(transferencia.monto)}
                                </span>
                            </p>

                            {excedente > 0 && (
                                <p className="text-sm text-muted-foreground">
                                    El excedente de L{' '}
                                    {formatoMonto(excedente / 100)} quedará como
                                    saldo a favor.
                                </p>
                            )}

                            {avisoMonto && (
                                <p className="text-sm text-destructive">
                                    {avisoMonto}
                                </p>
                            )}
                            <InputError message={errors.pago} />

                            <Button
                                className="h-12 w-full text-base"
                                disabled={processing || avisoMonto !== null}
                            >
                                {processing ? <Spinner /> : <Check />}
                                Confirmar transferencia
                            </Button>
                        </>
                    )}
                </Form>
            )}

            {rechazando ? (
                <Form
                    {...VerificacionTransferenciaController.rechazar.form(
                        transferencia.id,
                    )}
                    options={{ preserveScroll: true }}
                    className="grid gap-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="motivo-rechazo">
                                    Motivo del rechazo
                                </Label>
                                <textarea
                                    id="motivo-rechazo"
                                    name="motivo"
                                    rows={3}
                                    maxLength={1000}
                                    required
                                    className={claseTexto}
                                />
                                <p className="text-sm text-muted-foreground">
                                    El residente lo recibirá por correo.
                                </p>
                                <InputError message={errors.motivo} />
                                <InputError message={errors.pago} />
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Rechazar transferencia
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
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setRechazando(true)}
                >
                    <X />
                    Rechazar
                </Button>
            )}
        </div>
    );
}
