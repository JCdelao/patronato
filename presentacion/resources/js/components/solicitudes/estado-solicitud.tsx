import { Badge } from '@/components/ui/badge';
import type { EstadoSolicitud as Estado } from '@/types/solicitudes';
import { ESTADOS_SOLICITUD } from '@/types/solicitudes';

// Estado de una solicitud de corrección; las rechazadas se distinguen a simple vista
export default function EstadoSolicitud({ estado }: { estado: Estado }) {
    const variante =
        estado === 'resuelta'
            ? 'secondary'
            : estado === 'rechazada'
              ? 'destructive'
              : 'outline';

    return <Badge variant={variante}>{ESTADOS_SOLICITUD[estado]}</Badge>;
}
