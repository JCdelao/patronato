import { Badge } from '@/components/ui/badge';

const ETIQUETAS: Record<string, string> = {
    confirmado: 'Confirmado',
    anulado: 'Anulado',
    pendiente_verificacion: 'Por verificar',
    reportado_por_vecino: 'Reportado',
    rechazado: 'Rechazado',
};

// Estado del pago; el anulado se distingue a simple vista
export default function EstadoPago({ estado }: { estado: string }) {
    return (
        <Badge variant={estado === 'anulado' ? 'destructive' : 'secondary'}>
            {ETIQUETAS[estado] ?? estado}
        </Badge>
    );
}
