import { Head, usePage } from '@inertiajs/react';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Panel" />
            <div className="space-y-2 p-6 md:p-10">
                <h1 className="text-4xl font-bold tracking-tight">Panel</h1>
                <p className="text-lg text-muted-foreground">
                    {auth.user.residencial_id || auth.esAdminPlataforma
                        ? 'Los indicadores del patronato aparecerán aquí.'
                        : 'Tu cuenta todavía no está asignada a un residencial. Pide a la Junta Directiva que te dé acceso.'}
                </p>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Panel',
            href: dashboard(),
        },
    ],
};
