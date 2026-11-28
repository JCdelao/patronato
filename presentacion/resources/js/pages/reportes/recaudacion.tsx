import { Form, Head } from '@inertiajs/react';
import { Filter } from 'lucide-react';
import ReporteRecaudacionController from '@/actions/Patronato/Presentacion/Controllers/ReporteRecaudacionController';
import EstadoPago from '@/components/finanzas/estado-pago';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatoMonto } from '@/lib/formato';

type Cobrador = { id: number; nombre: string };

type Agrupacion = 'dia' | 'mes' | 'anio';

type Filtros = {
    cobrador: number | null;
    desde: string;
    hasta: string;
    agrupar: Agrupacion;
};

type Montos = {
    pagos: number;
    efectivo: string;
    transferencia: string;
    total: string;
};

type PorEstado = { estado: string; pagos: number; monto: string };

type Reporte = {
    periodos: (Montos & { periodo: string })[];
    totales: Montos;
    pendientes: { pagos: number; monto: string; estados: PorEstado[] };
    sin_efecto: PorEstado[];
};

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const TITULO_PERIODO: Record<Agrupacion, string> = {
    dia: 'Día',
    mes: 'Mes',
    anio: 'Año',
};

const formatoPeriodo = (periodo: string, agrupar: Agrupacion) => {
    if (agrupar === 'anio') {
        return periodo;
    }

    return agrupar === 'mes'
        ? new Date(`${periodo}-01T00:00:00`).toLocaleDateString('es-HN', {
              month: 'long',
              year: 'numeric',
          })
        : new Date(`${periodo}T00:00:00`).toLocaleDateString('es-HN', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
};

function Dato({ titulo, valor }: { titulo: string; valor: string }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{titulo}</dt>
            <dd className="text-2xl font-semibold">{valor}</dd>
        </div>
    );
}

