import { Badge } from '@/components/ui/badge';
import type { EstadoEgreso as Estado } from '@/types/finanzas';

// Estado del egreso; el anulado se distingue a simple vista
export default function EstadoEgreso({ estado }: { estado: Estado }) {
    return (
        <Badge variant={estado === 'anulado' ? 'destructive' : 'secondary'}>
            {estado === 'anulado' ? 'Anulado' : 'Vigente'}
        </Badge>
    );
}
