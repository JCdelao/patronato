import { Head, Link, router } from '@inertiajs/react';
import { Building2, KeyRound } from 'lucide-react';
import { useState } from 'react';
import ResidencialController from '@/actions/Patronato/Modules/Plataforma/Controllers/ResidencialController';
import Heading from '@/components/heading';
import EstadoResidencialDialog from '@/components/plataforma/estado-residencial-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Residencial = {
    id: number;
    nombre: string;
    direccion: string | null;
    telefono: string | null;
    correo: string | null;
    estado: string;
    motivo_suspension: string | null;
    logo_url: string | null;
};

type Credenciales = {
    residencial: string;
    correo: string;
    password_temporal: string;
};

export default function Residenciales({
    residenciales,
    credenciales,
}: {
    residenciales: Residencial[];
    credenciales: Credenciales | null;
}) {
    const [seleccionado, setSeleccionado] = useState<Residencial | null>(null);

    const alGuardar = () => {
        setSeleccionado(null);
        router.reload({ only: ['residenciales'] });
    };

    return (
        <>
            <Head title="Residenciales" />

            <div className="space-y-6 p-6 md:p-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Residenciales"
                        description="Residenciales registrados en la plataforma"
                    />

                    <Button asChild>
                        <Link href={ResidencialController.create()}>
                            Registrar residencial
                        </Link>
                    </Button>
                </div>

                {credenciales && (
                    <Alert>
                        <KeyRound />
                        <AlertTitle>
                            Cuenta creada para {credenciales.residencial}
                        </AlertTitle>
                        <AlertDescription>
                            <p>
                                No se pudo enviar el correo. Copia estos datos y
                                entrégalos al administrador técnico. La
                                contraseña temporal no se volverá a mostrar.
                            </p>
                            <p>
                                Correo: <strong>{credenciales.correo}</strong>
                            </p>
                            <p>
                                Contraseña temporal:{' '}
                                <code className="font-mono font-semibold break-all text-foreground">
                                    {credenciales.password_temporal}
                                </code>
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {residenciales.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Todavía no hay residenciales registrados.
                    </p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-card">
                        {residenciales.map((residencial) => (
                            <li
                                key={residencial.id}
                                className="flex flex-wrap items-center gap-4 p-4"
                            >
                                {residencial.logo_url ? (
                                    <img
                                        src={residencial.logo_url}
                                        alt=""
                                        className="size-10 rounded-md object-contain"
                                    />
                                ) : (
                                    <Building2 className="size-10 p-2 text-muted-foreground" />
                                )}

                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {residencial.nombre}
                                    </p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {[
                                            residencial.direccion,
                                            residencial.telefono,
                                            residencial.correo,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {residencial.motivo_suspension && (
                                        <p className="text-sm text-destructive">
                                            Motivo:{' '}
                                            {residencial.motivo_suspension}
                                        </p>
                                    )}
                                </div>

                                <Badge
                                    variant={
                                        residencial.estado === 'suspendido'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {residencial.estado}
                                </Badge>

                                <Button
                                    variant="outline"
                                    onClick={() => setSeleccionado(residencial)}
                                >
                                    {residencial.estado === 'suspendido'
                                        ? 'Reactivar'
                                        : 'Suspender'}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {seleccionado && (
                <EstadoResidencialDialog
                    residencial={seleccionado}
                    onClose={() => setSeleccionado(null)}
                    onGuardado={alGuardar}
                />
            )}
        </>
    );
}

Residenciales.layout = {
    breadcrumbs: [
        {
            title: 'Residenciales',
            href: ResidencialController.index(),
        },
    ],
};
