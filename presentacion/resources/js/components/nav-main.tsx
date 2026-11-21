import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup, NavItem } from '@/types';

export function NavMain({ items }: { items: (NavItem | NavGroup)[] }) {
    return (
        <SidebarGroup className="px-3 py-0">
            <SidebarMenu className="gap-1.5">
                {items.map((item) =>
                    'items' in item ? (
                        <Grupo key={item.title} grupo={item} />
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            <Opcion item={item} />
                        </SidebarMenuItem>
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}

// Se abre solo si contiene la página actual
function Grupo({ grupo }: { grupo: NavGroup }) {
    const { isCurrentUrl } = useCurrentUrl();
    const pendientes = grupo.items.reduce(
        (total, item) => total + (item.badge ?? 0),
        0,
    );

    return (
        <Collapsible
            asChild
            defaultOpen={grupo.items.some((item) => isCurrentUrl(item.href))}
            className="group/grupo"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton size="lg" className="rounded-xl px-4">
                        <grupo.icon />
                        <span>{grupo.title}</span>
                        <ChevronRight className="ml-auto transition-transform group-data-[state=open]/grupo:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                {pendientes > 0 && (
                    <SidebarMenuBadge className="right-9 bg-destructive text-white group-data-[state=open]/grupo:hidden">
                        {pendientes}
                    </SidebarMenuBadge>
                )}
                <CollapsibleContent>
                    <SidebarMenuSub className="mt-1 mr-0 gap-1 pr-0">
                        {grupo.items.map((item) => (
                            <SidebarMenuSubItem
                                key={item.title}
                                className="group/menu-item"
                            >
                                <Opcion item={item} />
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

function Opcion({ item }: { item: NavItem }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            {item.disabled ? (
                <SidebarMenuButton
                    size="lg"
                    disabled
                    tooltip={{ children: 'Próximamente' }}
                    className="rounded-xl px-4"
                >
                    {item.icon && <item.icon />}
                    <span>{item.title}</span>
                </SidebarMenuButton>
            ) : (
                <SidebarMenuButton
                    asChild
                    size="lg"
                    isActive={isCurrentUrl(item.href)}
                    tooltip={{ children: item.title }}
                    className="rounded-xl px-4"
                >
                    <Link href={item.href}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                    </Link>
                </SidebarMenuButton>
            )}
            {item.badge !== undefined && item.badge > 0 && (
                <SidebarMenuBadge className="bg-destructive text-white">
                    {item.badge}
                </SidebarMenuBadge>
            )}
        </>
    );
}
