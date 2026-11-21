import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    // Módulo aún no disponible
    disabled?: boolean;
    icon?: LucideIcon | null;
    isActive?: boolean;
    // Número de pendientes junto a la opción
    badge?: number;
};

// Opciones de un mismo tema que se despliegan juntas
export type NavGroup = {
    title: string;
    icon: LucideIcon;
    items: NavItem[];
};
