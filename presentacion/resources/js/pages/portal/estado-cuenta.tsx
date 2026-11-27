import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileDown } from 'lucide-react';
import { useState } from 'react';
import PortalEstadoCuentaController from '@/actions/Patronato/Presentacion/Controllers/PortalEstadoCuentaController';
import EstadoMora from '@/components/finanzas/estado-mora';
import EstadoPago from '@/components/finanzas/estado-pago';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { FORMAS_PAGO, formatoFecha, formatoMonto } from '@/lib/formato';
import type { EstadoMora as Mora } from '@/types/finanzas';

type OpcionVivienda = { id: number; codigo: string; activa: boolean };

type Mes = { periodo: string };

type Movimiento = {
    clase: 'cargo' | 'ajuste' | 'pago';
    id: number;
    fecha: string;
    tipo: 'cargo' | 'ajuste' | 'pago' | 'abono';
    concepto?: string;
    periodo?: string;
    motivo?: string;
    forma_pago?: string;
    numero_recibo?: string | null;
    referencia?: string | null;
    meses?: Mes[];
    debe: string | null;
    haber: string | null;
    saldo: string;
};

type MesPendiente = {
    id: number;
    periodo: string;
    concepto: string;
    monto: string;
    pendiente: string;
    estado: 'parcial' | 'pendiente';
};

type PorVerificar = {
    id: number;
    fecha_pago: string;
    monto: string;
    forma_pago: string;
    estado: string;
    referencia: string | null;
};

type SinEfecto = {
    id: number;
    fecha_pago: string;
    monto: string;
    estado: string;
    motivo: string | null;
};

type Estado = {
    vivienda: OpcionVivienda;
    mora: Mora;
    totales: { saldo: string; por_verificar: string };
    anios: number[];
    anio: number | null;
    saldo_inicial: string | null;
    movimientos: Movimiento[];
    meses_pendientes: MesPendiente[];
    por_verificar: PorVerificar[];
    sin_efecto: SinEfecto[];
};

type Rango = { desde: string; hasta: string };

// Mes y año del período, por ejemplo "septiembre de 2026"
const formatoPeriodo = (periodo: string) =>
    new Date(`${periodo}T00:00:00`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
    });

// Un saldo negativo es dinero a favor de la vivienda
const textoSaldo = (saldo: string) =>
    Number(saldo) < 0
        ? `L ${formatoMonto(Math.abs(Number(saldo)))} a favor`
        : `L ${formatoMonto(saldo)}`;

// Título de cada movimiento del historial
const tituloMovimiento = (movimiento: Movimiento) => {
    if (movimiento.clase === 'cargo') {
        return `${movimiento.concepto} · ${formatoPeriodo(movimiento.periodo ?? movimiento.fecha)}`;
    }

    if (movimiento.clase === 'ajuste') {
        return `Ajuste · ${movimiento.concepto}`;
    }

    return `${movimiento.tipo === 'abono' ? 'Abono' : 'Pago'} · ${FORMAS_PAGO[movimiento.forma_pago ?? ''] ?? movimiento.forma_pago}`;
};

