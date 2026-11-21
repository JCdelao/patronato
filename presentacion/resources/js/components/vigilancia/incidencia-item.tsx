import { ImageIcon } from 'lucide-react';
import IncidenciaController from '@/actions/Patronato/Presentacion/Controllers/IncidenciaController';
import { Badge } from '@/components/ui/badge';
import type { Incidencia } from '@/types/vigilancia';
import { nombreDia } from '@/types/vigilancia';

// Una incidencia con su tipo, cuándo ocurrió, quién la registró y la foto si tiene
export default function IncidenciaItem({
    incidencia,
}: {
    incidencia: Incidencia;
}) {
    return (
        <li className="space-y-1 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-semibold">{incidencia.tipo}</p>
                {incidencia.urgente && (
                    <Badge variant="destructive">Urgente</Badge>
                )}
            </div>
            <p className="text-sm text-muted-foreground">
                {nombreDia(incidencia.ocurrida_en)} a las{' '}
                {incidencia.ocurrida_en.slice(11)} · {incidencia.guardia}
            </p>
            <p className="text-sm whitespace-pre-line">
                {incidencia.descripcion}
            </p>
            {incidencia.tiene_foto && (
                <a
                    href={IncidenciaController.foto.url(incidencia.id)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 text-sm font-medium underline underline-offset-4"
                >
                    <ImageIcon className="size-4" />
                    Ver foto
                </a>
            )}
        </li>
    );
}
