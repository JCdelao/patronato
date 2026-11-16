import { cn } from '@/lib/utils';
import type { EstadoMora as Estado } from '@/types/finanzas';

const formatoMonto = (monto: string) =>
    Number(monto).toLocaleString('es-HN', { minimumFractionDigits: 2 });

// Etiqueta "Al día" o "En mora"; con detalle muestra los meses vencidos y lo que se debe
export default function EstadoMora({
    mora,
    detalle = false,
    className,
}: {
    mora: Estado;
    detalle?: boolean;
    className?: string;
}) {
    const enMora = mora.estado === 'en_mora';
    const meses =
        mora.meses_vencidos === 1
            ? '1 mes vencido'
            : `${mora.meses_vencidos} meses vencidos`;

    return (
        <span
            className={cn(
                'inline-flex flex-wrap items-center gap-2',
                className,
            )}
        >
            <span
                className={cn(
                    'rounded-full px-3 py-0.5 text-sm font-medium',
                    enMora
                        ? 'bg-destructive/10 text-destructive'
                        : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                )}
            >
                {enMora ? 'En mora' : 'Al día'}
            </span>
            {detalle && mora.meses_vencidos > 0 && (
                <span className="text-sm text-muted-foreground">
                    {meses} · L. {formatoMonto(mora.vencido)}
                </span>
            )}
        </span>
    );
}
