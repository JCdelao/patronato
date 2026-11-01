import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    Car,
    ChartColumn,
    Home,
    LayoutGrid,
    ScrollText,
    ShieldCheck,
    Users,
    Wallet,
} from 'lucide-react';
import AuditoriaController from '@/actions/Patronato/Modules/Identidad/Controllers/AuditoriaController';
import CuentaController from '@/actions/Patronato/Modules/Identidad/Controllers/CuentaController';
import ViviendaController from '@/actions/Patronato/Modules/Padron/Controllers/ViviendaController';
import ResidencialController from '@/actions/Patronato/Modules/Plataforma/Controllers/ResidencialController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage().props;

    const items: NavItem[] = [
        { title: 'Panel', href: dashboard(), icon: LayoutGrid },
        ...(auth.esAdminPlataforma
            ? [
                  {
                      title: 'Residenciales',
                      href: ResidencialController.index(),
                      icon: Building2,
                  },
              ]
            : []),
        {
            title: 'Viviendas',
            href: ViviendaController.index(),
            icon: Home,
            disabled: !auth.permisos.includes('padron.ver'),
        },
        { title: 'Cobros', href: '#', icon: Wallet, disabled: true },
        { title: 'Vigilancia', href: '#', icon: ShieldCheck, disabled: true },
        { title: 'Vehículos', href: '#', icon: Car, disabled: true },
        { title: 'Reportes', href: '#', icon: ChartColumn, disabled: true },
        {
            title: 'Usuarios',
            href: CuentaController.index(),
            icon: Users,
            disabled: !auth.puedeAdministrarUsuarios,
        },
        ...(auth.permisos.includes('auditoria.ver')
            ? [
                  {
                      title: 'Auditoría',
                      href: AuditoriaController.index(),
                      icon: ScrollText,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="offcanvas">
            <SidebarHeader className="px-6 py-8">
                <Link href={dashboard()}>
                    <AppLogo />
                </Link>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-border p-3">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
