import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CarnetController from '@/actions/Patronato/Presentacion/Controllers/CarnetController';
import EstadoCredencial from '@/components/credenciales/estado-credencial';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFecha } from '@/lib/formato';
import type { Carnet, ResidenteCarnets } from '@/types/carnets';
import type { AccionCredencial } from '@/types/credenciales';
import { CREDENCIAL_VIGENTE } from '@/types/credenciales';

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const TEXTO_ACCION: Record<AccionCredencial, string> = {
    suspender: 'Suspender',
    reactivar: 'Reactivar',
    vencer: 'Marcar como vencido',
    reemplazar: 'Renovar',
    dar_de_baja: 'Dar de baja',
};

// Carnet vigente del residente, sus cambios de estado e historial
export default function CarnetsDialog({
    residente,
    vigenciaPredeterminada,
    onClose,
}: {
    residente: ResidenteCarnets;
    vigenciaPredeterminada: string;
    onClose: () => void;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('carnets.registrar');
    const puedeAnular = auth.permisos.includes('carnets.anular');
    const [accion, setAccion] = useState<AccionCredencial | null>(null);

    const vigente = residente.carnets.find((carnet) =>
        CREDENCIAL_VIGENTE.includes(carnet.estado),
    );
    const anteriores = residente.carnets.filter(
        (carnet) => carnet.id !== vigente?.id,
    );

    // Cada acción la ofrece solo quien tiene el permiso que la ruta exige
    const disponibles = (vigente?.acciones ?? []).filter((nombre) =>
        nombre === 'dar_de_baja' ? puedeAnular : puedeRegistrar,
    );

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-lg">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Carnets de {residente.nombre}
                </DialogTitle>
                <DialogDescription>
                    Un residente tiene un solo carnet a la vez. El número lo
                    asigna el sistema y nunca se repite.
                </DialogDescription>

                {vigente ? (
                    <section className="space-y-3 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-lg font-semibold">
                                    {vigente.numero}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Emitido el{' '}
                                    {formatoFecha(vigente.fecha_emision)}
                                    {vigente.vigente_hasta &&
                                        ` · vale hasta el ${formatoFecha(vigente.vigente_hasta)}`}
                                </p>
                                {vigente.motivo &&
                                    vigente.estado !== 'activo' && (
                                        <p className="text-sm text-muted-foreground">
                                            {vigente.motivo}
                                        </p>
                                    )}
                            </div>
                            <EstadoCredencial estado={vigente.estado} />
                        </div>

                        {disponibles.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {disponibles.map((nombre) => (
                                    <Button
                                        key={nombre}
                                        type="button"
                                        size="sm"
                                        variant={
                                            accion === nombre
                                                ? 'secondary'
                                                : nombre === 'dar_de_baja'
                                                  ? 'destructive'
                                                  : 'outline'
                                        }
                                        onClick={() =>
                                            setAccion(
                                                accion === nombre
                                                    ? null
                                                    : nombre,
                                            )
                                        }
                                    >
                                        {TEXTO_ACCION[nombre]}
                                    </Button>
                                ))}
                            </div>
                        )}

                        {accion && (
                            <FormularioAccion
                                key={accion}
                                carnet={vigente}
                                accion={accion}
                                vigenciaPredeterminada={vigenciaPredeterminada}
                                onListo={() => setAccion(null)}
                            />
                        )}
                    </section>
                ) : (
                    puedeRegistrar && (
                        <Form
                            {...CarnetController.emitir.form(residente.id)}
                            options={{ preserveScroll: true }}
                            className="grid gap-3 rounded-xl border p-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <CampoVigencia
                                        predeterminada={vigenciaPredeterminada}
                                        error={errors.vigente_hasta}
                                    />
                                    <Button disabled={processing}>
                                        {processing && <Spinner />}
                                        Emitir carnet
                                    </Button>
                                </>
                            )}
                        </Form>
                    )
                )}

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">Historial</h3>
                    {anteriores.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {vigente
                                ? 'Es el primer carnet del residente.'
                                : 'El residente no ha tenido carnets.'}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {anteriores.map((carnet) => (
                                <HistorialCarnet
                                    key={carnet.id}
                                    carnet={carnet}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </DialogContent>
        </Dialog>
    );
}

function CampoVigencia({
    predeterminada,
    error,
}: {
    predeterminada: string;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor="vigente_hasta">Vale hasta</Label>
            <Input
                id="vigente_hasta"
                name="vigente_hasta"
                type="date"
                defaultValue={predeterminada}
                required
            />
            <InputError message={error} />
        </div>
    );
}

function HistorialCarnet({ carnet }: { carnet: Carnet }) {
    return (
        <li className="flex items-start justify-between gap-3 p-3">
            <div>
                <p className="font-medium">{carnet.numero}</p>
                <p className="text-muted-foreground">
                    Emitido el {formatoFecha(carnet.fecha_emision)}
                    {carnet.vigente_hasta &&
                        ` · hasta el ${formatoFecha(carnet.vigente_hasta)}`}
                    {carnet.motivo && ` · ${carnet.motivo}`}
                </p>
            </div>
            <EstadoCredencial estado={carnet.estado} />
        </li>
    );
}

function FormularioAccion({
    carnet,
    accion,
    vigenciaPredeterminada,
    onListo,
}: {
    carnet: Carnet;
    accion: AccionCredencial;
    vigenciaPredeterminada: string;
    onListo: () => void;
}) {
    const definicion =
        accion === 'reemplazar'
            ? CarnetController.renovar.form(carnet.id)
            : accion === 'dar_de_baja'
              ? CarnetController.baja.form(carnet.id)
              : CarnetController.cambiarEstado.form(carnet.id);

    return (
        <Form
            {...definicion}
            options={{ preserveScroll: true }}
            onSuccess={onListo}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    {accion !== 'reemplazar' && accion !== 'dar_de_baja' && (
                        <input type="hidden" name="accion" value={accion} />
                    )}
                    {accion === 'reemplazar' && (
                        <CampoVigencia
                            predeterminada={vigenciaPredeterminada}
                            error={errors.vigente_hasta}
                        />
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="motivo_carnet">Motivo</Label>
                        <textarea
                            id="motivo_carnet"
                            name="motivo"
                            rows={2}
                            maxLength={1000}
                            required
                            autoFocus
                            className={claseTexto}
                        />
                        <InputError message={errors.motivo} />
                        <InputError message={errors.accion} />
                    </div>
                    <Button
                        variant={
                            accion === 'dar_de_baja' ? 'destructive' : 'default'
                        }
                        disabled={processing}
                        className="justify-self-start"
                    >
                        {processing && <Spinner />}
                        {TEXTO_ACCION[accion]}
                    </Button>
                </>
            )}
        </Form>
    );
}
