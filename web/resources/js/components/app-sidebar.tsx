import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    Car,
    ChartColumn,
    HandCoins,
    Home,
    LayoutGrid,
    ScrollText,
    ShieldCheck,
    SlidersHorizontal,
    Tags,
    Users,
    Wallet,
} from 'lucide-react';
import CobroController from '@/actions/Patronato/Modules/Finanzas/Controllers/CobroController';
import ConceptoController from '@/actions/Patronato/Modules/Finanzas/Controllers/ConceptoController';
import ModalidadController from '@/actions/Patronato/Modules/Finanzas/Controllers/ModalidadController';
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

    // Solo se muestran las opciones que el usuario puede usar
    const opciones: (NavItem & { visible: boolean })[] = [
        { title: 'Panel', href: dashboard(), icon: LayoutGrid, visible: true },
        {
            title: 'Residenciales',
            href: ResidencialController.index(),
            icon: Building2,
            visible: auth.esAdminPlataforma,
        },
        {
            title: 'Viviendas',
            href: ViviendaController.index(),
            icon: Home,
            visible: auth.permisos.includes('padron.ver'),
        },
        {
            title: 'Registrar cobro',
            href: CobroController.create(),
            icon: HandCoins,
            visible: auth.permisos.includes('cobros.registrar'),
        },
        {
            title: 'Cobros',
            href: '#',
            icon: Wallet,
            disabled: true,
            visible: auth.permisos.includes('cobros.ver'),
        },
        {
            title: 'Modalidad de cobro',
            href: ModalidadController.index(),
            icon: SlidersHorizontal,
            visible: auth.permisos.includes('parametros.ver'),
        },
        {
            title: 'Conceptos de cobro',
            href: ConceptoController.index(),
            icon: Tags,
            visible: auth.permisos.includes('parametros.ver'),
        },
        {
            title: 'Vigilancia',
            href: '#',
            icon: ShieldCheck,
            disabled: true,
            visible: auth.permisos.includes('vigilancia.ver'),
        },
        {
            title: 'Vehículos',
            href: '#',
            icon: Car,
            disabled: true,
            visible: auth.permisos.includes('vehiculos.ver'),
        },
        {
            title: 'Reportes',
            href: '#',
            icon: ChartColumn,
            disabled: true,
            visible: auth.permisos.includes('reportes.ver'),
        },
        {
            title: 'Usuarios',
            href: CuentaController.index(),
            icon: Users,
            visible: auth.puedeAdministrarUsuarios,
        },
        {
            title: 'Auditoría',
            href: AuditoriaController.index(),
            icon: ScrollText,
            visible: auth.permisos.includes('auditoria.ver'),
        },
    ];

    const items: NavItem[] = opciones.filter((opcion) => opcion.visible);

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
