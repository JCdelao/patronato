import { Form, Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Save } from 'lucide-react';
import PresupuestoController from '@/actions/Patronato/Presentacion/Controllers/PresupuestoController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoMonto } from '@/lib/formato';

type Estado = 'sin_presupuesto' | 'normal' | 'alerta' | 'excedido';

type Fila = {
    categoria_id: number;
    categoria: string;
    activa: boolean;
    presupuestado: string | null;
    ejecutado: string;
    porcentaje: number | null;
    estado: Estado;
};

type Comparacion = {
    anio: number;
    categorias: Fila[];
    total_presupuestado: string;
    total_ejecutado: string;
    porcentaje_total: number | null;
};

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// Fondo de la fila según el avance del gasto
const FONDO: Record<Estado, string> = {
    sin_presupuesto: '',
    normal: '',
    alerta: 'bg-amber-50 dark:bg-amber-950/40',
    excedido: 'bg-red-50 dark:bg-red-950/40',
};

const BARRA: Record<Estado, string> = {
    sin_presupuesto: 'bg-muted-foreground/40',
    normal: 'bg-emerald-500',
    alerta: 'bg-amber-500',
    excedido: 'bg-red-500',
};

function EstadoPresupuesto({ estado }: { estado: Estado }) {
    if (estado === 'alerta') {
        return (
            <Badge className="bg-amber-500 text-white hover:bg-amber-500">
                En alerta
            </Badge>
        );
    }

    if (estado === 'excedido') {
        return <Badge variant="destructive">Excedido</Badge>;
    }

    return estado === 'sin_presupuesto' ? (
        <Badge variant="outline">Sin presupuesto</Badge>
    ) : null;
}

export default function Presupuesto({
    anios,
    umbral,
    comparacion,
}: {
    anios: { minimo: number; maximo: number; actual: number };
    umbral: number;
    comparacion: Comparacion;
}) {
    const { auth } = usePage().props;
    const puedeEditar = auth.permisos.includes('tesoreria.registrar');
    const listaAnios = Array.from(
        { length: Math.min(6, anios.maximo - anios.minimo + 1) },
        (_, i) => anios.maximo - i,
    );
    const resaltadas = comparacion.categorias.filter(
        (fila) => fila.estado === 'alerta' || fila.estado === 'excedido',
    );

    return (
        <>
            <Head title="Presupuesto" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Presupuesto {comparacion.anio}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Gasto planeado por categoría frente a los egresos
                            vigentes del año
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="anio">Año</Label>
                        <select
                            id="anio"
                            value={comparacion.anio}
                            onChange={(evento) =>
                                router.get(
                                    PresupuestoController.index.url(),
                                    { anio: evento.target.value },
                                    { preserveScroll: true },
                                )
                            }
                            className={claseSelect}
                        >
                            {listaAnios.map((anio) => (
                                <option key={anio} value={anio}>
                                    {anio}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {resaltadas.length > 0 && (
                    <div className="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/40">
                        <AlertTriangle className="size-5 shrink-0 text-amber-600" />
                        <p>
                            {resaltadas.length === 1
                                ? '1 categoría llegó'
                                : `${resaltadas.length} categorías llegaron`}{' '}
                            al {umbral} % o más de su presupuesto:{' '}
                            {resaltadas
                                .map((fila) => fila.categoria)
                                .join(', ')}
                            .
                        </p>
                    </div>
                )}

                <Form
                    {...PresupuestoController.guardar.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="anio"
                                value={comparacion.anio}
                            />
                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3">Categoría</th>
                                            <th className="p-3 text-right">
                                                Presupuestado
                                            </th>
                                            <th className="p-3 text-right">
                                                Ejecutado
                                            </th>
                                            <th className="w-40 p-3">Avance</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {comparacion.categorias.length ===
                                            0 && (
                                            <tr>
                                                <td
                                                    colSpan={4}
                                                    className="p-3 text-muted-foreground"
                                                >
                                                    No hay categorías de egreso.
                                                </td>
                                            </tr>
                                        )}
                                        {comparacion.categorias.map(
                                            (fila, i) => (
                                                <tr
                                                    key={fila.categoria_id}
                                                    className={
                                                        FONDO[fila.estado]
                                                    }
                                                >
                                                    <td className="p-3">
                                                        <span className="font-medium">
                                                            {fila.categoria}
                                                        </span>
                                                        {!fila.activa && (
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                (inactiva)
                                                            </span>
                                                        )}
                                                        <div className="mt-1">
                                                            <EstadoPresupuesto
                                                                estado={
                                                                    fila.estado
                                                                }
                                                            />
                                                        </div>
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        {puedeEditar ? (
                                                            <>
                                                                <input
                                                                    type="hidden"
                                                                    name={`montos[${i}][categoria_id]`}
                                                                    value={
                                                                        fila.categoria_id
                                                                    }
                                                                />
                                                                <Input
                                                                    name={`montos[${i}][monto]`}
                                                                    type="number"
                                                                    min="0"
                                                                    step="0.01"
                                                                    inputMode="decimal"
                                                                    defaultValue={
                                                                        fila.presupuestado ??
                                                                        ''
                                                                    }
                                                                    placeholder="Sin presupuesto"
                                                                    aria-label={`Presupuesto de ${fila.categoria}`}
                                                                    className="ml-auto w-36 text-right"
                                                                />
                                                                <InputError
                                                                    message={
                                                                        (
                                                                            errors as Record<
                                                                                string,
                                                                                string
                                                                            >
                                                                        )[
                                                                            `montos.${i}.monto`
                                                                        ]
                                                                    }
                                                                />
                                                            </>
                                                        ) : fila.presupuestado ===
                                                          null ? (
                                                            '—'
                                                        ) : (
                                                            `L ${formatoMonto(fila.presupuestado)}`
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        L{' '}
                                                        {formatoMonto(
                                                            fila.ejecutado,
                                                        )}
                                                    </td>
                                                    <td className="p-3">
                                                        <Avance
                                                            porcentaje={
                                                                fila.porcentaje
                                                            }
                                                            estado={fila.estado}
                                                        />
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                    <tfoot className="border-t font-semibold">
                                        <tr>
                                            <td className="p-3">Total</td>
                                            <td className="p-3 text-right">
                                                L{' '}
                                                {formatoMonto(
                                                    comparacion.total_presupuestado,
                                                )}
                                            </td>
                                            <td className="p-3 text-right">
                                                L{' '}
                                                {formatoMonto(
                                                    comparacion.total_ejecutado,
                                                )}
                                            </td>
                                            <td className="p-3">
                                                {comparacion.porcentaje_total ===
                                                null
                                                    ? '—'
                                                    : `${comparacion.porcentaje_total} %`}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <InputError message={errors.anio} />
                            {puedeEditar &&
                                comparacion.categorias.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <Button disabled={processing}>
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Save />
                                            )}
                                            Guardar presupuesto
                                        </Button>
                                        <p className="text-sm text-muted-foreground">
                                            Deja vacío un monto para quitar el
                                            presupuesto de esa categoría.
                                        </p>
                                    </div>
                                )}
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function Avance({
    porcentaje,
    estado,
}: {
    porcentaje: number | null;
    estado: Estado;
}) {
    if (porcentaje === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="space-y-1">
            <div
                className="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={porcentaje}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    className={`h-full rounded-full ${BARRA[estado]}`}
                    style={{ width: `${Math.min(porcentaje, 100)}%` }}
                />
            </div>
            <span className="text-xs">{porcentaje} %</span>
        </div>
    );
}
