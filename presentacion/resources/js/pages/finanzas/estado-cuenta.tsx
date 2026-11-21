import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, Search, X } from 'lucide-react';
import { useRef } from 'react';
import EstadoCuentaController from '@/actions/Patronato/Presentacion/Controllers/EstadoCuentaController';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import VerificacionTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/VerificacionTransferenciaController';
import EstadoMora from '@/components/finanzas/estado-mora';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { FORMAS_PAGO, formatoFecha, formatoMonto } from '@/lib/formato';
import type { EstadoMora as Mora } from '@/types/finanzas';

type OpcionVivienda = {
    id: number;
    codigo: string;
    responsable: string | null;
    activa: boolean;
};

type Mes = { periodo: string; concepto: string; monto: string };

type Movimiento = {
    clase: 'cargo' | 'ajuste' | 'pago';
    id: number;
    fecha: string;
    tipo: 'cargo' | 'ajuste' | 'pago' | 'abono';
    concepto?: string;
    periodo?: string;
    estado_cargo?: 'pagado' | 'parcial' | 'pendiente';
    pendiente?: string;
    motivo?: string;
    autorizado_por?: string | null;
    forma_pago?: string;
    numero_recibo?: string | null;
    referencia?: string | null;
    responsable?: string | null;
    meses?: Mes[];
    debe: string | null;
    haber: string | null;
    saldo: string;
    migrado: boolean;
};

type PorVerificar = {
    id: number;
    fecha_pago: string;
    monto: string;
    forma_pago: string;
    referencia: string | null;
    origen: string;
    dias_sin_verificar: number;
};

type SinEfecto = {
    id: number;
    fecha_pago: string;
    monto: string;
    forma_pago: string;
    estado: 'anulado' | 'rechazado';
    numero_recibo: string | null;
    referencia: string | null;
    motivo: string | null;
    responsable: string | null;
};

type Estado = {
    vivienda: OpcionVivienda;
    mora: Mora;
    totales: {
        cargos: string;
        pagos_confirmados: string;
        ajustes: string;
        saldo: string;
        por_verificar: string;
    };
    cuadra: boolean;
    anios: number[];
    anio: number | null;
    saldo_inicial: string | null;
    movimientos: Movimiento[];
    por_verificar: PorVerificar[];
    sin_efecto: SinEfecto[];
};

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

const ESTADO_CARGO = {
    pagado: { texto: 'Pagado', variante: 'secondary' },
    parcial: { texto: 'Parcial', variante: 'outline' },
    pendiente: { texto: 'Pendiente', variante: 'destructive' },
} as const;

const textoDias = (dias: number) =>
    dias === 0
        ? 'Registrada hoy'
        : dias === 1
          ? '1 día sin verificar'
          : `${dias} días sin verificar`;

function Buscador({
    buscar,
    viviendas,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
}) {
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                EstadoCuentaController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <div className="space-y-3">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    aria-label="Buscar vivienda"
                    placeholder="Código de vivienda o responsable"
                    defaultValue={buscar}
                    onChange={(evento) => alBuscar(evento.target.value)}
                    className="h-12 rounded-full pl-11"
                />
            </div>

            {buscar !== '' && viviendas.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No se encontraron viviendas.
                </p>
            )}

            <div className="grid gap-2">
                {viviendas.map((opcion) => (
                    <Link
                        key={opcion.id}
                        href={EstadoCuentaController.show.url(opcion.id)}
                        className="rounded-xl border p-4 hover:bg-muted"
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span className="font-semibold">
                                {opcion.codigo}
                            </span>
                            {!opcion.activa && (
                                <Badge variant="outline">Inactiva</Badge>
                            )}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {opcion.responsable ?? 'Sin responsable de pago'}
                        </span>
                    </Link>
                ))}
            </div>
        </div>
    );
}

