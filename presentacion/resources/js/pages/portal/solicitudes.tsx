import { Form, Head, usePage } from '@inertiajs/react';
import { CircleAlert, Paperclip } from 'lucide-react';
import PortalSolicitudController from '@/actions/Patronato/Presentacion/Controllers/PortalSolicitudController';
import InputError from '@/components/input-error';
import EstadoSolicitud from '@/components/solicitudes/estado-solicitud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFechaHora } from '@/lib/formato';
import type { SolicitudPortal, TiposSolicitud } from '@/types/solicitudes';

type OpcionVivienda = { id: number; codigo: string; activa: boolean };

const claseCampo =
    'rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

function NuevaSolicitud({
    viviendas,
    tipos,
    inicial,
    tamanoMaximoMb,
}: {
    viviendas: OpcionVivienda[];
    tipos: TiposSolicitud;
    inicial: { tipo: string | null; vivienda_id: number | null };
    tamanoMaximoMb: number;
}) {
    // Solo se preelige una vivienda donde el residente vive hoy
    const viviendaInicial = viviendas.some(
        (vivienda) => vivienda.id === inicial.vivienda_id,
    )
        ? String(inicial.vivienda_id)
        : '';

    return (
        <Form
            {...PortalSolicitudController.store.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess={['descripcion', 'adjunto']}
            className="grid gap-4 rounded-xl border p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h2 className="text-lg font-semibold">Nueva solicitud</h2>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="tipo">
                                ¿Qué quieres corregir? (obligatorio)
                            </Label>
                            <select
                                id="tipo"
                                name="tipo"
                                required
                                defaultValue={inicial.tipo ?? ''}
                                className={`h-9 ${claseCampo}`}
                                aria-invalid={errors.tipo ? true : undefined}
                            >
                                <option value="" disabled>
                                    Elige el tipo
                                </option>
                                {Object.entries(tipos).map(
                                    ([valor, nombre]) => (
                                        <option key={valor} value={valor}>
                                            {nombre}
                                        </option>
                                    ),
                                )}
                            </select>
                            <InputError message={errors.tipo} />
                        </div>

                        {viviendas.length === 1 ? (
                            <div className="grid gap-2">
                                <Label>Vivienda</Label>
                                <p className="flex h-9 items-center font-semibold">
                                    {viviendas[0].codigo}
                                </p>
                                <input
                                    type="hidden"
                                    name="vivienda_id"
                                    value={viviendas[0].id}
                                />
                                <InputError message={errors.vivienda_id} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="vivienda_id">
                                    Vivienda (obligatoria)
                                </Label>
                                <select
                                    id="vivienda_id"
                                    name="vivienda_id"
                                    required
                                    defaultValue={viviendaInicial}
                                    className={`h-9 ${claseCampo}`}
                                    aria-invalid={
                                        errors.vivienda_id ? true : undefined
                                    }
                                >
                                    <option value="" disabled>
                                        Elige la vivienda
                                    </option>
                                    {viviendas.map((vivienda) => (
                                        <option
                                            key={vivienda.id}
                                            value={vivienda.id}
                                        >
                                            {vivienda.codigo}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.vivienda_id} />
                            </div>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="descripcion">
                            Descripción (obligatoria)
                        </Label>
                        <textarea
                            id="descripcion"
                            name="descripcion"
                            rows={4}
                            minLength={10}
                            maxLength={2000}
                            required
                            placeholder="Indica qué dato está mal y cuál es el correcto."
                            className={`py-2 ${claseCampo}`}
                            aria-invalid={errors.descripcion ? true : undefined}
                        />
                        <InputError message={errors.descripcion} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adjunto">
                            Archivo adjunto (opcional)
                        </Label>
                        <Input
                            id="adjunto"
                            name="adjunto"
                            type="file"
                            accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        />
                        <p className="text-sm text-muted-foreground">
                            Imagen JPG o PNG, o PDF, de hasta {tamanoMaximoMb}{' '}
                            MB.
                        </p>
                        <InputError message={errors.adjunto} />
                    </div>

                    <Button
                        className="h-12 w-full text-base"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Enviar solicitud
                    </Button>
                </>
            )}
        </Form>
    );
}

function ListaSolicitudes({
    solicitudes,
    tipos,
}: {
    solicitudes: SolicitudPortal[];
    tipos: TiposSolicitud;
}) {
    if (solicitudes.length === 0) {
        return (
            <p className="rounded-xl border p-6 text-center text-muted-foreground">
                Todavía no has enviado solicitudes.
            </p>
        );
    }

    return (
        <ul className="divide-y rounded-xl border">
            {solicitudes.map((solicitud) => (
                <li key={solicitud.id} className="space-y-2 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="font-semibold">
                                {tipos[solicitud.tipo] ?? solicitud.tipo}
                                {solicitud.vivienda &&
                                    ` · Vivienda ${solicitud.vivienda}`}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Enviada el{' '}
                                {formatoFechaHora(solicitud.creada_en)}
                            </p>
                        </div>
                        <EstadoSolicitud estado={solicitud.estado} />
                    </div>
                    <p className="text-sm whitespace-pre-line">
                        {solicitud.descripcion}
                    </p>
                    {solicitud.respuesta && (
                        <div className="rounded-lg bg-muted/50 p-3 text-sm">
                            <p className="text-muted-foreground">
                                Respuesta de la Junta Directiva ·{' '}
                                {formatoFechaHora(solicitud.actualizada_en)}
                            </p>
                            <p className="mt-1 whitespace-pre-line">
                                {solicitud.respuesta}
                            </p>
                        </div>
                    )}
                    {solicitud.tiene_adjunto && (
                        <a
                            href={PortalSolicitudController.adjunto.url(
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
                </li>
            ))}
        </ul>
    );
}

export default function Solicitudes({
    solicitudes,
    viviendas,
    tipos,
    inicial,
    tamanoMaximoMb,
}: {
    solicitudes: SolicitudPortal[];
    viviendas: OpcionVivienda[];
    tipos: TiposSolicitud;
    inicial: { tipo: string | null; vivienda_id: number | null };
    tamanoMaximoMb: number;
}) {
    const { auth } = usePage().props;
    const puedeSolicitar = auth.permisos.includes('portal.registrar');

    return (
        <>
            <Head title="Mis solicitudes" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Mis solicitudes
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Pide que se corrija un dato o reporta un pago que no
                        aparece.
                    </p>
                </div>

                {puedeSolicitar &&
                    (viviendas.length === 0 ? (
                        <p className="rounded-xl border p-6 text-center text-muted-foreground">
                            No tienes viviendas asignadas. Consulta con el
                            patronato.
                        </p>
                    ) : (
                        <>
                            <Alert>
                                <CircleAlert />
                                <AlertTitle>
                                    La Junta Directiva revisa cada solicitud
                                </AlertTitle>
                                <AlertDescription>
                                    Enviarla no cambia ningún dato. Te
                                    avisaremos por correo cuando se resuelva o
                                    se rechace.
                                </AlertDescription>
                            </Alert>
                            <NuevaSolicitud
                                viviendas={viviendas}
                                tipos={tipos}
                                inicial={inicial}
                                tamanoMaximoMb={tamanoMaximoMb}
                            />
                        </>
                    ))}

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Enviadas</h2>
                    <ListaSolicitudes solicitudes={solicitudes} tipos={tipos} />
                </section>
            </div>
        </>
    );
}
