import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import IncidenciaController from '@/actions/Patronato/Presentacion/Controllers/IncidenciaController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import IncidenciaItem from '@/components/vigilancia/incidencia-item';
import type { Incidencia, TipoIncidencia } from '@/types/vigilancia';
import { textoFecha } from '@/types/vigilancia';

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

// Fecha y hora actuales como las espera el campo: AAAA-MM-DDTHH:MM
function ahora(): string {
    const fecha = new Date();
    const hora = `${String(fecha.getHours()).padStart(2, '0')}:${String(fecha.getMinutes()).padStart(2, '0')}`;

    return `${textoFecha(fecha)}T${hora}`;
}

// El guardia registra una incidencia y ve las suyas de la última semana
export default function MisIncidencias({
    guardia,
    incidencias,
    tipos,
}: {
    guardia: string;
    incidencias: Incidencia[];
    tipos: TipoIncidencia[];
}) {
    // Cambia la clave para limpiar el formulario después de registrar
    const [formulario, setFormulario] = useState(0);

    return (
        <>
            <Head title="Reportar incidencia" />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Reportar incidencia
                    </h1>
                    <p className="mt-1 text-muted-foreground">{guardia}</p>
                </div>

                <Form
                    key={formulario}
                    {...IncidenciaController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setFormulario((valor) => valor + 1)}
                    className="grid gap-4 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="tipo_incidencia_id">
                                        Tipo
                                    </Label>
                                    <select
                                        id="tipo_incidencia_id"
                                        name="tipo_incidencia_id"
                                        defaultValue=""
                                        required
                                        className={claseSelect}
                                    >
                                        <option value="" disabled>
                                            Elige el tipo
                                        </option>
                                        {tipos.map((tipo) => (
                                            <option
                                                key={tipo.id}
                                                value={tipo.id}
                                            >
                                                {tipo.nombre}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.tipo_incidencia_id}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="ocurrida_en">
                                        Cuándo ocurrió
                                    </Label>
                                    <Input
                                        id="ocurrida_en"
                                        name="ocurrida_en"
                                        type="datetime-local"
                                        defaultValue={ahora()}
                                        required
                                    />
                                    <InputError message={errors.ocurrida_en} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="descripcion">Qué pasó</Label>
                                <textarea
                                    id="descripcion"
                                    name="descripcion"
                                    rows={4}
                                    maxLength={2000}
                                    required
                                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                />
                                <InputError message={errors.descripcion} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="foto">Foto (opcional)</Label>
                                <Input
                                    id="foto"
                                    name="foto"
                                    type="file"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                    capture="environment"
                                />
                                <p className="text-sm text-muted-foreground">
                                    Imagen JPG o PNG de hasta 5 MB.
                                </p>
                                <InputError message={errors.foto} />
                            </div>

                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="urgente"
                                    value="1"
                                    className="mt-0.5 size-4"
                                />
                                <span>
                                    <span className="font-medium">
                                        Es urgente
                                    </span>
                                    <span className="block text-muted-foreground">
                                        Se avisa al presidente por correo en
                                        cuanto se registra.
                                    </span>
                                </span>
                            </label>

                            <Button className="w-fit" disabled={processing}>
                                {processing && <Spinner />}
                                Registrar incidencia
                            </Button>
                        </>
                    )}
                </Form>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        Mis incidencias de la última semana
                    </h2>
                    {incidencias.length === 0 ? (
                        <p className="text-muted-foreground">
                            No has registrado incidencias.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {incidencias.map((incidencia) => (
                                <IncidenciaItem
                                    key={incidencia.id}
                                    incidencia={incidencia}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