// Concepto y detalle de cada movimiento
function Detalle({ movimiento }: { movimiento: Movimiento }) {
    if (movimiento.clase === 'cargo') {
        const estado = ESTADO_CARGO[movimiento.estado_cargo ?? 'pendiente'];

        return (
            <>
                <p className="font-medium">
                    {movimiento.concepto} ·{' '}
                    {formatoPeriodo(movimiento.periodo ?? movimiento.fecha)}
                </p>
                <p className="mt-1 flex flex-wrap items-center gap-2 text-muted-foreground">
                    <Badge variant={estado.variante}>{estado.texto}</Badge>
                    {movimiento.estado_cargo === 'parcial' && (
                        <span>
                            Falta L {formatoMonto(movimiento.pendiente ?? 0)}
                        </span>
                    )}
                </p>
            </>
        );
    }

    if (movimiento.clase === 'ajuste') {
        return (
            <>
                <p className="font-medium">Ajuste · {movimiento.concepto}</p>
                <p className="text-muted-foreground">{movimiento.motivo}</p>
                <p className="text-muted-foreground">
                    {movimiento.autorizado_por
                        ? `Autorizó ${movimiento.autorizado_por}`
                        : 'Sin autorizador registrado'}
                </p>
            </>
        );
    }

    return (
        <>
            <p className="font-medium">
                <Link
                    href={PagoController.show.url(movimiento.id)}
                    className="hover:underline"
                >
                    {movimiento.tipo === 'abono' ? 'Abono' : 'Pago'} ·{' '}
                    {FORMAS_PAGO[movimiento.forma_pago ?? ''] ??
                        movimiento.forma_pago}
                </Link>
            </p>
            <p className="text-muted-foreground">
                {[
                    movimiento.numero_recibo
                        ? `Recibo ${movimiento.numero_recibo}`
                        : null,
                    movimiento.referencia,
                ]
                    .filter(Boolean)
                    .join(' · ') || 'Sin recibo'}
            </p>
            {(movimiento.meses ?? []).length > 0 && (
                <p className="text-muted-foreground">
                    Aplicado a{' '}
                    {(movimiento.meses ?? [])
                        .map(
                            (mes) =>
                                `${formatoPeriodo(mes.periodo)} (L ${formatoMonto(mes.monto)})`,
                        )
                        .join(', ')}
                </p>
            )}
        </>
    );
}

