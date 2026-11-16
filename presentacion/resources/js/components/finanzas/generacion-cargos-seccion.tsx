import { Form } from '@inertiajs/react';
import { CalendarCheck, RefreshCw } from 'lucide-react';
import GeneracionCargosController from '@/actions/Patronato/Presentacion/Controllers/GeneracionCargosController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { GeneracionCargos } from '@/types/finanzas';

// Muestra una fecha AAAA-MM-DD como "noviembre de 2026"
const nombrePeriodo = (fecha: string) =>
    new Date(`${fecha}T00:00:00Z`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

// Muestra fecha y hora en la hora de Honduras
const fechaHora = (fecha: string) =>
    new Date(fecha).toLocaleString('es-HN', {
        day: 'numeric',
        month: 'long',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'America/Tegucigalpa',
    });

export default function GeneracionCargosSeccion({
    generacion,
    periodoActual,
    puedeEditar,
}: {
    generacion: GeneracionCargos;
    periodoActual: string;
    puedeEditar: boolean;
}) {
    const { ultima } = generacion;

    return (
        <section className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold tracking-tight">
                    Generación de cargos
                </h2>
                <p className="mt-1 text-muted-foreground">
                    Cada mes se generan solos los cargos de todas las viviendas
                    activas: la cuota de su tipo, o su cuota especial, más los
                    conceptos activos
                </p>
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <div className="rounded-2xl border bg-card p-6">
                    <p className="text-sm text-muted-foreground">
                        {nombrePeriodo(periodoActual)}
                    </p>
                    {ultima ? (
                        <>
                            <p className="mt-1 flex items-center gap-2 text-xl font-semibold">
                                <CalendarCheck className="size-5 text-primary" />
                                Cargos generados
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                {ultima.automatica
                                    ? 'Automáticamente'
                                    : `Por ${ultima.usuario ?? 'un usuario'}`}{' '}
                                el {fechaHora(ultima.fecha)}
                                {ultima.cargos > 0 &&
                                    ` · ${ultima.cargos} cargos nuevos`}
                            </p>
                        </>
                    ) : (
                        <>
                            <p className="mt-1 text-xl font-semibold">
                                Todavía no se generan
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                Se generarán el día {generacion.dia}.
                            </p>
                        </>
                    )}

                    {puedeEditar && (
                        <Form
                            {...GeneracionCargosController.generar.form()}
                            options={{ preserveScroll: true }}
                            className="mt-4"
                        >
                            {({ processing }) => (
                                <Button variant="outline" disabled={processing}>
                                    {processing ? <Spinner /> : <RefreshCw />}
                                    Generar ahora
                                </Button>
                            )}
                        </Form>
                    )}
                    <p className="mt-2 text-sm text-muted-foreground">
                        Generar otra vez no duplica cargos: solo crea los que
                        falten, por ejemplo de una vivienda registrada después.
                    </p>
                </div>

                <div className="rounded-2xl border bg-card p-6">
                    {puedeEditar ? (
                        <Form
                            {...GeneracionCargosController.dia.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Label htmlFor="dia">
                                        Día del mes en que se generan
                                    </Label>
                                    <div className="flex gap-2">
                                        <Input
                                            id="dia"
                                            name="dia"
                                            type="number"
                                            min="1"
                                            max="28"
                                            defaultValue={generacion.dia}
                                            className="w-24"
                                            required
                                        />
                                        <Button disabled={processing}>
                                            {processing && <Spinner />}
                                            Guardar
                                        </Button>
                                    </div>
                                    <InputError message={errors.dia} />
                                    <p className="text-sm text-muted-foreground">
                                        Del 1 al 28, para que exista en todos
                                        los meses.
                                    </p>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground">
                            Los cargos se generan el día {generacion.dia} de
                            cada mes.
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}
