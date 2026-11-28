import { Form, Head, Link } from '@inertiajs/react';
import { Check, Eye, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import SolicitudCorreccionController from '@/actions/Patronato/Presentacion/Controllers/SolicitudCorreccionController';
import InputError from '@/components/input-error';
import EstadoSolicitud from '@/components/solicitudes/estado-solicitud';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFechaHora } from '@/lib/formato';
import { cn } from '@/lib/utils';
import type { SolicitudBandeja, TiposSolicitud } from '@/types/solicitudes';

type Paginado<T> = {
    data: T[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Vista = 'abiertas' | 'cerradas';

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

function Cierre({
    solicitud,
    resolver,
    onCancelar,
}: {
    solicitud: SolicitudBandeja;
    resolver: boolean;
    onCancelar: () => void;
}) {
    const accion = resolver
        ? SolicitudCorreccionController.resolver
        : SolicitudCorreccionController.rechazar;
    const id = `respuesta-${solicitud.id}`;

    return (
        <Form
            {...accion.form(solicitud.id)}
            options={{ preserveScroll: true }}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={id}>
                            {resolver
                                ? 'Respuesta: qué se corrigió'
                                : 'Respuesta: por qué se rechaza'}
                        </Label>
                        <textarea
                            id={id}
                            name="respuesta"
                            rows={3}
                            minLength={5}
                            maxLength={1000}
                            required
                            className={claseTexto}
                        />
                        <p className="text-sm text-muted-foreground">
                            El residente la recibe por correo y la ve en el
                            portal.
                        </p>
                        <InputError message={errors.respuesta} />
                        <InputError message={errors.solicitud} />
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant={resolver ? 'default' : 'destructive'}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {resolver
                                ? 'Marcar como resuelta'
                                : 'Rechazar solicitud'}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onCancelar}
                        >
                            Cancelar
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Acciones({ solicitud }: { solicitud: SolicitudBandeja }) {
    const [cierre, setCierre] = useState<'resolver' | 'rechazar' | null>(null);

    if (cierre !== null) {
        return (
            <Cierre
                solicitud={solicitud}
                resolver={cierre === 'resolver'}
                onCancelar={() => setCierre(null)}
            />
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {solicitud.puede_tomar && (
                <Form
                    {...SolicitudCorreccionController.revisar.form(
                        solicitud.id,
                    )}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <Button variant="outline" disabled={processing}>
                                {processing ? <Spinner /> : <Eye />}
                                Tomar en revisión
                            </Button>
                            <InputError message={errors.solicitud} />
                        </>
                    )}
                </Form>
            )}
            <Button onClick={() => setCierre('resolver')}>
                <Check />
                Resolver
            </Button>
            <Button variant="outline" onClick={() => setCierre('rechazar')}>
                <X />
                Rechazar
            </Button>
        </div>
    );
}

function TarjetaSolicitud({
    solicitud,
    tipos,
}: {
    solicitud: SolicitudBandeja;
    tipos: TiposSolicitud;
}) {
    return (
        <li className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">
                        {tipos[solicitud.tipo] ?? solicitud.tipo}
                        {solicitud.vivienda &&
                            ` · Vivienda ${solicitud.vivienda}`}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {solicitud.residente} ·{' '}
                        {formatoFechaHora(solicitud.creada_en)}
                    </p>
                </div>
                <EstadoSolicitud estado={solicitud.estado} />
            </div>

            <p className="text-sm whitespace-pre-line">
                {solicitud.descripcion}
            </p>

            {solicitud.tiene_adjunto && (
                <a
                    href={SolicitudCorreccionController.adjunto.url(
                        solicitud.id,
                    )}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <Paperclip className="size-4" />
                    Ver archivo adjunto
                </a>
            )}

            {(solicitud.atendida_por || solicitud.respuesta) && (
                <div className="rounded-lg bg-muted/50 p-3 text-sm">
                    {solicitud.atendida_por && (
                        <p className="text-muted-foreground">
                            Atendió {solicitud.atendida_por} ·{' '}
                            {formatoFechaHora(solicitud.actualizada_en)}
                        </p>
                    )}
                    {solicitud.respuesta && (
                        <p className="mt-1 whitespace-pre-line">
                            {solicitud.respuesta}
                        </p>
                    )}
                </div>
            )}

            {solicitud.puede_cerrar && <Acciones solicitud={solicitud} />}
        </li>
    );
}

export default function Bandeja({
    solicitudes,
    vista,
    tipos,
}: {
    solicitudes: Paginado<SolicitudBandeja>;
    vista: Vista;
    tipos: TiposSolicitud;
}) {
    const vistas: { valor: Vista; titulo: string }[] = [
        { valor: 'abiertas', titulo: 'Por atender' },
        { valor: 'cerradas', titulo: 'Cerradas' },
    ];

    return (
        <>
            <Head title="Solicitudes de corrección" />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Solicitudes de corrección
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        La bandeja no cambia datos: corrige en el módulo que
                        corresponde (viviendas, vehículos, carnets o cobros) y
                        luego cierra la solicitud con una respuesta.
                    </p>
                </div>

                <nav className="flex gap-2" aria-label="Vista de la bandeja">
                    {vistas.map((opcion) => (
                        <Link
                            key={opcion.valor}
                            href={SolicitudCorreccionController.index.url({
                                query:
                                    opcion.valor === 'cerradas'
                                        ? { vista: 'cerradas' }
                                        : {},
                            })}
                            aria-current={
                                vista === opcion.valor ? 'page' : undefined
                            }
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm font-medium',
                                vista === opcion.valor
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted',
                            )}
                        >
                            {opcion.titulo}
                        </Link>
                    ))}
                </nav>

                {solicitudes.data.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-muted-foreground">
                        {vista === 'abiertas'
                            ? 'No hay solicitudes por atender.'
                            : 'Todavía no hay solicitudes cerradas.'}
                    </p>
                ) : (
                    <ul className="space-y-4">
                        {solicitudes.data.map((solicitud) => (
                            <TarjetaSolicitud
                                key={solicitud.id}
                                solicitud={solicitud}
                                tipos={tipos}
                            />
                        ))}
                    </ul>
                )}

                {(solicitudes.prev_page_url || solicitudes.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {solicitudes.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={solicitudes.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {solicitudes.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={solicitudes.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}
