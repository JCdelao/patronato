import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Filter } from 'lucide-react';
import BitacoraAccesosController from '@/actions/Patronato/Presentacion/Controllers/BitacoraAccesosController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TipoTurno } from '@/types/vigilancia';
import { TURNOS } from '@/types/vigilancia';

type TipoEvento = 'entrada' | 'salida' | 'incidencia';

type Opcion = { id: number; nombre: string };

type Filtros = {
    desde: string;
    hasta: string;
    tipo: TipoEvento | null;
    turno: TipoTurno | null;
    guardia: number | null;
    vivienda: number | null;
    medio: number | null;
};

type Evento = {
    tipo: TipoEvento;
    id: number;
    // Hora de Honduras: AAAA-MM-DD HH:MM
    fecha: string;
    guardia: string;
    turno: TipoTurno | null;
    vivienda: string | null;
    medio: string | null;
    visitante: string | null;
    placa: string | null;
    categoria: string | null;
    detalle: string | null;
    urgente: boolean;
};

type Paginado<T> = {
    data: T[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const TIPOS: Record<TipoEvento, string> = {
    entrada: 'Entrada',
    salida: 'Salida',
    incidencia: 'Incidencia',
};

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// Entradas, salidas e incidencias con filtros combinables y exportación
export default function Bitacora({
    filtros,
    opciones,
    eventos,
    totales,
    maximoExportacion,
}: {
    filtros: Filtros;
    opciones: { guardias: Opcion[]; viviendas: Opcion[]; medios: Opcion[] };
    eventos: Paginado<Evento>;
    totales: Record<TipoEvento, number>;
    maximoExportacion: number;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    // La exportación usa los mismos filtros que la pantalla
    const consulta = Object.fromEntries(
        Object.entries(filtros).filter(([, valor]) => valor !== null),
    );

    return (
        <>
            <Head title="Bitácora de accesos" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Bitácora de accesos
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Entradas, salidas e incidencias registradas por los
                        guardias
                    </p>
                </div>

                <Form
                    {...BitacoraAccesosController.index.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4"
                >
                    {({ errors: errores, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="desde">Desde</Label>
                                <Input
                                    id="desde"
                                    name="desde"
                                    type="date"
                                    defaultValue={filtros.desde}
                                />
                                <InputError message={errores.desde} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="hasta">Hasta</Label>
                                <Input
                                    id="hasta"
                                    name="hasta"
                                    type="date"
                                    defaultValue={filtros.hasta}
                                />
                                <InputError message={errores.hasta} />
                            </div>
                            <Selector
                                id="tipo"
                                etiqueta="Tipo"
                                valor={filtros.tipo}
                                todos="Todos"
                                opciones={Object.entries(TIPOS).map(
                                    ([id, nombre]) => ({ id, nombre }),
                                )}
                                error={errores.tipo}
                            />
                            <Selector
                                id="turno"
                                etiqueta="Turno"
                                valor={filtros.turno}
                                todos="Todos"
                                opciones={(
                                    [
                                        'dia',
                                        'tarde',
                                        'noche',
                                        '24_horas',
                                    ] as const
                                ).map((id) => ({ id, nombre: TURNOS[id] }))}
                                error={errores.turno}
                            />
                            <Selector
                                id="guardia"
                                etiqueta="Guardia"
                                valor={filtros.guardia}
                                todos="Todos"
                                opciones={opciones.guardias}
                                error={errores.guardia}
                            />
                            <Selector
                                id="vivienda"
                                etiqueta="Vivienda"
                                valor={filtros.vivienda}
                                todos="Todas"
                                opciones={opciones.viviendas}
                                error={errores.vivienda}
                            />
                            <Selector
                                id="medio"
                                etiqueta="Medio de entrada"
                                valor={filtros.medio}
                                todos="Todos"
                                opciones={opciones.medios}
                                error={errores.medio}
                            />
                            <div className="flex items-end">
                                <Button disabled={processing}>
                                    <Filter />
                                    Filtrar
                                </Button>
                            </div>
                            <p className="text-sm text-muted-foreground sm:col-span-2 lg:col-span-4">
                                Con el filtro de vivienda, las incidencias se
                                buscan por el número de vivienda escrito en "Qué
                                pasó". Con el filtro de medio de entrada solo
                                aparecen visitas.
                            </p>
                        </>
                    )}
                </Form>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2 text-sm">
                        <Badge variant="secondary">
                            Entradas: {totales.entrada}
                        </Badge>
                        <Badge variant="secondary">
                            Salidas: {totales.salida}
                        </Badge>
                        <Badge variant="secondary">
                            Incidencias: {totales.incidencia}
                        </Badge>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a
                                href={BitacoraAccesosController.exportar.url(
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
                                href={BitacoraAccesosController.exportar.url(
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
                <InputError message={errors.exportar} />
                {eventos.total > maximoExportacion && (
                    <p className="text-sm text-muted-foreground">
                        Se exportan hasta {maximoExportacion} registros. Reduce
                        el rango de fechas o agrega filtros para exportar.
                    </p>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted text-left">
                            <tr>
                                <th className="p-3 font-medium">
                                    Fecha y hora
                                </th>
                                <th className="p-3 font-medium">Tipo</th>
                                <th className="p-3 font-medium">Guardia</th>
                                <th className="p-3 font-medium">Turno</th>
                                <th className="p-3 font-medium">Vivienda</th>
                                <th className="p-3 font-medium">Detalle</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {eventos.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-4 text-center text-muted-foreground"
                                    >
                                        No hay registros con estos filtros.
                                    </td>
                                </tr>
                            )}
                            {eventos.data.map((evento) => (
                                <tr
                                    key={`${evento.tipo}-${evento.id}`}
                                    className="align-top"
                                >
                                    <td className="p-3 whitespace-nowrap">
                                        {evento.fecha}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex flex-wrap gap-1">
                                            <Badge variant="outline">
                                                {TIPOS[evento.tipo]}
                                            </Badge>
                                            {evento.urgente && (
                                                <Badge variant="destructive">
                                                    Urgente
                                                </Badge>
                                            )}
                                        </div>
                                    </td>
                                    <td className="p-3">{evento.guardia}</td>
                                    <td className="p-3">
                                        {evento.turno
                                            ? TURNOS[evento.turno]
                                            : 'Sin turno'}
                                    </td>
                                    <td className="p-3">
                                        {evento.vivienda ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        <Detalle evento={evento} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {(eventos.prev_page_url || eventos.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {eventos.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={eventos.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {eventos.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={eventos.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

function Selector({
    id,
    etiqueta,
    valor,
    todos,
    opciones,
    error,
}: {
    id: string;
    etiqueta: string;
    valor: string | number | null;
    todos: string;
    opciones: { id: string | number; nombre: string }[];
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{etiqueta}</Label>
            <select
                id={id}
                name={id}
                defaultValue={valor ?? ''}
                className={claseSelect}
            >
                <option value="">{todos}</option>
                {opciones.map((opcion) => (
                    <option key={opcion.id} value={opcion.id}>
                        {opcion.nombre}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

// Visitante, medio y placa de una visita, o tipo y descripción de una incidencia
function Detalle({ evento }: { evento: Evento }) {
    if (evento.tipo === 'incidencia') {
        return (
            <div>
                <p className="font-medium">{evento.categoria}</p>
                <p className="whitespace-pre-line text-muted-foreground">
                    {evento.detalle}
                </p>
            </div>
        );
    }

    return (
        <div>
            <p className="font-medium">{evento.visitante}</p>
            <p className="text-muted-foreground">
                {[evento.medio, evento.placa, evento.detalle]
                    .filter(Boolean)
                    .join(' · ')}
            </p>
        </div>
    );
}
