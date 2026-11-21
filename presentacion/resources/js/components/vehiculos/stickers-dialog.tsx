import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import StickerController from '@/actions/Patronato/Presentacion/Controllers/StickerController';
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
import type { AccionCredencial } from '@/types/credenciales';
import { CREDENCIAL_VIGENTE } from '@/types/credenciales';
import type { Sticker, Vehiculo } from '@/types/vehiculos';
import { identificador } from '@/types/vehiculos';

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const TEXTO_ACCION: Record<AccionCredencial, string> = {
    suspender: 'Suspender',
    reactivar: 'Reactivar',
    vencer: 'Marcar como vencido',
    reemplazar: 'Reemplazar',
    dar_de_baja: 'Dar de baja',
};

// Sticker vigente del vehículo, sus cambios de estado e historial
export default function StickersDialog({
    vehiculo,
    onClose,
}: {
    vehiculo: Vehiculo;
    onClose: () => void;
}) {
    const { auth } = usePage().props;
    const puedeRegistrar = auth.permisos.includes('vehiculos.registrar');
    const puedeAnular = auth.permisos.includes('vehiculos.anular');
    const [accion, setAccion] = useState<AccionCredencial | null>(null);

    const vigente = vehiculo.stickers.find((sticker) =>
        CREDENCIAL_VIGENTE.includes(sticker.estado),
    );
    const anteriores = vehiculo.stickers.filter(
        (sticker) => sticker.id !== vigente?.id,
    );

    // Cada acción la ofrece solo quien tiene el permiso que la ruta exige
    const disponibles = (vigente?.acciones ?? []).filter((nombre) =>
        nombre === 'dar_de_baja' ? puedeAnular : puedeRegistrar,
    );

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-lg">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Stickers de {identificador(vehiculo)}
                </DialogTitle>
                <DialogDescription>
                    Un vehículo tiene un solo sticker a la vez. El número no se
                    repite entre los stickers activos del residencial.
                </DialogDescription>

                {vigente ? (
                    <section className="space-y-3 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-lg font-semibold">
                                    N.º {vigente.numero}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Emitido el{' '}
                                    {formatoFecha(vigente.fecha_emision)}
                                    {vigente.motivo &&
                                        vigente.estado !== 'activo' &&
                                        ` · ${vigente.motivo}`}
                                </p>
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
                                sticker={vigente}
                                accion={accion}
                                onListo={() => setAccion(null)}
                            />
                        )}
                    </section>
                ) : (
                    puedeRegistrar &&
                    vehiculo.activo && (
                        <Form
                            {...StickerController.asignar.form(vehiculo.id)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="grid gap-3 rounded-xl border p-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="numero">
                                            Número del sticker
                                        </Label>
                                        <Input
                                            id="numero"
                                            name="numero"
                                            maxLength={30}
                                            placeholder="0457"
                                            required
                                            autoFocus
                                        />
                                        <InputError message={errors.numero} />
                                    </div>
                                    <Button disabled={processing}>
                                        {processing && <Spinner />}
                                        Asignar sticker
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
                                ? 'Es el primer sticker del vehículo.'
                                : 'El vehículo no ha tenido stickers.'}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {anteriores.map((sticker) => (
                                <HistorialSticker
                                    key={sticker.id}
                                    sticker={sticker}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </DialogContent>
        </Dialog>
    );
}

function HistorialSticker({ sticker }: { sticker: Sticker }) {
    return (
        <li className="flex items-start justify-between gap-3 p-3">
            <div>
                <p className="font-medium">N.º {sticker.numero}</p>
                <p className="text-muted-foreground">
                    Emitido el {formatoFecha(sticker.fecha_emision)}
                    {sticker.motivo && ` · ${sticker.motivo}`}
                </p>
            </div>
            <EstadoCredencial estado={sticker.estado} />
        </li>
    );
}

function FormularioAccion({
    sticker,
    accion,
    onListo,
}: {
    sticker: Sticker;
    accion: AccionCredencial;
    onListo: () => void;
}) {
    const definicion =
        accion === 'reemplazar'
            ? StickerController.reemplazar.form(sticker.id)
            : accion === 'dar_de_baja'
              ? StickerController.baja.form(sticker.id)
              : StickerController.cambiarEstado.form(sticker.id);

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
                        <div className="grid gap-2">
                            <Label htmlFor="numero_nuevo">
                                Número del sticker nuevo
                            </Label>
                            <Input
                                id="numero_nuevo"
                                name="numero"
                                maxLength={30}
                                required
                                autoFocus
                            />
                            <InputError message={errors.numero} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="motivo_sticker">Motivo</Label>
                        <textarea
                            id="motivo_sticker"
                            name="motivo"
                            rows={2}
                            maxLength={1000}
                            required
                            autoFocus={accion !== 'reemplazar'}
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
