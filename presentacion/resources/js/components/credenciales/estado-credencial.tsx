import { Badge } from '@/components/ui/badge';
import type { EstadoCredencial as Estado } from '@/types/credenciales';
import { ESTADOS_CREDENCIAL } from '@/types/credenciales';

// Estado de un sticker o un carnet; los que ya no sirven para entrar se distinguen a simple vista
export default function EstadoCredencial({ estado }: { estado: Estado }) {
    const variante =
        estado === 'activo'
            ? 'secondary'
            : estado === 'suspendido' || estado === 'vencido'
              ? 'outline'
              : 'destructive';

    return <Badge variant={variante}>{ESTADOS_CREDENCIAL[estado]}</Badge>;
}
