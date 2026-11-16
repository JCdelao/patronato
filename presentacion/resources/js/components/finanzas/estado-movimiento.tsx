import { Badge } from '@/components/ui/badge';
import type { EstadoMovimiento as Estado } from '@/types/finanzas';

// Estado de un egreso o un ingreso; el anulado se distingue a simple vista
export default function EstadoMovimiento({ estado }: { estado: Estado }) {
    return (
        <Badge variant={estado === 'anulado' ? 'destructive' : 'secondary'}>
            {estado === 'anulado' ? 'Anulado' : 'Vigente'}
        </Badge>
    );
}
