import { Link, usePage } from '@inertiajs/react';
import {
    Ban,
    Building2,
    BadgeCheck,
    Car,
    ChartColumn,
    HandCoins,
    Home,
    Landmark,
    LayoutGrid,
    PiggyBank,
    Receipt,
    ReceiptText,
    ScrollText,
    Send,
    ShieldCheck,
    SlidersHorizontal,
    Target,
    Users,
    Vault,
    Wallet,
} from 'lucide-react';
import AnulacionController from '@/actions/Patronato/Presentacion/Controllers/AnulacionController';
import CajaController from '@/actions/Patronato/Presentacion/Controllers/CajaController';
import CobroController from '@/actions/Patronato/Presentacion/Controllers/CobroController';
import EgresoController from '@/actions/Patronato/Presentacion/Controllers/EgresoController';
import IngresoController from '@/actions/Patronato/Presentacion/Controllers/IngresoController';
import InformeFinancieroController from '@/actions/Patronato/Presentacion/Controllers/InformeFinancieroController';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import PresupuestoController from '@/actions/Patronato/Presentacion/Controllers/PresupuestoController';
import ParametroCobroController from '@/actions/Patronato/Presentacion/Controllers/ParametroCobroController';
import PortalTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/PortalTransferenciaController';
import ReporteCobrosController from '@/actions/Patronato/Presentacion/Controllers/ReporteCobrosController';
import TransferenciaController from '@/actions/Patronato/Presentacion/Controllers/TransferenciaController';
import VerificacionTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/VerificacionTransferenciaController';
import AuditoriaController from '@/actions/Patronato/Presentacion/Controllers/AuditoriaController';
import CuentaController from '@/actions/Patronato/Presentacion/Controllers/CuentaController';
import ViviendaController from '@/actions/Patronato/Presentacion/Controllers/ViviendaController';
import ResidencialController from '@/actions/Patronato/Presentacion/Controllers/ResidencialController';
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
            title: 'Mis reportes',
            href: PortalTransferenciaController.index(),
            icon: ReceiptText,
            visible: auth.esResidente && auth.permisos.includes('portal.ver'),
        },
        {
            title: 'Reportar transferencia',
            href: PortalTransferenciaController.create(),
            icon: Send,
            visible:
                auth.esResidente && auth.permisos.includes('portal.registrar'),
        },
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
            title: 'Registrar transferencia',
            href: TransferenciaController.create(),
            icon: Landmark,
            visible: auth.permisos.includes('transferencias.registrar'),
        },
        {
            title: 'Transferencias por verificar',
            href: VerificacionTransferenciaController.index(),
            icon: BadgeCheck,
            badge: auth.transferenciasPorVerificar,
            visible: auth.permisos.includes('transferencias.verificar'),
        },
        {
            title: 'Cobros',
            href: PagoController.index(),
            icon: Wallet,
            visible: auth.permisos.includes('cobros.ver'),
        },
        {
            title: 'Anulaciones por aprobar',
            href: AnulacionController.index(),
            icon: Ban,
            badge: auth.anulacionesPendientes,
            visible: auth.permisos.includes('cobros.aprobar'),
        },
        {
            title: 'Cierre de caja',
            href: CajaController.index(),
            icon: Vault,
            visible: auth.permisos.includes('cajas.registrar'),
        },
        {
            title: 'Ingresos',
            href: IngresoController.index(),
            icon: PiggyBank,
            visible: auth.permisos.includes('tesoreria.ver'),
        },
        {
            title: 'Egresos',
            href: EgresoController.index(),
            icon: Receipt,
            visible: auth.permisos.includes('tesoreria.ver'),
        },
        {
            title: 'Presupuesto',
            href: PresupuestoController.index(),
            icon: Target,
            visible: auth.permisos.includes('reportes.ver'),
        },
        {
            title: 'Parámetros de cobro',
            href: ParametroCobroController.index(),
            icon: SlidersHorizontal,
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
            href: ReporteCobrosController.index(),
            icon: ChartColumn,
            visible: auth.permisos.includes('reportes.ver'),
        },
        {
            title: 'Informe financiero',
            href: InformeFinancieroController.index(),
            icon: Landmark,
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
