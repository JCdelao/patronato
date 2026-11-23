import { useEffect, useState } from 'react';
import PreautorizacionController from '@/actions/Patronato/Presentacion/Controllers/PreautorizacionController';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatoFechaHora } from '@/lib/formato';

type Preautorizacion = {
    id: number;
    visitante: string;
    medio: string | null;
    inicio: string;
    fin: string;
    estado: string;
    vinculo_vigente: boolean;
};

function Resultados({ vivienda, fecha }: { vivienda: string; fecha: string }) {
    const [visitas, setVisitas] = useState<Preautorizacion[]>([]);
    const [estado, setEstado] = useState<'cargando' | 'lista' | 'error'>(
        'cargando',
    );

    useEffect(() => {
        const controller = new AbortController();
        const url = PreautorizacionController.index.url(vivienda, {
            query: { fecha },
        });
        void fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(
                        'No se pudieron consultar las preautorizaciones.',
                    );
                }
                const datos: Preautorizacion[] = await response.json();
                if (!controller.signal.aborted) {
                    setVisitas(datos);
                    setEstado('lista');
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setEstado('error');
                }
            });
        return () => controller.abort();
    }, [vivienda, fecha]);

    if (estado === 'cargando') {
        return <p role="status">Consultando preautorizaciones…</p>;
    }
    if (estado === 'error') {
        return (
            <p role="alert">No se pudieron consultar las preautorizaciones.</p>
        );
    }
    if (visitas.length === 0) {
        return <p>No hay preautorizaciones para esta vivienda y fecha.</p>;
    }

    return (
        <ul className="divide-y rounded-lg border">
            {visitas.map((visita) => (
                <li key={visita.id} className="space-y-1 p-3">
                    <div className="flex flex-wrap justify-between gap-2">
                        <p className="font-medium">{visita.visitante}</p>
                        <p
                            className={
                                visita.estado === 'Vigente'
                                    ? 'font-semibold'
                                    : 'text-muted-foreground'
                            }
                        >
                            {visita.estado}
                        </p>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {visita.medio ?? 'Medio sin especificar'} ·{' '}
                        {formatoFechaHora(visita.inicio)} a{' '}
                        {formatoFechaHora(visita.fin)}
                    </p>
                    {!visita.vinculo_vigente && (
                        <p className="text-sm text-muted-foreground">
                            El vínculo con la vivienda ya no está vigente.
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function PreautorizacionesVivienda({
    viviendas,
}: {
    viviendas: { id: number; codigo: string }[];
}) {
    const [busqueda, setBusqueda] = useState('');
    const [vivienda, setVivienda] = useState('');
    const [fecha, setFecha] = useState(() =>
        new Intl.DateTimeFormat('sv-SE', {
            timeZone: 'America/Tegucigalpa',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date()),
    );
    const coincidencias = viviendas.filter(
        ({ id, codigo }) =>
            String(id) === vivienda ||
            codigo.toLowerCase().includes(busqueda.trim().toLowerCase()),
    );

    return (
        <section
            className="grid gap-3 rounded-xl border p-4"
            aria-labelledby="preautorizaciones-vivienda"
        >
            <h2
                id="preautorizaciones-vivienda"
                className="text-xl font-semibold"
            >
                Visitas preautorizadas
            </h2>
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="buscar-vivienda-preautorizaciones">
                        Buscar por código
                    </Label>
                    <Input
                        id="buscar-vivienda-preautorizaciones"
                        type="search"
                        value={busqueda}
                        onChange={(event) => {
                            setBusqueda(event.target.value);
                            setVivienda('');
                        }}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="vivienda-preautorizaciones">Vivienda</Label>
                    <select
                        id="vivienda-preautorizaciones"
                        value={vivienda}
                        onChange={(event) => setVivienda(event.target.value)}
                        className="h-10 rounded-md border border-input bg-transparent px-3"
                    >
                        <option value="">Elige la vivienda</option>
                        {coincidencias.map(({ id, codigo }) => (
                            <option key={id} value={id}>
                                {codigo}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="fecha-preautorizaciones">
                        Fecha (Honduras)
                    </Label>
                    <Input
                        id="fecha-preautorizaciones"
                        type="date"
                        value={fecha}
                        onChange={(event) => setFecha(event.target.value)}
                    />
                </div>
            </div>
            {vivienda && fecha && (
                <Resultados
                    key={vivienda + fecha}
                    vivienda={vivienda}
                    fecha={fecha}
                />
            )}
        </section>
    );
}
