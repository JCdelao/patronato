import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import EntregaTurnoController from '@/actions/Patronato/Presentacion/Controllers/EntregaTurnoController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { EntregaPendiente, TurnoProgramado } from '@/types/vigilancia';
import { TURNOS, estadoTurno, nombreDia } from '@/types/vigilancia';

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

type Receptor = { id: number; nombre: string };

// Turnos del guardia de la cuenta: el actual para iniciarlo o entregarlo, los próximos y los de la última semana
export default function MisTurnos({
    guardia,
    turnos,
    pendiente,
    actual,
    receptores,
    sugerido,
}: {
    guardia: string;
    turnos: TurnoProgramado[];
    pendiente: EntregaPendiente | null;
    actual: TurnoProgramado | null;
    receptores: Receptor[];
    sugerido: number | null;
}) {
    const proximos = turnos.filter(
        (turno) => !turno.empezo && turno.id !== actual?.id,
    );
    const anteriores = turnos
        .filter((turno) => turno.empezo && turno.id !== actual?.id)
        .reverse();

    return (
        <>
            <Head title="Mis turnos" />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Mis turnos
                    </h1>
                    <p className="mt-1 text-muted-foreground">{guardia}</p>
                </div>

                {pendiente && <Recibir pendiente={pendiente} />}
                {actual &&
                    (actual.inicio_real ? (
                        <Entregar
                            turno={actual}
                            receptores={receptores}
                            sugerido={sugerido}
                        />
                    ) : (
                        <Iniciar turno={actual} />
                    ))}

                <Lista
                    titulo="Próximos"
                    turnos={proximos}
                    vacio="No tienes turnos programados."
                />
                {anteriores.length > 0 && (
                    <Lista titulo="Recientes" turnos={anteriores} vacio="" />
                )}
            </div>
        </>
    );
}

// Otro guardia entregó el turno a este y falta que lo confirme
function Recibir({ pendiente }: { pendiente: EntregaPendiente }) {
    return (
        <section className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
            <div>
                <h2 className="text-lg font-semibold">
                    {pendiente.entrega} te entregó el turno
                </h2>
                <p className="text-sm text-muted-foreground">
                    A las {pendiente.entregado_en.slice(11)} del{' '}
                    {nombreDia(pendiente.entregado_en).toLowerCase()}. Su turno
                    fue de {TURNOS[pendiente.tipo].toLowerCase()}, de{' '}
                    {pendiente.inicio.slice(11)} a {pendiente.fin.slice(11)}.
                </p>
            </div>
            <Novedades texto={pendiente.novedades} />
            <Form
                {...EntregaTurnoController.recibir.form(pendiente.id)}
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            Confirmar que lo recibí
                        </Button>
                        <InputError message={errors.entrega} />
                    </>
                )}
            </Form>
        </section>
    );
}

// Turno que empieza dentro de una hora o que ya debía empezar
function Iniciar({ turno }: { turno: TurnoProgramado }) {
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div>
                <h2 className="text-lg font-semibold">Turno de hoy</h2>
                <p className="text-sm text-muted-foreground">
                    {TURNOS[turno.tipo]} · {rangoHoras(turno)}
                </p>
            </div>
            <Form
                {...EntregaTurnoController.iniciar.form(turno.id)}
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            Iniciar turno
                        </Button>
                        <InputError message={errors.turno} />
                    </>
                )}
            </Form>
        </section>
    );
}

// Turno en curso: al terminarlo se anotan las novedades y, si hay, el guardia que lo releva
function Entregar({
    turno,
    receptores,
    sugerido,
}: {
    turno: TurnoProgramado;
    receptores: Receptor[];
    sugerido: number | null;
}) {
    const [abierto, setAbierto] = useState(false);

    return (
        <section className="space-y-4 rounded-xl border p-4">
            <div>
                <h2 className="text-lg font-semibold">Turno en curso</h2>
                <p className="text-sm text-muted-foreground">
                    {TURNOS[turno.tipo]} · {rangoHoras(turno)}. Lo iniciaste a
                    las {turno.inicio_real?.slice(11)}.
                </p>
            </div>
            {!abierto && (
                <Button className="w-fit" onClick={() => setAbierto(true)}>
                    Terminar turno
                </Button>
            )}
            {abierto && (
                <Form
                    {...EntregaTurnoController.entregar.form(turno.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="guardia_recibe_id">
                                    Lo releva (opcional)
                                </Label>
                                <select
                                    id="guardia_recibe_id"
                                    name="guardia_recibe_id"
                                    defaultValue={sugerido ?? ''}
                                    className={claseSelect}
                                >
                                    <option value="">Nadie me releva</option>
                                    {receptores.map((receptor) => (
                                        <option
                                            key={receptor.id}
                                            value={receptor.id}
                                        >
                                            {receptor.nombre}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.guardia_recibe_id}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="novedades">Novedades</Label>
                                <textarea
                                    id="novedades"
                                    name="novedades"
                                    rows={4}
                                    maxLength={2000}
                                    placeholder="Sin novedades"
                                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                />
                                <InputError message={errors.novedades} />
                            </div>
                            <InputError message={errors.turno} />
                            <div className="flex flex-wrap gap-2">
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Terminar turno
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={() => setAbierto(false)}
                                >
                                    Cancelar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

function Novedades({ texto }: { texto: string | null }) {
    return (
        <div className="rounded-lg bg-background/60 p-3 text-sm">
            <p className="font-medium">Novedades</p>
            <p className="whitespace-pre-line text-muted-foreground">
                {texto ?? 'Sin novedades.'}
            </p>
        </div>
    );
}

// "06:00 a 14:00", con el día siguiente cuando termina otro día
function rangoHoras(turno: TurnoProgramado): string {
    const otroDia = turno.fin.slice(0, 10) !== turno.inicio.slice(0, 10);

    return `de ${turno.inicio.slice(11)} a ${turno.fin.slice(11)}${otroDia ? ` del ${nombreDia(turno.fin).toLowerCase()}` : ''}`;
}

function Lista({
    titulo,
    turnos,
    vacio,
}: {
    titulo: string;
    turnos: TurnoProgramado[];
    vacio: string;
}) {
    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold">{titulo}</h2>
            {turnos.length === 0 ? (
                <p className="text-muted-foreground">{vacio}</p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {turnos.map((turno) => (
                        <li key={turno.id} className="space-y-1 p-4">
                            <p className="font-medium">
                                {nombreDia(turno.inicio)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {TURNOS[turno.tipo]} · {rangoHoras(turno)}
                            </p>
                            {estadoTurno(turno) && (
                                <p className="text-sm">{estadoTurno(turno)}</p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
