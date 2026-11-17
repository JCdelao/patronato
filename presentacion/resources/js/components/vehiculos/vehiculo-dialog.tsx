import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import VehiculoController from '@/actions/Patronato/Presentacion/Controllers/VehiculoController';
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
import type {
    OpcionVivienda,
    Persona,
    TipoVehiculo,
    Vehiculo,
} from '@/types/vehiculos';
import { TIPOS_VEHICULO } from '@/types/vehiculos';

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// Registra un vehículo o corrige uno activo; la vivienda solo se elige al registrar
export default function VehiculoDialog({
    vehiculo,
    viviendas,
    tipos,
    viviendaInicial,
    onClose,
}: {
    vehiculo?: Vehiculo;
    viviendas: OpcionVivienda[];
    tipos: TipoVehiculo[];
    viviendaInicial?: number | null;
    onClose: () => void;
}) {
    const [viviendaId, setViviendaId] = useState<number | null>(
        vehiculo?.vivienda_id ?? viviendaInicial ?? null,
    );
    const [residentes, setResidentes] = useState<Persona[] | null>(null);
    const [errorCarga, setErrorCarga] = useState(false);
    const elegidos = new Set(vehiculo?.conductores.map((c) => c.id) ?? []);

    // Los conductores posibles son los residentes de la vivienda elegida
    useEffect(() => {
        setResidentes(null);
        setErrorCarga(false);

        if (viviendaId === null) {
            return;
        }

        fetch(VehiculoController.conductores.url(viviendaId), {
            headers: { Accept: 'application/json' },
        })
            .then((respuesta) => {
                if (!respuesta.ok) {
                    throw new Error();
                }

                return respuesta.json() as Promise<Persona[]>;
            })
            .then(setResidentes)
            .catch(() => setErrorCarga(true));
    }, [viviendaId]);

    const accion = vehiculo
        ? VehiculoController.update.form(vehiculo.id)
        : VehiculoController.store.form();

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    {vehiculo ? `Editar ${vehiculo.placa}` : 'Agregar vehículo'}
                </DialogTitle>
                <DialogDescription>
                    {vehiculo
                        ? `Vivienda ${vehiculo.vivienda}. Para pasarlo a otra vivienda, dalo de baja y regístralo de nuevo.`
                        : 'Un vehículo pertenece a una sola vivienda.'}
                </DialogDescription>
                <Form
                    {...accion}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {!vehiculo && (
                                <div className="grid gap-2">
                                    <Label htmlFor="vivienda_id">
                                        Vivienda
                                    </Label>
                                    <select
                                        id="vivienda_id"
                                        name="vivienda_id"
                                        value={viviendaId ?? ''}
                                        onChange={(evento) =>
                                            setViviendaId(
                                                evento.target.value === ''
                                                    ? null
                                                    : Number(
                                                          evento.target.value,
                                                      ),
                                            )
                                        }
                                        className={claseSelect}
                                        required
                                    >
                                        <option value="">
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

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="tipo">Tipo</Label>
                                    <select
                                        id="tipo"
                                        name="tipo"
                                        defaultValue={vehiculo?.tipo ?? 'carro'}
                                        className={claseSelect}
                                    >
                                        {tipos.map((tipo) => (
                                            <option key={tipo} value={tipo}>
                                                {TIPOS_VEHICULO[tipo]}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.tipo} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="placa">Placa</Label>
                                    <Input
                                        id="placa"
                                        name="placa"
                                        defaultValue={vehiculo?.placa}
                                        maxLength={20}
                                        placeholder="HAA 1234"
                                        className="uppercase"
                                        required
                                        autoFocus={!!vehiculo}
                                    />
                                    <InputError message={errors.placa} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="marca">Marca</Label>
                                    <Input
                                        id="marca"
                                        name="marca"
                                        defaultValue={vehiculo?.marca ?? ''}
                                        maxLength={60}
                                    />
                                    <InputError message={errors.marca} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="modelo">Modelo</Label>
                                    <Input
                                        id="modelo"
                                        name="modelo"
                                        defaultValue={vehiculo?.modelo ?? ''}
                                        maxLength={60}
                                    />
                                    <InputError message={errors.modelo} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="color">Color</Label>
                                    <Input
                                        id="color"
                                        name="color"
                                        defaultValue={vehiculo?.color ?? ''}
                                        maxLength={30}
                                    />
                                    <InputError message={errors.color} />
                                </div>
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    Conductores autorizados (opcional)
                                </legend>
                                {viviendaId === null ? (
                                    <p className="text-sm text-muted-foreground">
                                        Elige primero la vivienda.
                                    </p>
                                ) : errorCarga ? (
                                    <p className="text-sm text-destructive">
                                        No se pudieron cargar los residentes.
                                    </p>
                                ) : residentes === null ? (
                                    <Spinner />
                                ) : residentes.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        La vivienda no tiene residentes
                                        registrados.
                                    </p>
                                ) : (
                                    residentes.map((residente) => (
                                        <label
                                            key={residente.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                name="conductores[]"
                                                value={residente.id}
                                                defaultChecked={elegidos.has(
                                                    residente.id,
                                                )}
                                                className="size-4"
                                            />
                                            {residente.nombre}
                                        </label>
                                    ))
                                )}
                                <InputError message={errors.conductores} />
                            </fieldset>

                            <Button className="w-full" disabled={processing}>
                                {processing && <Spinner />}
                                Guardar
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
