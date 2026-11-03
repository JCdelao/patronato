import { Link } from '@inertiajs/react';
import ResidenteController from '@/actions/Patronato/Modules/Padron/Controllers/ResidenteController';
import ViviendaController from '@/actions/Patronato/Modules/Padron/Controllers/ViviendaController';
import { Button } from '@/components/ui/button';

const VISTAS = [
    { clave: 'activas', texto: 'Activas', href: ViviendaController.index() },
    {
        clave: 'inactivas',
        texto: 'Inactivas',
        href: ViviendaController.index({ query: { estado: 'inactivas' } }),
    },
    {
        clave: 'residentes',
        texto: 'Residentes inactivos',
        href: ResidenteController.index(),
    },
] as const;

export default function PadronNav({
    actual,
}: {
    actual: (typeof VISTAS)[number]['clave'];
}) {
    return (
        <nav
            aria-label="Vistas del padrón"
            className="flex flex-wrap gap-1 rounded-xl border bg-card p-1"
        >
            {VISTAS.map((vista) => (
                <Button
                    key={vista.clave}
                    asChild
                    size="sm"
                    variant={vista.clave === actual ? 'secondary' : 'ghost'}
                >
                    <Link
                        href={vista.href}
                        aria-current={
                            vista.clave === actual ? 'page' : undefined
                        }
                    >
                        {vista.texto}
                    </Link>
                </Button>
            ))}
        </nav>
    );
}
