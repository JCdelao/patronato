import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import EstadoCuentaController from '@/actions/Patronato/Presentacion/Controllers/EstadoCuentaController';
import ReporteMorosidadController from '@/actions/Patronato/Presentacion/Controllers/ReporteMorosidadController';
import EstadoMora from '@/components/finanzas/estado-mora';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatoFecha, formatoMonto } from '@/lib/formato';

type Orden = 'monto' | 'antiguedad';

type Fila = {
    vivienda_id: number;
    codigo: string;
    activa: boolean;
    responsable: string | null;
    saldo: string;
    por_verificar: string;
    meses_adeudados: number;
    deuda_desde: string | null;
    meses_vencidos: number;
    vencido: string;
    estado: 'al_dia' | 'en_mora';
};

type Reporte = {
    orden: Orden;
    fecha_corte: string;
    viviendas: Fila[];
    total: string;
    total_vencido: string;
    en_mora: number;
};

const ORDENES: { valor: Orden; texto: string }[] = [
    { valor: 'monto', texto: 'Mayor monto' },
    { valor: 'antiguedad', texto: 'Deuda más antigua' },
];

// Mes y año del período, por ejemplo "septiembre de 2026"
const formatoMes = (periodo: string) =>
    new Date(`${periodo}T00:00:00`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
    });

function Dato({ titulo, valor }: { titulo: string; valor: string }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{titulo}</dt>
            <dd className="text-2xl font-semibold">{valor}</dd>
        </div>
    );
}

export default function ReporteMorosidad({ reporte }: { reporte: Reporte }) {
    const { auth } = usePage().props;
    const veEstadoCuenta = auth.permisos.includes('cobros.ver');
    const consulta = { orden: reporte.orden };

    const ordenar = (orden: Orden) =>
        router.get(
            ReporteMorosidadController.index.url(),
            { orden },
            { preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Reporte de morosidad" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Reporte de morosidad
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Viviendas con saldo pendiente al{' '}
                        {formatoFecha(reporte.fecha_corte)}, para organizar las
                        gestiones de cobro.
                    </p>
                </div>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Dato
                        titulo="Viviendas con saldo"
                        valor={String(reporte.viviendas.length)}
                    />
                    <Dato titulo="En mora" valor={String(reporte.en_mora)} />
                    <Dato
                        titulo="Vencido"
                        valor={`L ${formatoMonto(reporte.total_vencido)}`}
                    />
                    <Dato
                        titulo="Saldo total"
                        valor={`L ${formatoMonto(reporte.total)}`}
                    />
                </dl>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div
                        role="group"
                        aria-label="Ordenar por"
                        className="inline-flex rounded-md border p-1"
                    >
                        {ORDENES.map((opcion) => (
                            <Button
                                key={opcion.valor}
                                size="sm"
                                variant={
                                    reporte.orden === opcion.valor
                                        ? 'default'
                                        : 'ghost'
                                }
                                aria-pressed={reporte.orden === opcion.valor}
                                onClick={() => ordenar(opcion.valor)}
                            >
                                {opcion.texto}
                            </Button>
                        ))}
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a
                                href={ReporteMorosidadController.exportar.url(
                                    { formato: 'pdf' },
                                    { query: consulta },
                                )}
                            >
                                <FileText />
                                Exportar PDF
                            </a>
                        </Button>
                        <Button asChild variant="outline">
                            <a
                                href={ReporteMorosidadController.exportar.url(
                                    { formato: 'excel' },
                                    { query: consulta },
                                )}
                            >
                                <FileSpreadsheet />
                                Exportar Excel
                            </a>
                        </Button>
                    </div>
                </div>

                {reporte.viviendas.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-muted-foreground">
                        No hay viviendas con saldo pendiente.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted text-left">
                                <tr>
                                    <th className="p-3 font-medium">
                                        Vivienda
                                    </th>
                                    <th className="p-3 font-medium">
                                        Responsable de pago
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Meses adeudados
                                    </th>
                                    <th className="p-3 font-medium">
                                        Deuda desde
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Vencido
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Saldo
                                    </th>
                                    <th className="p-3 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reporte.viviendas.map((fila) => (
                                    <tr
                                        key={fila.vivienda_id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3 font-medium whitespace-nowrap">
                                            {veEstadoCuenta ? (
                                                <Link
                                                    href={EstadoCuentaController.show.url(
                                                        fila.vivienda_id,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {fila.codigo}
                                                </Link>
                                            ) : (
                                                fila.codigo
                                            )}
                                            {!fila.activa && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Inactiva
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {fila.responsable ?? (
                                                <span className="text-muted-foreground">
                                                    Sin responsable
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            {fila.meses_adeudados}
                                            {fila.meses_vencidos > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {fila.meses_vencidos}{' '}
                                                    vencido
                                                    {fila.meses_vencidos === 1
                                                        ? ''
                                                        : 's'}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 whitespace-nowrap first-letter:uppercase">
                                            {fila.deuda_desde
                                                ? formatoMes(fila.deuda_desde)
                                                : '—'}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            L {formatoMonto(fila.vencido)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            <span className="font-semibold">
                                                L {formatoMonto(fila.saldo)}
                                            </span>
                                            {Number(fila.por_verificar) > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    L{' '}
                                                    {formatoMonto(
                                                        fila.por_verificar,
                                                    )}{' '}
                                                    por verificar
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 whitespace-nowrap">
                                            <EstadoMora mora={fila} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
