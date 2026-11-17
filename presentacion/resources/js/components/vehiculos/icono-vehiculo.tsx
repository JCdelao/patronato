import type { LucideIcon } from 'lucide-react';
import { Car, CarFront, Truck } from 'lucide-react';
import Motorbike from '@/components/iconos/motorbike';

// Ícono de cada tipo; un tipo nuevo del catálogo solo agrega su línea aquí
const ICONOS: Record<string, LucideIcon> = {
    carro: Car,
    moto: Motorbike,
    otro: Truck,
};

// Ícono del tipo de vehículo; los tipos sin ícono propio usan uno genérico
export default function IconoVehiculo({
    tipo,
    className,
}: {
    tipo: string;
    className?: string;
}) {
    const Icono = ICONOS[tipo] ?? CarFront;

    return <Icono className={className} aria-hidden="true" />;
}
