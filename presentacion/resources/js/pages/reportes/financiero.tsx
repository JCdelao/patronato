import { Form, Head, usePage } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Filter } from 'lucide-react';
import { useState } from 'react';
import InformeFinancieroController from '@/actions/Patronato/Presentacion/Controllers/InformeFinancieroController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFecha, formatoMonto } from '@/lib/formato';

type Tipo = 'mensual' | 'anual';

type Periodo = { tipo: Tipo; anio: number; mes: number };

type Fila = { categoria: string; total: string };

type Mes = {
    mes: string;
    saldo_inicial: string;
    apertura: string | null;
    ingresos: string;
    egresos: string;
    saldo_final: string;
};

type Informe = {
    tipo: Tipo;
    titulo: string;
    desde: string;
    hasta: string;
    saldo_inicial: string;
    apertura: { fecha: string; monto: string } | null;
    ingresos: Fila[];
    egresos: Fila[];
    total_ingresos: string;
    total_egresos: string;
    saldo_final: string;
    meses: Mes[];
};

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const MESES = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const nombreMes = (mes: string) => MESES[Number(mes.slice(5, 7)) - 1];

export default function InformeFinanciero({
    periodo,
    hoy,
    apertura,
    informe,
}: {
    periodo: Periodo;
    hoy: string;
    apertura: { monto: string | null; fecha: string | null };
    informe: Informe;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('tesoreria.registrar');
    const [tipo, setTipo] = useState<Tipo>(periodo.tipo);
    const anioActual = Number(hoy.slice(0, 4));
    const anios = Array.from({ length: 6 }, (_, i) => anioActual - i);

    // La exportación usa el mismo período que la pantalla
    const consulta =
        periodo.tipo === 'anual'
            ? { tipo: 'anual', anio: periodo.anio }
            : { tipo: 'mensual', anio: periodo.anio, mes: periodo.mes };

    return (
        <>
            <Head title="Informe financiero" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Informe financiero
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Saldo inicial, ingresos y egresos por categoría y saldo
                        final. Los movimientos anulados no suman.
                    </p>
                </div>

                <Form
                    {...InformeFinancieroController.index.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 rounded-xl border p-4 sm:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Informe</Label>
                                <select
                                    id="tipo"
                                    name="tipo"
                                    value={tipo}
                                    onChange={(evento) =>
                                        setTipo(evento.target.value as Tipo)
                                    }
                                    className={claseSelect}
                                >
                                    <option value="mensual">Mensual</option>
                                    <option value="anual">Anual</option>
                                </select>
                                <InputError message={errors.tipo} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="anio">Año</Label>
                                <select
                                    id="anio"
                                    name="anio"
                                    defaultValue={periodo.anio}
                                    className={claseSelect}
                                >
                                    {anios.map((anio) => (
                                        <option key={anio} value={anio}>
                                            {anio}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.anio} />
                            </div>
                            {tipo === 'mensual' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="mes">Mes</Label>
                                    <select
                                        id="mes"
                                        name="mes"
                                        defaultValue={periodo.mes}
                                        className={claseSelect}
                                    >
                                        {MESES.map((nombre, i) => (
                                            <option key={nombre} value={i + 1}>
                                                {nombre}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.mes} />
                                </div>
                            )}
                            <div className="flex items-end">
                                <Button disabled={processing}>
                                    <Filter />
                                    Ver informe
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <a
                            href={InformeFinancieroController.exportar.url(
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
                            href={InformeFinancieroController.exportar.url(
                                { formato: 'excel' },
                                { query: consulta },
                            )}
                        >
                            <FileSpreadsheet />
                            Exportar Excel
                        </a>
                    </Button>
                </div>

                <section className="space-y-4 rounded-xl border p-4">
                    <div>
                        <h2 className="text-xl font-semibold">
                            {informe.titulo}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Del {formatoFecha(informe.desde)} al{' '}
                            {formatoFecha(informe.hasta)}
                        </p>
                    </div>

                    <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-2">
                        <dt className="font-medium">Saldo inicial</dt>
                        <dd className="text-right font-medium">
                            L {formatoMonto(informe.saldo_inicial)}
                        </dd>
                        {informe.apertura && (
                            <>
                                <dt className="text-muted-foreground">
                                    Saldo de apertura del{' '}
                                    {formatoFecha(informe.apertura.fecha)}
                                </dt>
                                <dd className="text-right">
                                    L {formatoMonto(informe.apertura.monto)}
                                </dd>
                            </>
                        )}
                    </dl>

                    <Categorias
                        titulo="Ingresos por categoría"
                        filas={informe.ingresos}
                        total={informe.total_ingresos}
                        vacio="Sin ingresos en el período."
                    />
                    <Categorias
                        titulo="Egresos por categoría"
                        filas={informe.egresos}
                        total={informe.total_egresos}
                        vacio="Sin egresos en el período."
                    />

                    <dl className="grid grid-cols-[1fr_auto] gap-x-6 border-t pt-3 text-lg">
                        <dt className="font-bold">Saldo final</dt>
                        <dd className="text-right font-bold">
                            L {formatoMonto(informe.saldo_final)}
                        </dd>
                    </dl>
                </section>

                {informe.meses.length > 0 && (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Mes</th>
                                    <th className="p-3 text-right">
                                        Saldo inicial
                                    </th>
                                    <th className="p-3 text-right">Ingresos</th>
                                    <th className="p-3 text-right">Egresos</th>
                                    <th className="p-3 text-right">
                                        Saldo final
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {informe.meses.map((fila) => (
                                    <tr key={fila.mes}>
                                        <td className="p-3">
                                            {nombreMes(fila.mes)}
                                            {fila.apertura && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Apertura L{' '}
                                                    {formatoMonto(
                                                        fila.apertura,
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            L {formatoMonto(fila.saldo_inicial)}
                                        </td>
                                        <td className="p-3 text-right">
                                            L {formatoMonto(fila.ingresos)}
                                        </td>
                                        <td className="p-3 text-right">
                                            L {formatoMonto(fila.egresos)}
                                        </td>
                                        <td className="p-3 text-right font-medium">
                                            L {formatoMonto(fila.saldo_final)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {puedeRegistrar && (
                    <Form
                        {...InformeFinancieroController.apertura.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4 rounded-xl border p-4 sm:grid-cols-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="sm:col-span-3">
                                    <h2 className="font-semibold">
                                        Saldo de apertura
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Dinero que tenía el patronato el día en
                                        que empezó a registrar en el sistema.
                                        Entra al informe en esa fecha. Cada
                                        cambio queda en la bitácora.
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="apertura_monto">
                                        Monto (L.)
                                    </Label>
                                    <Input
                                        id="apertura_monto"
                                        name="monto"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        defaultValue={apertura.monto ?? ''}
                                        required
                                    />
                                    <InputError message={errors.monto} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="apertura_fecha">
                                        Fecha
                                    </Label>
                                    <Input
                                        id="apertura_fecha"
                                        name="fecha"
                                        type="date"
                                        max={hoy}
                                        defaultValue={apertura.fecha ?? ''}
                                        required
                                    />
                                    <InputError message={errors.fecha} />
                                </div>
                                <div className="flex items-end">
                                    <Button
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        Guardar apertura
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

function Categorias({
    titulo,
    filas,
    total,
    vacio,
}: {
    titulo: string;
    filas: Fila[];
    total: string;
    vacio: string;
}) {
    return (
        <div className="space-y-2">
            <h3 className="text-sm font-medium text-muted-foreground">
                {titulo}
            </h3>
            <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1">
                {filas.length === 0 ? (
                    <dt className="text-sm text-muted-foreground">{vacio}</dt>
                ) : (
                    filas.map((fila) => (
                        <div key={fila.categoria} className="contents">
                            <dt>{fila.categoria}</dt>
                            <dd className="text-right">
                                L {formatoMonto(fila.total)}
                            </dd>
                        </div>
                    ))
                )}
                <dt className="font-medium">Total</dt>
                <dd className="text-right font-medium">
                    L {formatoMonto(total)}
                </dd>
            </dl>
        </div>
    );
}
