import { useEffect, useState } from 'react';
import ViviendaController from '@/actions/Patronato/Presentacion/Controllers/ViviendaController';
import TablaCambios, {
    textoAccion,
} from '@/components/identidad/tabla-cambios';
import type { Cambio } from '@/components/identidad/tabla-cambios';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { Vivienda } from '@/types/padron';

type Entrada = {
    id: number;
    fecha: string;
    usuario: string;
    accion: string;
    referencia: string | null;
    cambios: Cambio[];
    motivo: string | null;
};

export default function HistorialDialog({
    vivienda,
    onClose,
}: {
    vivienda: Vivienda;
    onClose: () => void;
}) {
    const [entradas, setEntradas] = useState<Entrada[] | null>(null);
    const [error, setError] = useState(false);

    // Carga el historial al abrir
    useEffect(() => {
        fetch(ViviendaController.historial.url(vivienda.id), {
            headers: { Accept: 'application/json' },
        })
            .then((respuesta) => {
                if (!respuesta.ok) {
                    throw new Error();
                }

                return respuesta.json() as Promise<Entrada[]>;
            })
            .then(setEntradas)
            .catch(() => setError(true));
    }, [vivienda.id]);

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto rounded-2xl sm:max-w-2xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Historial de {vivienda.codigo}
                </DialogTitle>
                <DialogDescription>
                    Cambios de la vivienda y de sus residentes, del más reciente
                    al más antiguo.
                </DialogDescription>

                {error ? (
                    <p className="text-destructive">
                        No se pudo cargar el historial.
                    </p>
                ) : entradas === null ? (
                    <Spinner />
                ) : entradas.length === 0 ? (
                    <p className="text-muted-foreground">
                        Todavía no hay cambios registrados.
                    </p>
                ) : (
                    <ol className="divide-y">
                        {entradas.map((entrada) => (
                            <li key={entrada.id} className="space-y-1 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {textoAccion(entrada.accion)}
                                    </Badge>
                                    <span className="font-medium">
                                        {entrada.usuario}
                                    </span>
                                    <span className="ml-auto text-sm text-muted-foreground">
                                        {new Date(entrada.fecha).toLocaleString(
                                            'es-HN',
                                        )}
                                    </span>
                                </div>
                                {entrada.referencia && (
                                    <p className="text-sm text-muted-foreground">
                                        {entrada.referencia}
                                    </p>
                                )}
                                {entrada.motivo && (
                                    <p className="text-sm">{entrada.motivo}</p>
                                )}
                                {entrada.cambios.length > 0 && (
                                    <TablaCambios cambios={entrada.cambios} />
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </DialogContent>
        </Dialog>
    );
}
