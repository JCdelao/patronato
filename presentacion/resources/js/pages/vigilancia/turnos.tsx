import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import TurnoController from '@/actions/Patronato/Presentacion/Controllers/TurnoController';
import ConfirmarDialog from '@/components/padron/confirmar-dialog';
import { Button } from '@/components/ui/button';
import ProgramarTurnosDialog from '@/components/vigilancia/programar-turnos-dialog';
import { cn } from '@/lib/utils';
import type {
    OpcionGuardia,
    Sugerido,
    TipoTurno,
    TurnoProgramado,
} from '@/types/vigilancia';
import {
    DIAS_SEMANA,
    TURNOS,
    estadoTurno,
    fechaLocal,
    nombreDia,
    textoFecha,
} from '@/types/vigilancia';

type DiaCalendario = { fecha: string; turnos: TurnoProgramado[] };

const COLOR_TIPO: Record<TipoTurno, string> = {
    dia: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
    tarde: 'bg-orange-100 text-orange-900 dark:bg-orange-900/40 dark:text-orange-100',
    noche: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-100',
    '24_horas':
        'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
};

export default function Turnos({
    vista,
    mes,
    dia,
    dias,
    turnosDelDia,
    guardias,
    sugeridos,
}: {
    vista: 'mes' | 'dia';
    mes: string;
    dia: string;
    dias: DiaCalendario[];
    turnosDelDia: TurnoProgramado[];
    guardias: OpcionGuardia[];
    sugeridos: Record<TipoTurno, Sugerido>;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('turnos.registrar');
    const [programando, setProgramando] = useState(false);
    const [quitando, setQuitando] = useState<TurnoProgramado | null>(null);
    const hoy = textoFecha(new Date());

    return (
        <>
            <Head title="Turnos" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Turnos
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            Calendario de los guardias de servicio
                        </p>
                    </div>
                    {puedeRegistrar && guardias.length > 0 && (
                        <Button onClick={() => setProgramando(true)}>
                            <Plus />
                            Programar turnos
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <nav
                        aria-label="Vista del calendario"
                        className="flex w-fit gap-1 rounded-xl border bg-card p-1"
                    >
                        {(
                            [
                                ['mes', 'Mes', { mes }],
                                ['dia', 'Día', { vista: 'dia', dia }],
                            ] as const
                        ).map(([clave, texto, query]) => (
                            <Button
                                key={clave}
                                asChild
                                size="sm"
                                variant={
                                    clave === vista ? 'secondary' : 'ghost'
                                }
                            >
                                <Link
                                    href={TurnoController.index.url({ query })}
                                    aria-current={
                                        clave === vista ? 'page' : undefined
                                    }
                                >
                                    {texto}
                                </Link>
                            </Button>
                        ))}
                    </nav>

                    {vista === 'mes' ? (
                        <Navegacion
                            titulo={nombreMes(mes)}
                            anterior={{ mes: moverMes(mes, -1) }}
                            siguiente={{ mes: moverMes(mes, 1) }}
                        />
                    ) : (
                        <Navegacion
                            titulo={nombreDia(dia)}
                            anterior={{ vista: 'dia', dia: moverDia(dia, -1) }}
                            siguiente={{ vista: 'dia', dia: moverDia(dia, 1) }}
                        />
                    )}
                </div>

                {guardias.length === 0 && puedeRegistrar && (
                    <p className="text-muted-foreground">
                        Registra primero a los guardias para programar sus
                        turnos.
                    </p>
                )}

                {vista === 'mes' ? (
                    <Mes dias={dias} hoy={hoy} />
                ) : turnosDelDia.length === 0 ? (
                    <p className="text-muted-foreground">
                        No hay turnos este día.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {turnosDelDia.map((turno) => (
                            <li
                                key={turno.id}
                                className="flex items-center justify-between gap-4 p-4"
                            >
                                <div className="min-w-0">
                                    <p className="font-semibold">
                                        {turno.guardia}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        <span
                                            className={cn(
                                                'mr-2 rounded px-1.5 py-0.5 text-xs font-medium',
                                                COLOR_TIPO[turno.tipo],
                                            )}
                                        >
                                            {TURNOS[turno.tipo]}
                                        </span>
                                        {rangoHoras(turno, dia)}
                                    </p>
                                    {estadoTurno(turno) && (
                                        <p className="mt-1 text-sm">
                                            {estadoTurno(turno)}
                                        </p>
                                    )}
                                    {turno.entrega?.novedades && (
                                        <p className="mt-1 text-sm whitespace-pre-line text-muted-foreground">
                                            Novedades: {turno.entrega.novedades}
                                        </p>
                                    )}
                                </div>
                                {puedeRegistrar && !turno.empezo && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Quitar el turno de ${turno.guardia}`}
                                        onClick={() => setQuitando(turno)}
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {programando && (
                <ProgramarTurnosDialog
                    guardias={guardias}
                    sugeridos={sugeridos}
                    fechaInicial={vista === 'dia' && dia > hoy ? dia : hoy}
                    onClose={() => setProgramando(false)}
                />
            )}
            {quitando && (
                <ConfirmarDialog
                    titulo={`¿Quitar el turno de ${quitando.guardia}?`}
                    descripcion={`${TURNOS[quitando.tipo]}, ${rangoHoras(quitando)}. El cambio queda en la bitácora.`}
                    confirmar="Quitar"
                    accion={TurnoController.quitar.form(quitando.id)}
                    onClose={() => setQuitando(null)}
                    onGuardado={() => setQuitando(null)}
                />
            )}
        </>
    );
}

function Navegacion({
    titulo,
    anterior,
    siguiente,
}: {
    titulo: string;
    anterior: Record<string, string>;
    siguiente: Record<string, string>;
}) {
    return (
        <div className="flex items-center gap-2">
            <Button variant="outline" size="icon" asChild>
                <Link
                    href={TurnoController.index.url({ query: anterior })}
                    aria-label="Anterior"
                >
                    <ChevronLeft />
                </Link>
            </Button>
            <span className="min-w-44 text-center font-medium">{titulo}</span>
            <Button variant="outline" size="icon" asChild>
                <Link
                    href={TurnoController.index.url({ query: siguiente })}
                    aria-label="Siguiente"
                >
                    <ChevronRight />
                </Link>
            </Button>
        </div>
    );
}

// Cuadrícula de lunes a domingo; cada día lleva a su vista detallada
function Mes({ dias, hoy }: { dias: DiaCalendario[]; hoy: string }) {
    const vacios =
        dias.length > 0 ? (fechaLocal(dias[0].fecha).getDay() + 6) % 7 : 0;

    return (
        <div className="overflow-x-auto">
            <div className="grid min-w-[640px] grid-cols-7 overflow-hidden rounded-xl border">
                {DIAS_SEMANA.map(({ dia, corto }) => (
                    <div
                        key={dia}
                        className="border-b bg-muted/50 px-2 py-1.5 text-center text-xs font-medium text-muted-foreground"
                    >
                        {corto}
                    </div>
                ))}
                {Array.from({ length: vacios }, (_, i) => (
                    <div key={`vacio-${i}`} className="border-r border-b" />
                ))}
                {dias.map(({ fecha, turnos }) => (
                    <Link
                        key={fecha}
                        href={TurnoController.index.url({
                            query: { vista: 'dia', dia: fecha },
                        })}
                        className="min-h-24 border-r border-b p-1.5 transition-colors hover:bg-muted/50"
                    >
                        <span
                            className={cn(
                                'mb-1 inline-flex size-6 items-center justify-center rounded-full text-xs',
                                fecha === hoy &&
                                    'bg-primary font-semibold text-primary-foreground',
                            )}
                        >
                            {Number(fecha.slice(8))}
                        </span>
                        <ul className="space-y-0.5">
                            {turnos.map((turno) => (
                                <li
                                    key={turno.id}
                                    className={cn(
                                        'truncate rounded px-1 text-[11px] leading-5',
                                        COLOR_TIPO[turno.tipo],
                                    )}
                                    title={`${turno.guardia} · ${TURNOS[turno.tipo]} · ${rangoHoras(turno)}`}
                                >
                                    {turno.inicio.slice(11)} {turno.guardia}
                                </li>
                            ))}
                        </ul>
                    </Link>
                ))}
            </div>
        </div>
    );
}

// "18:00 a 06:00 del jueves 20"; el día solo se escribe si es otro
function rangoHoras(turno: TurnoProgramado, dia?: string): string {
    const diaInicio = turno.inicio.slice(0, 10);
    const diaFin = turno.fin.slice(0, 10);
    const referencia = dia ?? diaInicio;

    const parte = (fecha: string, hora: string) =>
        fecha === referencia
            ? hora
            : `${hora} del ${nombreDia(fecha).split(' ').slice(0, 2).join(' ').toLowerCase()}`;

    return `${parte(diaInicio, turno.inicio.slice(11))} a ${parte(diaFin, turno.fin.slice(11))}`;
}

function nombreMes(mes: string): string {
    const texto = fechaLocal(`${mes}-01`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
    });

    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

function moverMes(mes: string, cantidad: number): string {
    const fecha = fechaLocal(`${mes}-01`);
    fecha.setMonth(fecha.getMonth() + cantidad);

    return textoFecha(fecha).slice(0, 7);
}

function moverDia(dia: string, cantidad: number): string {
    const fecha = fechaLocal(dia);
    fecha.setDate(fecha.getDate() + cantidad);

    return textoFecha(fecha);
}