function EstadoDeVivienda({ estado }: { estado: Estado }) {
    const { auth } = usePage().props;
    const puedeVerificar = auth.permisos.includes('transferencias.verificar');
    const { vivienda, totales } = estado;

    const elegirAnio = (valor: string) =>
        router.get(
            EstadoCuentaController.show.url(vivienda.id),
            valor === 'todos' ? {} : { anio: valor },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-6">
            <div className="flex items-start justify-between gap-4 rounded-xl border p-4">
                <div className="space-y-1">
                    <p className="flex items-center gap-2 text-lg font-semibold">
                        {vivienda.codigo}
                        {!vivienda.activa && (
                            <Badge variant="outline">Inactiva</Badge>
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {vivienda.responsable ?? 'Sin responsable de pago'}
                    </p>
                    <EstadoMora mora={estado.mora} detalle className="mt-1" />
                </div>
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={EstadoCuentaController.index.url()}
                        aria-label="Cambiar vivienda"
                    >
                        <X />
                    </Link>
                </Button>
            </div>

            <dl className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-xl border p-4">
                    <dt className="text-sm text-muted-foreground">
                        Saldo actual
                    </dt>
                    <dd className="text-2xl font-semibold">
                        {textoSaldo(totales.saldo)}
                    </dd>
                </div>
                <div className="rounded-xl border p-4">
                    <dt className="text-sm text-muted-foreground">
                        Cargos · pagos confirmados
                    </dt>
                    <dd className="font-medium">
                        L {formatoMonto(totales.cargos)} · L{' '}
                        {formatoMonto(totales.pagos_confirmados)}
                    </dd>
                    {Number(totales.ajustes) !== 0 && (
                        <dd className="text-sm text-muted-foreground">
                            Ajustes: L {formatoMonto(totales.ajustes)}
                        </dd>
                    )}
                </div>
                <div className="rounded-xl border p-4">
                    <dt className="text-sm text-muted-foreground">
                        Por verificar
                    </dt>
                    <dd className="font-medium">
                        L {formatoMonto(totales.por_verificar)}
                    </dd>
                    <dd className="text-sm text-muted-foreground">
                        No reduce el saldo
                    </dd>
                </div>
            </dl>

            {!estado.cuadra && (
                <Alert variant="destructive">
                    <CircleAlert />
                    <AlertTitle>El saldo no cuadra</AlertTitle>
                    <AlertDescription>
                        El saldo acumulado de los movimientos no coincide con el
                        saldo oficial. Avisa al administrador técnico.
                    </AlertDescription>
                </Alert>
            )}

            {estado.por_verificar.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        Pagos pendientes de verificación
                    </h2>
                    <ul className="space-y-2">
                        {estado.por_verificar.map((pago) => (
                            <li
                                key={pago.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-4 text-sm"
                            >
                                <div>
                                    <p className="font-medium">
                                        {FORMAS_PAGO[pago.forma_pago] ??
                                            pago.forma_pago}{' '}
                                        · {formatoFecha(pago.fecha_pago)}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {pago.referencia}
                                        {pago.origen === 'portal' &&
                                            ' · reportada desde el portal'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Badge variant="secondary">
                                        {textoDias(pago.dias_sin_verificar)}
                                    </Badge>
                                    <span className="font-semibold">
                                        L {formatoMonto(pago.monto)}
                                    </span>
                                    {puedeVerificar && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={VerificacionTransferenciaController.show.url(
                                                    pago.id,
                                                )}
                                            >
                                                Verificar
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold">Movimientos</h2>
                    {estado.anios.length > 0 && (
                        <Select
                            value={
                                estado.anio === null
                                    ? 'todos'
                                    : String(estado.anio)
                            }
                            onValueChange={elegirAnio}
                        >
                            <SelectTrigger className="w-44" aria-label="Año">
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
                </div>

                {estado.movimientos.length === 0 ? (
                    <p className="text-muted-foreground">
                        {estado.anio === null
                            ? 'Esta vivienda todavía no tiene cargos, pagos ni ajustes.'
                            : `No hay movimientos en ${estado.anio}.`}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Fecha</th>
                                    <th className="p-3">Concepto</th>
                                    <th className="p-3 text-right">Cargo</th>
                                    <th className="p-3 text-right">
                                        Pago o ajuste
                                    </th>
                                    <th className="p-3 text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                {estado.saldo_inicial !== null && (
                                    <tr className="border-t text-muted-foreground">
                                        <td className="p-3" colSpan={4}>
                                            Saldo al iniciar {estado.anio}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {textoSaldo(estado.saldo_inicial)}
                                        </td>
                                    </tr>
                                )}
                                {estado.movimientos.map((movimiento) => (
                                    <tr
                                        key={`${movimiento.clase}-${movimiento.id}`}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3 whitespace-nowrap">
                                            {formatoFecha(movimiento.fecha)}
                                        </td>
                                        <td className="p-3">
                                            <Detalle movimiento={movimiento} />
                                            {movimiento.migrado && (
                                                <Badge
                                                    variant="outline"
                                                    className="mt-1"
                                                >
                                                    Migrado
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {movimiento.debe &&
                                                `L ${formatoMonto(movimiento.debe)}`}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {movimiento.haber &&
                                                `− L ${formatoMonto(movimiento.haber)}`}
                                        </td>
                                        <td className="p-3 text-right font-medium whitespace-nowrap">
                                            {textoSaldo(movimiento.saldo)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {estado.sin_efecto.length > 0 && (
                <section className="space-y-3">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Sin efecto en el saldo
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Pagos anulados o transferencias rechazadas.
                        </p>
                    </div>
                    <ul className="space-y-2">
                        {estado.sin_efecto.map((pago) => (
                            <li
                                key={pago.id}
                                className="rounded-xl border p-4 text-sm"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Link
                                        href={PagoController.show.url(pago.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {FORMAS_PAGO[pago.forma_pago] ??
                                            pago.forma_pago}{' '}
                                        · {formatoFecha(pago.fecha_pago)}
                                    </Link>
                                    <span className="flex items-center gap-3">
                                        <Badge variant="outline">
                                            {pago.estado === 'anulado'
                                                ? 'Anulado'
                                                : 'Rechazada'}
                                        </Badge>
                                        <span className="text-muted-foreground line-through">
                                            L {formatoMonto(pago.monto)}
                                        </span>
                                    </span>
                                </div>
                                <p className="text-muted-foreground">
                                    {[
                                        pago.numero_recibo
                                            ? `Recibo ${pago.numero_recibo}`
                                            : null,
                                        pago.referencia,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {pago.motivo && (
                                    <p className="mt-1">
                                        Motivo: {pago.motivo}
                                        {pago.responsable &&
                                            ` (${pago.responsable})`}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

export default function EstadoCuenta({
    buscar,
    viviendas,
    estado,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
    estado: Estado | null;
}) {
    return (
        <>
            <Head
                title={
                    estado === null
                        ? 'Estado de cuenta'
                        : `Estado de cuenta · ${estado.vivienda.codigo}`
                }
            />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Estado de cuenta
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Cargos, pagos, abonos y ajustes de una vivienda con su
                        saldo acumulado.
                    </p>
                </div>

                {estado === null ? (
                    <Buscador buscar={buscar} viviendas={viviendas} />
                ) : (
                    <EstadoDeVivienda estado={estado} />
                )}
            </div>
        </>
    );
}
