import { Badge } from '@/components/ui/badge';
import type { EstadoSticker as Estado } from '@/types/vehiculos';
import { ESTADOS_STICKER } from '@/types/vehiculos';

// Estado del sticker; los que ya no sirven para entrar se distinguen a simple vista
export default function EstadoSticker({ estado }: { estado: Estado }) {
    const variante =
        estado === 'activo'
            ? 'secondary'
            : estado === 'suspendido' || estado === 'vencido'
              ? 'outline'
              : 'destructive';

    return <Badge variant={variante}>{ESTADOS_STICKER[estado]}</Badge>;
}