function TablaEstados({
    estados,
    pie,
}: {
    estados: PorEstado[];
    pie?: { pagos: number; monto: string };
}) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">
                <thead className="bg-muted text-left">
                    <tr>
                        <th className="p-3 font-medium">Estado</th>
                        <th className="p-3 text-right font-medium">Pagos</th>
                        <th className="p-3 text-right font-medium">Monto</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {estados.map((fila) => (
                        <tr key={fila.estado}>
                            <td className="p-3">
                                <EstadoPago estado={fila.estado} />
                            </td>
                            <td className="p-3 text-right">{fila.pagos}</td>
                            <td className="p-3 text-right">
                                L {formatoMonto(fila.monto)}
                            </td>
                        </tr>
                    ))}
                </tbody>
                {pie && (
                    <tfoot className="border-t-2">
                        <tr className="font-semibold">
                            <td className="p-3">Total pendiente</td>
                            <td className="p-3 text-right">{pie.pagos}</td>
                            <td className="p-3 text-right">
                                L {formatoMonto(pie.monto)}
                            </td>
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

export default function ReporteRecaudacion({
    cobradores,
    filtros,
    reporte,
}: {
    cobradores: Cobrador[];
    filtros: Filtros;
    reporte: Reporte;
}) {
    const conCobrador = filtros.cobrador !== null;

    return (
        <>
            <Head title="Recaudación" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Recaudación
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Pagos confirmados por efectivo y transferencia, para
                        controlar los ingresos por cuotas.
                    </p>
                </div>

                <Form
                    {...ReporteRecaudacionController.index.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="desde">Desde</Label>
                                <Input
                                    id="desde"
                                    name="desde"
                                    type="date"
                                    defaultValue={filtros.desde}
                                    aria-invalid={
                                        errors.desde ? true : undefined
                                    }
                                />
                                <InputError message={errors.desde} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="hasta">Hasta</Label>
                                <Input
                                    id="hasta"
                                    name="hasta"
                                    type="date"
                                    defaultValue={filtros.hasta}
                                    aria-invalid={
                                        errors.hasta ? true : undefined
                                    }
                                />
                                <InputError message={errors.hasta} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="cobrador">Cobrador</Label>
                                <select
                                    id="cobrador"
                                    name="cobrador"
                                    defaultValue={filtros.cobrador ?? ''}
                                    className={claseSelect}
                                    aria-invalid={
                                        errors.cobrador ? true : undefined
                                    }
                                >
                                    <option value="">Todos</option>
                                    {cobradores.map((cobrador) => (
                                        <option
                                            key={cobrador.id}
                                            value={cobrador.id}
                                        >
                                            {cobrador.nombre}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.cobrador} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="agrupar">Agrupar por</Label>
                                <select
                                    id="agrupar"
                                    name="agrupar"
                                    defaultValue={filtros.agrupar}
                                    className={claseSelect}
                                    aria-invalid={
                                        errors.agrupar ? true : undefined
                                    }
                                >
                                    <option value="dia">Día</option>
                                    <option value="mes">Mes</option>
                                    <option value="anio">Año</option>
                                </select>
                                <InputError message={errors.agrupar} />
                            </div>
                            <div className="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-4">
                                <Button disabled={processing}>
                                    <Filter />
                                    Filtrar
                                </Button>
                                {conCobrador && (
                                    <p className="text-sm text-muted-foreground">
                                        Con un cobrador elegido solo se muestra
                                        su efectivo; las transferencias no se
                                        incluyen.
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                </Form>

                <dl className="grid gap-3 sm:grid-cols-3">
                    <Dato
                        titulo="Efectivo"
                        valor={`L ${formatoMonto(reporte.totales.efectivo)}`}
                    />
                    <Dato
                        titulo="Transferencia"
                        valor={`L ${formatoMonto(reporte.totales.transferencia)}`}
                    />
                    <Dato
                        titulo={`Total recaudado (${reporte.totales.pagos} ${reporte.totales.pagos === 1 ? 'pago' : 'pagos'})`}
                        valor={`L ${formatoMonto(reporte.totales.total)}`}
                    />
                </dl>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        Recaudación confirmada
                    </h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted text-left">
                                <tr>
                                    <th className="p-3 font-medium">
                                        {TITULO_PERIODO[filtros.agrupar]}
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Pagos
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Efectivo
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Transferencia
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {reporte.periodos.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="p-4 text-center text-muted-foreground"
                                        >
                                            No hay pagos confirmados con estos
                                            filtros.
                                        </td>
                                    </tr>
                                )}
                                {reporte.periodos.map((fila) => (
                                    <tr key={fila.periodo}>
                                        <td className="p-3 capitalize">
                                            {formatoPeriodo(
                                                fila.periodo,
                                                filtros.agrupar,
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            {fila.pagos}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            L {formatoMonto(fila.efectivo)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            L {formatoMonto(fila.transferencia)}
                                        </td>
                                        <td className="p-3 text-right font-medium whitespace-nowrap">
                                            L {formatoMonto(fila.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t-2">
                                <tr className="font-semibold">
                                    <td className="p-3">Total</td>
                                    <td className="p-3 text-right">
                                        {reporte.totales.pagos}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        L{' '}
                                        {formatoMonto(reporte.totales.efectivo)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        L{' '}
                                        {formatoMonto(
                                            reporte.totales.transferencia,
                                        )}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        L {formatoMonto(reporte.totales.total)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Pendientes de verificar
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Transferencias del mismo rango que tesorería
                                todavía no confirma. No suman en la recaudación.
                            </p>
                            {conCobrador && (
                                <p className="mt-1 text-sm font-medium">
                                    Con un cobrador elegido no se muestran
                                    pendientes: el filtro solo aplica al
                                    efectivo y todos los pendientes son
                                    transferencias.
                                </p>
                            )}
                        </div>
                        <TablaEstados
                            estados={reporte.pendientes.estados}
                            pie={reporte.pendientes}
                        />
                    </section>

                    <section className="space-y-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Sin efecto
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Pagos anulados y transferencias rechazadas del
                                mismo rango. Solo son informativos: no suman en
                                la recaudación ni en los pendientes.
                            </p>
                        </div>
                        <TablaEstados estados={reporte.sin_efecto} />
                    </section>
                </div>
            </div>
        </>
    );
}
