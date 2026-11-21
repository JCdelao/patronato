import { Link, usePage } from '@inertiajs/react';
import {
    Ban,
    Banknote,
    Building2,
    BadgeCheck,
    BookOpenText,
    CalendarClock,
    CalendarDays,
    Car,
    ChartColumn,
    Coins,
    HandCoins,
    Home,
    IdCard,
    Landmark,
    LayoutGrid,
    PiggyBank,
    Receipt,
    ReceiptText,
    ScrollText,
    Send,
    ShieldCheck,
    Settings2,
    SlidersHorizontal,
    Target,
    TriangleAlert,
    UserCheck,
    Users,
    Vault,
    Wallet,
} from 'lucide-react';
import AnulacionController from '@/actions/Patronato/Presentacion/Controllers/AnulacionController';
import CajaController from '@/actions/Patronato/Presentacion/Controllers/CajaController';
import CarnetController from '@/actions/Patronato/Presentacion/Controllers/CarnetController';
import CobroController from '@/actions/Patronato/Presentacion/Controllers/CobroController';
import GuardiaController from '@/actions/Patronato/Presentacion/Controllers/GuardiaController';
import IncidenciaController from '@/actions/Patronato/Presentacion/Controllers/IncidenciaController';
import EgresoController from '@/actions/Patronato/Presentacion/Controllers/EgresoController';
import EstadoCuentaController from '@/actions/Patronato/Presentacion/Controllers/EstadoCuentaController';
import IngresoController from '@/actions/Patronato/Presentacion/Controllers/IngresoController';
import InformeFinancieroController from '@/actions/Patronato/Presentacion/Controllers/InformeFinancieroController';
import PagoController from '@/actions/Patronato/Presentacion/Controllers/PagoController';
import PresupuestoController from '@/actions/Patronato/Presentacion/Controllers/PresupuestoController';
import ParametroCobroController from '@/actions/Patronato/Presentacion/Controllers/ParametroCobroController';
import PortalTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/PortalTransferenciaController';
import ReporteCobrosController from '@/actions/Patronato/Presentacion/Controllers/ReporteCobrosController';
import TransferenciaController from '@/actions/Patronato/Presentacion/Controllers/TransferenciaController';
import TurnoController from '@/actions/Patronato/Presentacion/Controllers/TurnoController';
import VerificacionTransferenciaController from '@/actions/Patronato/Presentacion/Controllers/VerificacionTransferenciaController';
import AuditoriaController from '@/actions/Patronato/Presentacion/Controllers/AuditoriaController';
import CuentaController from '@/actions/Patronato/Presentacion/Controllers/CuentaController';
import VehiculoController from '@/actions/Patronato/Presentacion/Controllers/VehiculoController';
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
import type { NavGroup, NavItem } from '@/types';

type Opcion = NavItem & { visible: boolean };

type Grupo = Omit<NavGroup, 'items'> & { items: Opcion[] };

