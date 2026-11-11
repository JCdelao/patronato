import { Form, Head } from '@inertiajs/react';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ModalidadController from '@/actions/Patronato/Presentacion/Controllers/ModalidadController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type TipoModalidad = 'unificada' | 'diversificada';

type FilaDesglose = { concepto: string; monto: string };

type Modalidad = {
    modalidad: TipoModalidad;
    desde: string;
    desglose: FilaDesglose[];
};

const OPCIONES: { valor: TipoModalidad; titulo: string; texto: string }[] = [
    {
        valor: 'unificada',
        titulo: 'Unificada',
        texto: 'Una sola cuota mensual que incluye agua, mantenimiento y vigilancia.',
    },
    {
        valor: 'diversificada',
        titulo: 'Diversificada',
        texto: 'Un cargo separado por cada concepto, cada uno con su propio monto.',
    },
];

const nombreModalidad = (modalidad: TipoModalidad) =>
    modalidad === 'unificada' ? 'Unificada' : 'Diversificada';

// Muestra una fecha AAAA-MM-DD como "noviembre de 2026"
const nombrePeriodo = (fecha: string) =>
    new Date(`${fecha}T00:00:00Z`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

const formatoMonto = (monto: number) =>
    monto.toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

export default function ModalidadPagina({
    actual,
    programada,
    periodoActual,
    puedeEditar,
}: {
    actual: Modalidad | null;
    programada: Modalidad | null;
    periodoActual: string;
    puedeEditar: boolean;
}) {
    const inicial = programada ?? actual;

    const [modalidad, setModalidad] = useState<TipoModalidad>(
        inicial?.modalidad ?? 'unificada',
    );
    const [filas, setFilas] = useState<FilaDesglose[]>(inicial?.desglose ?? []);

    const total = filas.reduce(
        (suma, fila) => suma + (Number(fila.monto) || 0),
        0,
    );

    const cambiarFila = (indice: number, cambio: Partial<FilaDesglose>) =>
        setFilas((actuales) =>
            actuales.map((fila, i) =>
                i === indice ? { ...fila, ...cambio } : fila,
            ),
        );

    const siguientePeriodo = (() => {
        const fecha = new Date(`${periodoActual}T00:00:00Z`);
        fecha.setUTCMonth(fecha.getUTCMonth() + 1);

        return fecha.toISOString().slice(0, 10);
    })();

    const esCambio = actual !== null && modalidad !== actual.modalidad;

    return (
        <>
            <Head title="Modalidad de cobro" />

            <div className="max-w-3xl space-y-6 p-6 md:p-10">
                <div>
                    <h1 className="text-4xl font-bold tracking-tight">
                        Modalidad de cobro
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Define si la mensualidad se cobra como una sola cuota o
                        con un cargo por cada concepto.
                    </p>
                </div>

                <section className="rounded-2xl border bg-card p-6">
                    <p className="text-sm text-muted-foreground">
                        Modalidad actual
                    </p>
                    <p className="mt-1 text-2xl font-bold tracking-tight">
                        {actual
                            ? nombreModalidad(actual.modalidad)
                            : 'Unificada (sin definir)'}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        {actual
                            ? `Desde ${nombrePeriodo(actual.desde)}`
                            : 'Mientras no se defina, los cargos se generan como cuota unificada.'}
                    </p>
                </section>

                {programada && (
                    <Alert>
                        <CalendarClock />
                        <AlertTitle>Cambio programado</AlertTitle>
                        <AlertDescription>
                            Desde {nombrePeriodo(programada.desde)} la modalidad
                            será{' '}
                            {nombreModalidad(
                                programada.modalidad,
                            ).toLowerCase()}
                            . Los cargos ya generados no cambian.
                        </AlertDescription>
                    </Alert>
                )}

                {puedeEditar && (
                    <Form
                        {...ModalidadController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-6 rounded-2xl border bg-card p-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <fieldset className="grid gap-3 sm:grid-cols-2">
                                    <legend className="mb-3 text-lg font-semibold">
                                        Elegir modalidad
                                    </legend>
                                    {OPCIONES.map((opcion) => (
                                        <label
                                            key={opcion.valor}
                                            className="flex cursor-pointer gap-3 rounded-xl border p-4 has-checked:border-primary has-checked:bg-primary/5"
                                        >
                                            <input
                                                type="radio"
                                                name="modalidad"
                                                value={opcion.valor}
                                                checked={
                                                    modalidad === opcion.valor
                                                }
                                                onChange={() =>
                                                    setModalidad(opcion.valor)
                                                }
                                                className="mt-1 accent-primary"
                                            />
                                            <span>
                                                <span className="block font-medium">
                                                    {opcion.titulo}
                                                </span>
                                                <span className="block text-sm text-muted-foreground">
                                                    {opcion.texto}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </fieldset>
                                <InputError message={errors.modalidad} />

                                {modalidad === 'unificada' ? (
                                    <div className="space-y-3">
                                        <div>
                                            <h2 className="text-lg font-semibold">
                                                Desglose informativo
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Muestra qué incluye la cuota,
                                                por ejemplo agua, mantenimiento
                                                y vigilancia. Es solo
                                                informativo: lo que se cobra es
                                                la cuota mensual de cada
                                                vivienda.
                                            </p>
                                        </div>

                                        {filas.map((fila, indice) => (
                                            <div
                                                key={indice}
                                                className="grid grid-cols-[1fr_8rem_auto] items-start gap-2"
                                            >
                                                <div className="grid gap-1">
                                                    <Label
                                                        htmlFor={`concepto-${indice}`}
                                                        className="sr-only"
                                                    >
                                                        Concepto
                                                    </Label>
                                                    <Input
                                                        id={`concepto-${indice}`}
                                                        name={`desglose[${indice}][concepto]`}
                                                        value={fila.concepto}
                                                        onChange={(evento) =>
                                                            cambiarFila(
                                                                indice,
                                                                {
                                                                    concepto:
                                                                        evento
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="Agua"
                                                        maxLength={100}
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `desglose.${indice}.concepto`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label
                                                        htmlFor={`monto-${indice}`}
                                                        className="sr-only"
                                                    >
                                                        Monto en lempiras
                                                    </Label>
                                                    <Input
                                                        id={`monto-${indice}`}
                                                        name={`desglose[${indice}][monto]`}
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={fila.monto}
                                                        onChange={(evento) =>
                                                            cambiarFila(
                                                                indice,
                                                                {
                                                                    monto: evento
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="L. 0.00"
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `desglose.${indice}.monto`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Quitar ${fila.concepto || 'concepto'}`}
                                                    onClick={() =>
                                                        setFilas((actuales) =>
                                                            actuales.filter(
                                                                (_, i) =>
                                                                    i !==
                                                                    indice,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        ))}

                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={filas.length >= 10}
                                                onClick={() =>
                                                    setFilas((actuales) => [
                                                        ...actuales,
                                                        {
                                                            concepto: '',
                                                            monto: '',
                                                        },
                                                    ])
                                                }
                                            >
                                                <Plus />
                                                Concepto
                                            </Button>
                                            {filas.length > 0 && (
                                                <p className="font-medium">
                                                    Total: L.{' '}
                                                    {formatoMonto(total)}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <p className="rounded-xl bg-muted p-4 text-sm text-muted-foreground">
                                        Cada concepto de cobro activo tendrá su
                                        propio monto y aparecerá como una línea
                                        separada en el estado de cuenta.
                                    </p>
                                )}

                                {esCambio && (
                                    <p className="text-sm text-muted-foreground">
                                        El cambio se aplicará desde{' '}
                                        {nombrePeriodo(siguientePeriodo)}. Los
                                        cargos del período en curso y los ya
                                        generados no cambian.
                                    </p>
                                )}

                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Guardar
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

ModalidadPagina.layout = {
    breadcrumbs: [
        {
            title: 'Modalidad de cobro',
            href: ModalidadController.index(),
        },
    ],
};
