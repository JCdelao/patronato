import { Head, Link, usePage } from '@inertiajs/react';
import { FileText, IdCard, MessageSquareText } from 'lucide-react';
import PortalSolicitudController from '@/actions/Patronato/Presentacion/Controllers/PortalSolicitudController';
import PortalVehiculoController from '@/actions/Patronato/Presentacion/Controllers/PortalVehiculoController';
import EstadoCredencial from '@/components/credenciales/estado-credencial';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import IconoVehiculo from '@/components/vehiculos/icono-vehiculo';
import { formatoFecha } from '@/lib/formato';
import type {
    ResidentePortal,
    VehiculoPortal,
    ViviendaPortal,
} from '@/types/portal-vehiculos';
import { TIPOS_VEHICULO } from '@/types/vehiculos';

function Vacio({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

function TarjetaVehiculo({ vehiculo }: { vehiculo: VehiculoPortal }) {
    const { comprobante, sticker } = vehiculo;
    const descripcion = [vehiculo.marca, vehiculo.modelo, vehiculo.color]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex items-start gap-3">
                    <IconoVehiculo
                        tipo={vehiculo.tipo}
                        className="mt-0.5 size-5 text-muted-foreground"
                    />
                    <div>
                        <p className="font-semibold">
                            {vehiculo.placa ?? 'Sin placa'}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {TIPOS_VEHICULO[vehiculo.tipo]}
                            {descripcion !== '' && ` · ${descripcion}`}
                        </p>
                    </div>
                </div>
                {vehiculo.comprobante_vencido && (
                    <Badge variant="destructive">Comprobante vencido</Badge>
                )}
            </div>

            {comprobante !== null && (
                <div className="space-y-1 rounded-lg bg-muted/50 p-3 text-sm">
                    <p>
                        <span className="font-medium">
                            {comprobante.tipo ?? 'Comprobante'}
                        </span>
                        {comprobante.descripcion &&
                            ` (${comprobante.descripcion})`}
                        {' · '}N.º {comprobante.numero}
                    </p>
                    <p className="text-muted-foreground">
                        {comprobante.vence
                            ? `Vence el ${formatoFecha(comprobante.vence)}`
                            : 'Sin fecha de vencimiento'}
                    </p>
                    {comprobante.tiene_archivo && (
                        <a
                            href={PortalVehiculoController.comprobante.url(
                                vehiculo.id,
                            )}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-medium underline underline-offset-4"
                        >
                            <FileText className="size-4" aria-hidden="true" />
                            Ver comprobante
                        </a>
                    )}
                </div>
            )}

            <dl className="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-muted-foreground">Sticker</dt>
                    <dd className="mt-0.5 flex flex-wrap items-center gap-2">
                        {sticker === null ? (
                            'Sin sticker'
                        ) : (
                            <>
                                N.º {sticker.numero}
                                <EstadoCredencial estado={sticker.estado} />
                            </>
                        )}
                    </dd>
                    {sticker !== null && (
                        <dd className="text-muted-foreground">
                            Emitido el {formatoFecha(sticker.fecha_emision)}
                        </dd>
                    )}
                </div>
                <div>
                    <dt className="text-muted-foreground">Conductores</dt>
                    <dd className="mt-0.5">
                        {vehiculo.conductores.length > 0
                            ? vehiculo.conductores.join(', ')
                            : 'Sin conductores registrados'}
                    </dd>
                </div>
            </dl>
        </li>
    );
}

function FilaResidente({ residente }: { residente: ResidentePortal }) {
    const { carnet } = residente;

    return (
        <li className="flex flex-wrap items-center justify-between gap-2 p-4">
            <div className="flex items-start gap-3">
                <IdCard
                    className="mt-0.5 size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <p className="font-medium">{residente.nombre}</p>
                    <p className="text-sm text-muted-foreground">
                        {carnet === null
                            ? 'Sin carnet'
                            : `Carnet N.º ${carnet.numero} · ${
                                  carnet.vigente_hasta
                                      ? `vigente hasta el ${formatoFecha(carnet.vigente_hasta)}`
                                      : 'sin vencimiento'
                              }`}
                    </p>
                </div>
            </div>
            {carnet !== null && <EstadoCredencial estado={carnet.estado} />}
        </li>
    );
}

// Abre Mis solicitudes con el tipo y la vivienda ya elegidos (HU-54)
function SolicitarCorreccion({
    tipo,
    viviendaId,
}: {
    tipo: 'vehiculo' | 'carnet';
    viviendaId: number;
}) {
    const { auth } = usePage().props;

    if (!auth.permisos.includes('portal.registrar')) {
        return null;
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link
                href={PortalSolicitudController.index.url({
                    query: { tipo, vivienda: viviendaId },
                })}
            >
                <MessageSquareText />
                Solicitar corrección
            </Link>
        </Button>
    );
}

function SeccionVivienda({ vivienda }: { vivienda: ViviendaPortal }) {
    return (
        <section className="space-y-6">
            <h2 className="flex items-center gap-2 text-xl font-semibold">
                Vivienda {vivienda.codigo}
                {!vivienda.activa && <Badge variant="outline">Inactiva</Badge>}
            </h2>

            <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold">Vehículos</h3>
                    <SolicitarCorreccion
                        tipo="vehiculo"
                        viviendaId={vivienda.id}
                    />
                </div>
                {vivienda.vehiculos.length === 0 ? (
                    <Vacio>No hay vehículos registrados.</Vacio>
                ) : (
                    <ul className="space-y-3">
                        {vivienda.vehiculos.map((vehiculo) => (
                            <TarjetaVehiculo
                                key={vehiculo.id}
                                vehiculo={vehiculo}
                            />
                        ))}
                    </ul>
                )}
            </div>

            <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold">Carnets</h3>
                    <SolicitarCorreccion
                        tipo="carnet"
                        viviendaId={vivienda.id}
                    />
                </div>
                {vivienda.residentes.length === 0 ? (
                    <Vacio>No hay residentes registrados.</Vacio>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {vivienda.residentes.map((residente) => (
                            <FilaResidente
                                key={residente.id}
                                residente={residente}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

export default function MisVehiculos({
    viviendas,
}: {
    viviendas: ViviendaPortal[];
}) {
    return (
        <>
            <Head title="Mis vehículos y carnets" />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Mis vehículos y carnets
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Revisa que los datos registrados en tu vivienda sean
                        correctos. Si algo no coincide, solicita una corrección.
                    </p>
                </div>

                {viviendas.length === 0 ? (
                    <Vacio>
                        No tienes viviendas asignadas. Consulta con el
                        patronato.
                    </Vacio>
                ) : (
                    viviendas.map((vivienda) => (
                        <SeccionVivienda
                            key={vivienda.id}
                            vivienda={vivienda}
                        />
                    ))
                )}
            </div>
        </>
    );
}