export function AppSidebar() {
    const { auth } = usePage().props;

    const permite = (permiso: string) => auth.permisos.includes(permiso);

    // Solo se muestran las opciones que el usuario puede usar, agrupadas por tema
    const opciones: (Opcion | Grupo)[] = [
        { title: 'Panel', href: dashboard(), icon: LayoutGrid, visible: true },
        {
            title: 'Mis reportes',
            href: PortalTransferenciaController.index(),
            icon: ReceiptText,
            visible: auth.esResidente && permite('portal.ver'),
        },
        {
            title: 'Reportar transferencia',
            href: PortalTransferenciaController.create(),
            icon: Send,
            visible: auth.esResidente && permite('portal.registrar'),
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
            visible: permite('padron.ver'),
        },
        {
            title: 'Cobranza',
            icon: Coins,
            items: [
                {
                    title: 'Registrar cobro',
                    href: CobroController.create(),
                    icon: HandCoins,
                    visible: permite('cobros.registrar'),
                },
                {
                    title: 'Registrar transferencia',
                    href: TransferenciaController.create(),
                    icon: Landmark,
                    visible: permite('transferencias.registrar'),
                },
                {
                    title: 'Transferencias por verificar',
                    href: VerificacionTransferenciaController.index(),
                    icon: BadgeCheck,
                    badge: auth.transferenciasPorVerificar,
                    visible: permite('transferencias.verificar'),
                },
                {
                    title: 'Cobros',
                    href: PagoController.index(),
                    icon: Wallet,
                    visible: permite('cobros.ver'),
                },
                {
                    title: 'Estado de cuenta',
                    href: EstadoCuentaController.index(),
                    icon: BookOpenText,
                    visible: permite('cobros.ver'),
                },
                {
                    title: 'Anulaciones por aprobar',
                    href: AnulacionController.index(),
                    icon: Ban,
                    badge: auth.anulacionesPendientes,
                    visible: permite('cobros.aprobar'),
                },
                {
                    title: 'Cierre de caja',
                    href: CajaController.index(),
                    icon: Vault,
                    visible: permite('cajas.registrar'),
                },
                {
                    title: 'Parámetros de cobro',
                    href: ParametroCobroController.index(),
                    icon: SlidersHorizontal,
                    visible: permite('parametros.ver'),
                },
            ],
        },
        {
            title: 'Tesorería',
            icon: Banknote,
            items: [
                {
                    title: 'Ingresos',
                    href: IngresoController.index(),
                    icon: PiggyBank,
                    visible: permite('tesoreria.ver'),
                },
                {
                    title: 'Egresos',
                    href: EgresoController.index(),
                    icon: Receipt,
                    visible: permite('tesoreria.ver'),
                },
                {
                    title: 'Presupuesto',
                    href: PresupuestoController.index(),
                    icon: Target,
                    visible: permite('reportes.ver'),
                },
            ],
        },
        {
            title: 'Reportes',
            icon: ChartColumn,
            items: [
                {
                    title: 'Reporte de cobros',
                    href: ReporteCobrosController.index(),
                    icon: ChartColumn,
                    visible: permite('reportes.ver'),
                },
                {
                    title: 'Informe financiero',
                    href: InformeFinancieroController.index(),
                    icon: Landmark,
                    visible: permite('reportes.ver'),
                },
            ],
        },
        {
            title: 'Seguridad',
            icon: ShieldCheck,
            items: [
                {
                    title: 'Guardias',
                    href: GuardiaController.index(),
                    icon: UserCheck,
                    visible: permite('guardias.ver'),
                },
                {
                    title: 'Turnos',
                    href: TurnoController.index(),
                    icon: CalendarDays,
                    visible: permite('turnos.ver'),
                },
                {
                    title: 'Mis turnos',
                    href: TurnoController.misTurnos(),
                    icon: CalendarClock,
                    visible:
                        auth.esGuardia && permite('entrega_turnos.registrar'),
                },
                {
                    title: 'Incidencias',
                    href: IncidenciaController.index(),
                    icon: TriangleAlert,
                    visible: permite('incidencias.ver'),
                },
                {
                    title: 'Reportar incidencia',
                    href: IncidenciaController.misIncidencias(),
                    icon: TriangleAlert,
                    visible: auth.esGuardia && permite('incidencias.registrar'),
                },
                {
                    title: 'Vehículos',
                    href: VehiculoController.index(),
                    icon: Car,
                    visible: permite('vehiculos.ver'),
                },
                {
                    title: 'Carnets',
                    href: CarnetController.index(),
                    icon: IdCard,
                    visible: permite('carnets.ver'),
                },
            ],
        },
        {
            title: 'Administración',
            icon: Settings2,
            items: [
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
                    visible: permite('auditoria.ver'),
                },
            ],
        },
    ];

    // Un grupo sin opciones no aparece y uno con una sola opción se muestra suelto
    const items = opciones.flatMap((opcion): (NavItem | NavGroup)[] => {
        if (!('items' in opcion)) {
            return opcion.visible ? [opcion] : [];
        }

        const visibles = opcion.items.filter((item) => item.visible);

        return visibles.length > 1
            ? [{ ...opcion, items: visibles }]
            : visibles;
    });

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
