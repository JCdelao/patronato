import { useEffect, useState } from 'react';
import AutorizadoFrecuenteController from '@/actions/Patronato/Presentacion/Controllers/AutorizadoFrecuenteController';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { diasSemana } from '@/types/autorizaciones-frecuentes';

type Autorizacion = {
    id: number;
    nombre: string;
    tipo: string;
    dias: number[];
    inicio: string;
    fin: string;
    estado: string;
};

function Resultados({ vivienda }: { vivienda: string }) {
    const [registros, setRegistros] = useState<Autorizacion[] | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        void fetch(AutorizadoFrecuenteController.index.url(vivienda), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (respuesta) => {
                if (!respuesta.ok) {
                    throw new Error(
                        'No se pudieron consultar las autorizaciones.',
                    );
                }
                const datos: Autorizacion[] = await respuesta.json();
                if (!controller.signal.aborted) {
                    setRegistros(datos);
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setError(true);
                }
            });
        return () => controller.abort();
    }, [vivienda]);

    if (error) {
        return <p role="alert">No se pudieron consultar las autorizaciones.</p>;
    }
    if (registros === null) {
        return <p role="status">Consultando autorizaciones…</p>;
    }
    if (registros.length === 0) {
        return <p>No existe autorización</p>;
    }

    return (
        <ul className="divide-y rounded-lg border">
            {registros.map((registro) => (
                <li key={registro.id} className="space-y-1 p-3">
                    <p className="font-medium">
                        {registro.nombre} · {registro.tipo}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {registro.dias
                            .map((dia) => diasSemana[dia - 1])
                            .join(', ')}{' '}
                        · {registro.inicio}–{registro.fin}
                        {registro.fin < registro.inicio &&
                            ' (termina al día siguiente)'}
                    </p>
                    <p className="text-sm font-semibold">{registro.estado}</p>
                </li>
            ))}
        </ul>
    );
}

export default function AutorizacionesFrecuentes({
    viviendas,
}: {
    viviendas: { id: number; codigo: string }[];
}) {
    const [vivienda, setVivienda] = useState('');
    const [version, setVersion] = useState(0);

    return (
        <section
            className="grid gap-3 rounded-xl border p-4"
            aria-labelledby="autorizaciones-frecuentes"
        >
            <h2
                id="autorizaciones-frecuentes"
                className="text-xl font-semibold"
            >
                Autorizaciones frecuentes
            </h2>
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid flex-1 gap-2">
                    <Label htmlFor="vivienda-autorizaciones-frecuentes">
                        Vivienda
                    </Label>
                    <select
                        id="vivienda-autorizaciones-frecuentes"
                        value={vivienda}
                        onChange={(event) => setVivienda(event.target.value)}
                        className="h-10 rounded-md border border-input bg-transparent px-3"
                    >
                        <option value="">Elige la vivienda</option>
                        {viviendas.map(({ id, codigo }) => (
                            <option key={id} value={id}>
                                {codigo}
                            </option>
                        ))}
                    </select>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    disabled={!vivienda}
                    onClick={() => setVersion(version + 1)}
                >
                    Actualizar
                </Button>
            </div>
            {vivienda && (
                <>
                    <p className="text-sm text-muted-foreground">
                        Estado al consultar, en horario de Honduras.
                    </p>
                    <Resultados
                        key={vivienda + ':' + version}
                        vivienda={vivienda}
                    />
                </>
            )}
        </section>
    );
}
