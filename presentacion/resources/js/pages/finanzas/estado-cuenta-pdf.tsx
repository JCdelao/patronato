import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileDown, Search, X } from 'lucide-react';
import { useRef } from 'react';
import EstadoCuentaPdfController from '@/actions/Patronato/Presentacion/Controllers/EstadoCuentaPdfController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionVivienda = {
    id: number;
    codigo: string;
    responsable: string | null;
    activa: boolean;
};

export default function EstadoCuentaPdf({
    buscar,
    viviendas,
    vivienda,
    rango,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
    vivienda: OpcionVivienda | null;
    rango: { desde: string; hasta: string };
}) {
    return (
        <>
            <Head title="Estado de cuenta en PDF" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Estado de cuenta en PDF
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Descarga los cargos, ajustes y pagos de una vivienda en
                        un rango de fechas, con sus saldos.
                    </p>
                </div>

                {vivienda === null ? (
                    <Buscador buscar={buscar} viviendas={viviendas} />
                ) : (
                    <Rango vivienda={vivienda} rango={rango} />
                )}
            </div>
        </>
    );
}

function Buscador({
    buscar,
    viviendas,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
}) {
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                EstadoCuentaPdfController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <div className="space-y-3">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    aria-label="Buscar vivienda"
                    placeholder="Código de vivienda o responsable"
                    defaultValue={buscar}
                    onChange={(evento) => alBuscar(evento.target.value)}
                    className="h-12 rounded-full pl-11"
                />
            </div>

            {buscar !== '' && viviendas.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No se encontraron viviendas.
                </p>
            )}

            <div className="grid gap-2">
                {viviendas.map((opcion) => (
                    <Link
                        key={opcion.id}
                        href={EstadoCuentaPdfController.index.url({
                            query: { vivienda: opcion.id },
                        })}
                        className="rounded-xl border p-4 hover:bg-muted"
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span className="font-semibold">
                                {opcion.codigo}
                            </span>
                            {!opcion.activa && (
                                <Badge variant="outline">Inactiva</Badge>
                            )}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {opcion.responsable ?? 'Sin responsable de pago'}
                        </span>
                    </Link>
                ))}
            </div>
        </div>
    );
}

function Rango({
    vivienda,
    rango,
}: {
    vivienda: OpcionVivienda;
    rango: { desde: string; hasta: string };
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between rounded-xl border p-4">
                <div>
                    <p className="flex items-center gap-2 font-semibold">
                        {vivienda.codigo}
                        {!vivienda.activa && (
                            <Badge variant="outline">Inactiva</Badge>
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {vivienda.responsable ?? 'Sin responsable de pago'}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Cambiar vivienda"
                    asChild
                >
                    <Link href={EstadoCuentaPdfController.index.url()}>
                        <X />
                    </Link>
                </Button>
            </div>

            {/* Formulario normal: la respuesta es un archivo, no una página */}
            <form
                method="get"
                action={EstadoCuentaPdfController.descargar.url(vivienda.id)}
                className="grid gap-4 rounded-xl border p-4"
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="desde">Desde</Label>
                        <Input
                            id="desde"
                            name="desde"
                            type="date"
                            required
                            defaultValue={rango.desde}
                            max={rango.hasta}
                        />
                        <InputError message={errors.desde} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="hasta">Hasta</Label>
                        <Input
                            id="hasta"
                            name="hasta"
                            type="date"
                            required
                            defaultValue={rango.hasta}
                            max={rango.hasta}
                        />
                        <InputError message={errors.hasta} />
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    Puedes elegir cualquier fecha desde el inicio del historial
                    hasta hoy. Los cargos tienen la fecha del primer día de su
                    período.
                </p>

                <Button className="h-12 w-full text-base">
                    <FileDown />
                    Descargar PDF
                </Button>
            </form>
        </div>
    );
}
