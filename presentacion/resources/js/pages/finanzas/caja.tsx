import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, CircleAlert } from 'lucide-react';
import { useState } from 'react';
import CajaController from '@/actions/Patronato/Presentacion/Controllers/CajaController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { centavos, formatoFechaHora, formatoMonto } from '@/lib/formato';

type Cobro = {
    id: number;
    numero_recibo: string | null;
    vivienda: string;
    monto: string;
    estado: string;
    registrado_en: string;
};

type DetalleCaja = {
    id: number;
    estado: 'abierta' | 'cerrada';
    cobrador: string;
    abierta_en: string;
    cerrada_en: string | null;
    cerrada_por: string | null;
    cobros: Cobro[];
    total_registrado: string;
    monto_entregado: string | null;
    diferencia: string | null;
    justificacion: string | null;
    puede_cerrar: boolean;
};

function Diferencia({ centavos: valor }: { centavos: number }) {
    if (valor === 0) {
        return <span className="font-semibold">L 0.00 · cuadra</span>;
    }

    return (
        <span
            className={
                valor < 0 ? 'font-semibold text-destructive' : 'font-semibold'
            }
        >
            {valor < 0 ? 'Faltante' : 'Sobrante'} de L{' '}
            {formatoMonto(Math.abs(valor) / 100)}
        </span>
    );
}

export default function Caja({ caja }: { caja: DetalleCaja }) {
    const [entregado, setEntregado] = useState('');
    const cerrada = caja.estado === 'cerrada';

    const diferencia = cerrada
        ? centavos(caja.diferencia ?? '0')
        : entregado === ''
          ? null
          : centavos(entregado) - centavos(caja.total_registrado);

    return (
        <>
            <Head title={`Caja de ${caja.cobrador}`} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={CajaController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Cajas
                </Link>

                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Caja de {caja.cobrador}
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Abierta el {formatoFechaHora(caja.abierta_en)}
                        {cerrada &&
                            caja.cerrada_en !== null &&
                            ` · cerrada el ${formatoFechaHora(caja.cerrada_en)} por ${caja.cerrada_por}`}
                    </p>
                </div>

                <section className="space-y-2">
                    <h2 className="text-sm font-medium">Cobros incluidos</h2>
                    {caja.cobros.length === 0 ? (
                        <p className="text-muted-foreground">
                            Esta caja no tiene cobros.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {caja.cobros.map((cobro) => (
                                <li
                                    key={cobro.id}
                                    className="flex items-center justify-between p-4"
                                >
                                    <span>
                                        <span className="block font-medium">
                                            Recibo N.º {cobro.numero_recibo} ·{' '}
                                            {cobro.vivienda}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {formatoFechaHora(
                                                cobro.registrado_en,
                                            )}
                                            {cobro.estado !== 'confirmado' &&
                                                ` · ${cobro.estado}`}
                                        </span>
                                    </span>
                                    <span
                                        className={
                                            cobro.estado === 'confirmado'
                                                ? 'font-medium'
                                                : 'font-medium text-muted-foreground line-through'
                                        }
                                    >
                                        L {formatoMonto(cobro.monto)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <dl className="grid grid-cols-[1fr_auto] gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Total registrado</dt>
                    <dd className="text-right font-semibold">
                        L {formatoMonto(caja.total_registrado)}
                    </dd>
                    {cerrada && (
                        <>
                            <dt className="text-muted-foreground">
                                Monto entregado
                            </dt>
                            <dd className="text-right font-semibold">
                                L {formatoMonto(caja.monto_entregado ?? '0')}
                            </dd>
                        </>
                    )}
                    {diferencia !== null && (
                        <>
                            <dt className="text-muted-foreground">
                                Diferencia
                            </dt>
                            <dd className="text-right">
                                <Diferencia centavos={diferencia} />
                            </dd>
                        </>
                    )}
                    {cerrada && caja.justificacion && (
                        <>
                            <dt className="col-span-2 text-muted-foreground">
                                Justificación
                            </dt>
                            <dd className="col-span-2">{caja.justificacion}</dd>
                        </>
                    )}
                </dl>

                {cerrada ? (
                    <p className="text-sm text-muted-foreground">
                        Los cobros de una caja cerrada no se pueden editar.
                        Cualquier corrección se hace con una anulación.
                    </p>
                ) : !caja.puede_cerrar ? (
                    <Alert>
                        <CircleAlert />
                        <AlertTitle>No puedes cerrar tu propia caja</AlertTitle>
                        <AlertDescription>
                            Otro usuario con permiso de cierre debe hacerlo.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Form
                        {...CajaController.cerrar.form(caja.id)}
                        options={{ preserveScroll: true }}
                        className="grid gap-4 rounded-xl border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="monto_entregado">
                                        Monto entregado
                                    </Label>
                                    <Input
                                        id="monto_entregado"
                                        name="monto_entregado"
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0"
                                        value={entregado}
                                        onChange={(evento) =>
                                            setEntregado(evento.target.value)
                                        }
                                        className="h-12 text-lg"
                                    />
                                    <InputError
                                        message={errors.monto_entregado}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="justificacion">
                                        Justificación
                                        {diferencia !== null &&
                                            diferencia !== 0 &&
                                            ' (obligatoria)'}
                                    </Label>
                                    <textarea
                                        id="justificacion"
                                        name="justificacion"
                                        rows={3}
                                        maxLength={1000}
                                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    />
                                    <InputError
                                        message={errors.justificacion}
                                    />
                                </div>

                                <InputError message={errors.caja} />

                                <Button
                                    className="h-12 w-full text-base"
                                    disabled={processing || entregado === ''}
                                >
                                    {processing && <Spinner />}
                                    Cerrar caja
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