function Seccion({
    titulo,
    descripcion,
    children,
}: {
    titulo: string;
    descripcion?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div>
                <h2 className="text-lg font-semibold">{titulo}</h2>
                {descripcion && (
                    <p className="text-sm text-muted-foreground">
                        {descripcion}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function DescargarPdf({
    vivienda,
    rango,
}: {
    vivienda: OpcionVivienda;
    rango: Rango;
}) {
    const { errors } = usePage().props;
    const [desde, setDesde] = useState(rango.desde);
    const [hasta, setHasta] = useState(rango.hasta);

    return (
        <form
            method="get"
            action={PortalEstadoCuentaController.pdf.url(vivienda.id)}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
        >
            <div className="grid gap-1.5">
                <Label htmlFor="desde">Desde</Label>
                <Input
                    id="desde"
                    name="desde"
                    type="date"
                    value={desde}
                    max={hasta}
                    onChange={(evento) => setDesde(evento.target.value)}
                    aria-invalid={errors.desde ? true : undefined}
                    aria-describedby={errors.desde ? 'error-desde' : undefined}
                    required
                />
                <InputError id="error-desde" message={errors.desde} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="hasta">Hasta</Label>
                <Input
                    id="hasta"
                    name="hasta"
                    type="date"
                    value={hasta}
                    min={desde}
                    max={rango.hasta}
                    onChange={(evento) => setHasta(evento.target.value)}
                    aria-invalid={errors.hasta ? true : undefined}
                    aria-describedby={errors.hasta ? 'error-hasta' : undefined}
                    required
                />
                <InputError id="error-hasta" message={errors.hasta} />
            </div>
            <Button type="submit" className="sm:mt-6.5">
                <FileDown />
                Descargar PDF
            </Button>
        </form>
    );
}

function EstadoDeVivienda({
    estado,
    viviendas,
    rango,
}: {
    estado: Estado;
    viviendas: OpcionVivienda[];
    rango: Rango;
}) {
    const { vivienda, totales } = estado;

    const elegirAnio = (valor: string) =>
        router.get(
            PortalEstadoCuentaController.show.url(vivienda.id),
            valor === 'todos' ? {} : { anio: valor },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-8">
            {viviendas.length > 1 && (
                <Select
                    value={String(vivienda.id)}
                    onValueChange={(valor) =>
                        router.get(
                            PortalEstadoCuentaController.show.url(
                                Number(valor),
                            ),
                        )
                    }
                >
                    <SelectTrigger className="w-full" aria-label="Vivienda">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {viviendas.map((opcion) => (
                            <SelectItem
                                key={opcion.id}
                                value={String(opcion.id)}
                            >
                                Vivienda {opcion.codigo}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}

            <div className="space-y-3 rounded-xl border p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="flex items-center gap-2 font-semibold">
                        Vivienda {vivienda.codigo}
                        {!vivienda.activa && (
                            <Badge variant="outline">Inactiva</Badge>
                        )}
                    </p>
                    <EstadoMora mora={estado.mora} />
                </div>
                <div>
                    <p className="text-sm text-muted-foreground">
                        {Number(totales.saldo) > 0 ? 'Debes' : 'Saldo'}
                    </p>
                    <p className="text-3xl font-bold">
                        {textoSaldo(totales.saldo)}
                    </p>
                </div>
                {Number(totales.por_verificar) > 0 && (
                    <p className="text-sm text-muted-foreground">
                        L {formatoMonto(totales.por_verificar)} en pagos por
                        verificar; se descuentan cuando tesorería los confirme.
                    </p>
                )}
            </div>

            <Seccion titulo="Meses pendientes">
                {estado.meses_pendientes.length === 0 ? (
                    <p className="text-muted-foreground">
                        No tienes meses pendientes.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {estado.meses_pendientes.map((mes) => (
                            <li
                                key={mes.id}
                                className="flex items-center justify-between gap-3 p-4 text-sm"
                            >
                                <div>
                                    <p className="font-medium first-letter:uppercase">
                                        {formatoPeriodo(mes.periodo)}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {mes.concepto}
                                        {mes.estado === 'parcial' &&
                                            ` · abonado en parte de L ${formatoMonto(mes.monto)}`}
                                    </p>
                                </div>
                                <span className="font-semibold whitespace-nowrap">
                                    L {formatoMonto(mes.pendiente)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Seccion>

            {estado.por_verificar.length > 0 && (
                <Seccion
                    titulo="Pagos por verificar"
                    descripcion="No reducen tu saldo hasta que tesorería los confirme."
                >
                    <ul className="divide-y rounded-xl border">
                        {estado.por_verificar.map((pago) => (
                            <li
                                key={pago.id}
                                className="flex items-center justify-between gap-3 p-4 text-sm"
                            >
                                <div>
                                    <p className="font-medium">
                                        L {formatoMonto(pago.monto)} ·{' '}
                                        {formatoFecha(pago.fecha_pago)}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {FORMAS_PAGO[pago.forma_pago] ??
                                            pago.forma_pago}
                                        {pago.referencia &&
                                            ` · ${pago.referencia}`}
                                    </p>
                                </div>
                                <EstadoPago estado={pago.estado} />
                            </li>
                        ))}
                    </ul>
                </Seccion>
            )}

            {estado.sin_efecto.length > 0 && (
                <Seccion
                    titulo="Pagos sin efecto"
                    descripcion="Pagos anulados o transferencias rechazadas; no cuentan en tu saldo."
                >
                    <ul className="divide-y rounded-xl border">
                        {estado.sin_efecto.map((pago) => (
                            <li key={pago.id} className="space-y-1 p-4 text-sm">
                                <div className="flex items-center justify-between gap-3">
                                    <p className="font-medium">
                                        L {formatoMonto(pago.monto)} ·{' '}
                                        {formatoFecha(pago.fecha_pago)}
                                    </p>
                                    <EstadoPago estado={pago.estado} />
                                </div>
                                {pago.motivo && (
                                    <p className="text-muted-foreground">
                                        Motivo: {pago.motivo}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </Seccion>
            )}

            <Seccion titulo="Historial">
                {estado.anios.length > 0 && (
                    <Select
                        value={
                            estado.anio === null ? 'todos' : String(estado.anio)
                        }
                        onValueChange={elegirAnio}
                    >
                        <SelectTrigger
                            className="w-full sm:w-48"
                            aria-label="Año"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="todos">
                                Todo el historial
                            </SelectItem>
                            {estado.anios.map((anio) => (
                                <SelectItem key={anio} value={String(anio)}>
                                    {anio}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                {estado.movimientos.length === 0 ? (
                    <p className="text-muted-foreground">
                        {estado.anio === null
                            ? 'Tu vivienda todavía no tiene cargos ni pagos.'
                            : `No hay movimientos en ${estado.anio}.`}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {estado.saldo_inicial !== null && (
                            <li className="flex justify-between gap-3 p-4 text-sm text-muted-foreground">
                                <span>Saldo al iniciar {estado.anio}</span>
                                <span>{textoSaldo(estado.saldo_inicial)}</span>
                            </li>
                        )}
                        {[...estado.movimientos].reverse().map((movimiento) => (
                            <li
                                key={`${movimiento.clase}-${movimiento.id}`}
                                className="space-y-1 p-4 text-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {tituloMovimiento(movimiento)}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {formatoFecha(movimiento.fecha)}
                                            {movimiento.numero_recibo &&
                                                ` · Recibo ${movimiento.numero_recibo}`}
                                            {movimiento.referencia &&
                                                ` · ${movimiento.referencia}`}
                                        </p>
                                    </div>
                                    <span
                                        className={`font-semibold whitespace-nowrap ${movimiento.haber ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                                    >
                                        {movimiento.debe
                                            ? `L ${formatoMonto(movimiento.debe)}`
                                            : `− L ${formatoMonto(movimiento.haber ?? 0)}`}
                                    </span>
                                </div>
                                {movimiento.motivo && (
                                    <p className="text-muted-foreground">
                                        {movimiento.motivo}
                                    </p>
                                )}
                                {(movimiento.meses ?? []).length > 0 && (
                                    <p className="text-muted-foreground">
                                        Aplicado a{' '}
                                        {(movimiento.meses ?? [])
                                            .map((mes) =>
                                                formatoPeriodo(mes.periodo),
                                            )
                                            .join(', ')}
                                    </p>
                                )}
                                <p className="text-right text-xs text-muted-foreground">
                                    Saldo: {textoSaldo(movimiento.saldo)}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </Seccion>

            <Seccion
                titulo="Descargar estado de cuenta"
                descripcion="Elige el rango de fechas del PDF."
            >
                <DescargarPdf vivienda={vivienda} rango={rango} />
            </Seccion>
        </div>
    );
}

export default function EstadoCuenta({
    viviendas,
    estado,
    rango,
}: {
    viviendas: OpcionVivienda[];
    estado: Estado | null;
    rango: Rango;
}) {
    return (
        <>
            <Head title="Mi estado de cuenta" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Mi estado de cuenta
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Lo que debes, tus pagos y los meses pendientes.
                    </p>
                </div>

                {estado !== null ? (
                    <EstadoDeVivienda
                        estado={estado}
                        viviendas={viviendas}
                        rango={rango}
                    />
                ) : viviendas.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-muted-foreground">
                        No tienes viviendas asignadas. Consulta con el
                        patronato.
                    </p>
                ) : (
                    <div className="grid gap-2">
                        {viviendas.map((opcion) => (
                            <Link
                                key={opcion.id}
                                href={PortalEstadoCuentaController.show.url(
                                    opcion.id,
                                )}
                                className="flex items-center justify-between rounded-xl border p-4 font-semibold hover:bg-muted"
                            >
                                Vivienda {opcion.codigo}
                                {!opcion.activa && (
                                    <Badge variant="outline">Inactiva</Badge>
                                )}
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
