import { Form, Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Filter } from 'lucide-react';
import ReporteCobrosController from '@/actions/Patronato/Presentacion/Controllers/ReporteCobrosController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatoMonto } from '@/lib/formato';

type Cobrador = { id: number; nombre: string };

type Filtros = {
    cobrador: number | null;
    desde: string;
    hasta: string;
    estado: 'todas' | 'abierta' | 'cerrada';
    agrupar: 'dia' | 'mes';
};

type FilaResumen = {
    cobrador_id: number;
    cobrador: string;
    periodo: string;
    cobros: number;
    total: string;
};

type Subtotal = {
    cobrador_id: number;
    cobrador: string;
    cobros: number;
    total: string;
};

type Reporte = {
    resumen: FilaResumen[];
    subtotales: Subtotal[];
    total_cobros: number;
    total: string;
    anulados: number;
    monto_anulado: string;
};

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const formatoPeriodo = (periodo: string, agrupar: Filtros['agrupar']) =>
    agrupar === 'mes'
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

export default function ReporteCobros({
    cobradores,
    filtros,
    reporte,
}: {
    cobradores: Cobrador[];
    filtros: Filtros;
    reporte: Reporte;
}) {
    // La exportación usa los mismos filtros que la pantalla
    const consulta = {
        cobrador: filtros.cobrador ?? undefined,
        desde: filtros.desde,
        hasta: filtros.hasta,
        estado: filtros.estado,
        agrupar: filtros.agrupar,
    };

    return (
        <>
            <Head title="Cobros por cobrador" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Cobros por cobrador
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Recaudación en efectivo por día o por mes
                    </p>
                </div>

                <Form
                    {...ReporteCobrosController.index.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2 lg:col-span-2">
                                <Label htmlFor="cobrador">Cobrador</Label>
                                <select
                                    id="cobrador"
                                    name="cobrador"
                                    defaultValue={filtros.cobrador ?? ''}
                                    className={claseSelect}
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
                                <Label htmlFor="desde">Desde</Label>
                                <Input
                                    id="desde"
                                    name="desde"
                                    type="date"
                                    defaultValue={filtros.desde}
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
                                />
                                <InputError message={errors.hasta} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="estado">Cierre de caja</Label>
                                <select
                                    id="estado"
                                    name="estado"
                                    defaultValue={filtros.estado}
                                    className={claseSelect}
                                >
                                    <option value="todas">Todas</option>
                                    <option value="abierta">Abiertas</option>
                                    <option value="cerrada">Cerradas</option>
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="agrupar">Agrupar por</Label>
                                <select
                                    id="agrupar"
                                    name="agrupar"
                                    defaultValue={filtros.agrupar}
                                    className={claseSelect}
                                >
                                    <option value="dia">Día</option>
                                    <option value="mes">Mes</option>
                                </select>
                            </div>
                            <div className="flex items-end sm:col-span-2 lg:col-span-4">
                                <Button disabled={processing}>
                                    <Filter />
                                    Filtrar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <a
                            href={ReporteCobrosController.exportar.url(
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
                            href={ReporteCobrosController.exportar.url(
                                { formato: 'excel' },
                                { query: consulta },
                            )}
                        >
                            <FileSpreadsheet />
                            Exportar Excel
                        </a>
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted text-left">
                            <tr>
                                <th className="p-3 font-medium">Cobrador</th>
                                <th className="p-3 font-medium">
                                    {filtros.agrupar === 'mes' ? 'Mes' : 'Día'}
                                </th>
                                <th className="p-3 text-right font-medium">
                                    Cobros
                                </th>
                                <th className="p-3 text-right font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {reporte.subtotales.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-4 text-center text-muted-foreground"
                                    >
                                        No hay cobros con estos filtros.
                                    </td>
                                </tr>
                            )}
                            {reporte.subtotales.map((subtotal) => (
                                <FilasCobrador
                                    key={subtotal.cobrador_id}
                                    subtotal={subtotal}
                                    filas={reporte.resumen.filter(
                                        (fila) =>
                                            fila.cobrador_id ===
                                            subtotal.cobrador_id,
                                    )}
                                    agrupar={filtros.agrupar}
                                />
                            ))}
                        </tbody>
                        <tfoot className="border-t-2">
                            <tr className="font-semibold">
                                <td className="p-3" colSpan={2}>
                                    Total
                                </td>
                                <td className="p-3 text-right">
                                    {reporte.total_cobros}
                                </td>
                                <td className="p-3 text-right">
                                    L {formatoMonto(reporte.total)}
                                </td>
                            </tr>
                            {reporte.anulados > 0 && (
                                <tr className="text-muted-foreground">
                                    <td className="p-3" colSpan={2}>
                                        Anulados (no suman)
                                    </td>
                                    <td className="p-3 text-right">
                                        {reporte.anulados}
                                    </td>
                                    <td className="p-3 text-right">
                                        L {formatoMonto(reporte.monto_anulado)}
                                    </td>
                                </tr>
                            )}
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}

function FilasCobrador({
    subtotal,
    filas,
    agrupar,
}: {
    subtotal: Subtotal;
    filas: FilaResumen[];
    agrupar: Filtros['agrupar'];
}) {
    return (
        <>
            {filas.map((fila) => (
                <tr key={`${fila.cobrador_id}-${fila.periodo}`}>
                    <td className="p-3">{fila.cobrador}</td>
                    <td className="p-3 capitalize">
                        {formatoPeriodo(fila.periodo, agrupar)}
                    </td>
                    <td className="p-3 text-right">{fila.cobros}</td>
                    <td className="p-3 text-right">
                        L {formatoMonto(fila.total)}
                    </td>
                </tr>
            ))}
            <tr className="bg-muted/50 font-medium">
                <td className="p-3" colSpan={2}>
                    Subtotal {subtotal.cobrador}
                </td>
                <td className="p-3 text-right">{subtotal.cobros}</td>
                <td className="p-3 text-right">
                    L {formatoMonto(subtotal.total)}
                </td>
            </tr>
        </>
    );
}
