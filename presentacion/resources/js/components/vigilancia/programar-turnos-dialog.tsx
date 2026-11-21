import { Form } from '@inertiajs/react';
import { useState } from 'react';
import TurnoController from '@/actions/Patronato/Presentacion/Controllers/TurnoController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { OpcionGuardia, Sugerido, TipoTurno } from '@/types/vigilancia';
import { DIAS_SEMANA, TURNOS } from '@/types/vigilancia';

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// El turno habitual del guardia sugiere el tipo; uno rotativo empieza en día
function tipoSugerido(guardia: OpcionGuardia | undefined): TipoTurno {
    return guardia && guardia.turno !== 'rotativo' ? guardia.turno : 'dia';
}

// Hora en que termina el turno, con el aviso si es al día siguiente
function horaFin(hora: string, horas: number): string {
    const [hh, mm] = hora.split(':').map(Number);

    if (Number.isNaN(hh) || Number.isNaN(mm) || !horas) {
        return '';
    }

    const total = hh * 60 + mm + horas * 60;
    const fin = `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;

    return total >= 24 * 60 ? `${fin} del día siguiente` : fin;
}

// Programa los turnos de un guardia por días de la semana o un día de por medio
export default function ProgramarTurnosDialog({
    guardias,
    sugeridos,
    fechaInicial,
    onClose,
}: {
    guardias: OpcionGuardia[];
    sugeridos: Record<TipoTurno, Sugerido>;
    fechaInicial: string;
    onClose: () => void;
}) {
    const inicial = tipoSugerido(guardias[0]);
    const [guardiaId, setGuardiaId] = useState(guardias[0]?.id ?? 0);
    const [tipo, setTipo] = useState<TipoTurno>(inicial);
    const [hora, setHora] = useState(sugeridos[inicial].hora);
    const [horas, setHoras] = useState(sugeridos[inicial].horas);
    const [desde, setDesde] = useState(fechaInicial);
    const [hasta, setHasta] = useState(fechaInicial);
    const [repetir, setRepetir] = useState<'semana' | 'alternos'>('semana');

    // Cada tipo trae su hora y sus horas sugeridas; después se ajustan
    const cambiarTipo = (nuevo: TipoTurno) => {
        setTipo(nuevo);
        setHora(sugeridos[nuevo].hora);
        setHoras(sugeridos[nuevo].horas);
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Programar turnos
                </DialogTitle>
                <DialogDescription>
                    Se crea un turno en cada fecha del rango que toca trabajar.
                    Si alguno se cruza con otro turno del guardia, no se guarda
                    ninguno.
                </DialogDescription>
                <Form
                    {...TurnoController.programar.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="guardia_id">Guardia</Label>
                                <select
                                    id="guardia_id"
                                    name="guardia_id"
                                    value={guardiaId}
                                    onChange={(evento) => {
                                        const id = Number(evento.target.value);
                                        setGuardiaId(id);
                                        cambiarTipo(
                                            tipoSugerido(
                                                guardias.find(
                                                    (g) => g.id === id,
                                                ),
                                            ),
                                        );
                                    }}
                                    className={claseSelect}
                                >
                                    {guardias.map((guardia) => (
                                        <option
                                            key={guardia.id}
                                            value={guardia.id}
                                        >
                                            {guardia.nombre} (
                                            {TURNOS[
                                                guardia.turno
                                            ].toLowerCase()}
                                            )
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.guardia_id} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="tipo">Turno</Label>
                                    <select
                                        id="tipo"
                                        name="tipo"
                                        value={tipo}
                                        onChange={(evento) =>
                                            cambiarTipo(
                                                evento.target
                                                    .value as TipoTurno,
                                            )
                                        }
                                        className={claseSelect}
                                    >
                                        {(
                                            Object.keys(
                                                sugeridos,
                                            ) as TipoTurno[]
                                        ).map((opcion) => (
                                            <option key={opcion} value={opcion}>
                                                {TURNOS[opcion]}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.tipo} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="hora_inicio">Entra a</Label>
                                    <Input
                                        id="hora_inicio"
                                        name="hora_inicio"
                                        type="time"
                                        value={hora}
                                        onChange={(evento) =>
                                            setHora(evento.target.value)
                                        }
                                        required
                                    />
                                    <InputError message={errors.hora_inicio} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="horas">Horas</Label>
                                    <Input
                                        id="horas"
                                        name="horas"
                                        type="number"
                                        min={1}
                                        max={24}
                                        value={horas}
                                        onChange={(evento) =>
                                            setHoras(
                                                Number(evento.target.value),
                                            )
                                        }
                                        required
                                    />
                                    <InputError message={errors.horas} />
                                </div>
                            </div>
                            {horaFin(hora, horas) && (
                                <p className="-mt-2 text-sm text-muted-foreground">
                                    Sale a las {horaFin(hora, horas)}.
                                </p>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="desde">Desde</Label>
                                    <Input
                                        id="desde"
                                        name="desde"
                                        type="date"
                                        value={desde}
                                        onChange={(evento) => {
                                            setDesde(evento.target.value);

                                            if (evento.target.value > hasta) {
                                                setHasta(evento.target.value);
                                            }
                                        }}
                                        required
                                    />
                                    <InputError message={errors.desde} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="hasta">Hasta</Label>
                                    <Input
                                        id="hasta"
                                        name="hasta"
                                        type="date"
                                        value={hasta}
                                        min={desde}
                                        onChange={(evento) =>
                                            setHasta(evento.target.value)
                                        }
                                        required
                                    />
                                    <InputError message={errors.hasta} />
                                </div>
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-1 text-sm font-medium">
                                    ¿Qué días trabaja?
                                </legend>
                                <input
                                    type="hidden"
                                    name="repetir"
                                    value={repetir}
                                />
                                <div className="flex w-fit gap-1 rounded-xl border bg-card p-1">
                                    {(
                                        [
                                            ['semana', 'Días de la semana'],
                                            ['alternos', 'Día de por medio'],
                                        ] as const
                                    ).map(([clave, texto]) => (
                                        <Button
                                            key={clave}
                                            type="button"
                                            size="sm"
                                            variant={
                                                repetir === clave
                                                    ? 'secondary'
                                                    : 'ghost'
                                            }
                                            aria-pressed={repetir === clave}
                                            onClick={() => setRepetir(clave)}
                                        >
                                            {texto}
                                        </Button>
                                    ))}
                                </div>

                                {repetir === 'semana' ? (
                                    <div className="flex flex-wrap gap-3">
                                        {DIAS_SEMANA.map(({ dia, corto }) => (
                                            <label
                                                key={dia}
                                                className="flex items-center gap-1.5 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="dias[]"
                                                    value={dia}
                                                    defaultChecked
                                                    className="size-4"
                                                />
                                                {corto}
                                            </label>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Trabaja la fecha inicial, descansa el
                                        día siguiente y así hasta la fecha
                                        final.
                                    </p>
                                )}
                                <InputError message={errors.dias} />
                            </fieldset>

                            <Button className="w-full" disabled={processing}>
                                {processing && <Spinner />}
                                Programar
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
