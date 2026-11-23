import { Form, Head } from '@inertiajs/react';
import PortalPreautorizacionController from '@/actions/Patronato/Presentacion/Controllers/PortalPreautorizacionController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function PreautorizarVisita({
    viviendas,
    medios,
}: {
    viviendas: { id: number; codigo: string }[];
    medios: { id: number; nombre: string }[];
}) {
    return (
        <>
            <Head title="Preautorizar visita" />
            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 md:p-10">
                <h1 className="text-3xl font-bold tracking-tight">
                    Preautorizar visita
                </h1>
                {viviendas.length === 0 && (
                    <p>No tienes viviendas activas con vínculo vigente.</p>
                )}
                {medios.length === 0 && (
                    <p>No hay medios de entrada activos en tu residencial.</p>
                )}
                <Form
                    {...PortalPreautorizacionController.store.form()}
                    resetOnSuccess
                    className="grid gap-4 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="vivienda_id">Vivienda</Label>
                                <select
                                    id="vivienda_id"
                                    name="vivienda_id"
                                    required
                                    defaultValue=""
                                    className="h-10 rounded-md border border-input bg-transparent px-3"
                                >
                                    <option value="" disabled>
                                        Elige la vivienda
                                    </option>
                                    {viviendas.map(({ id, codigo }) => (
                                        <option key={id} value={id}>
                                            {codigo}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.vivienda_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="nombre_visitante">
                                    Nombre del visitante
                                </Label>
                                <Input
                                    id="nombre_visitante"
                                    name="nombre_visitante"
                                    maxLength={150}
                                    required
                                />
                                <InputError message={errors.nombre_visitante} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="medio_entrada_id">
                                    Medio de entrada
                                </Label>
                                <select
                                    id="medio_entrada_id"
                                    name="medio_entrada_id"
                                    required
                                    defaultValue=""
                                    className="h-10 rounded-md border border-input bg-transparent px-3"
                                >
                                    <option value="" disabled>
                                        Elige el medio
                                    </option>
                                    {medios.map(({ id, nombre }) => (
                                        <option key={id} value={id}>
                                            {nombre}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.medio_entrada_id} />
                            </div>
                            <fieldset className="grid items-start gap-3 sm:grid-cols-2">
                                <legend className="mb-2 text-sm text-muted-foreground">
                                    Horario de Honduras
                                </legend>
                                <div className="grid gap-2">
                                    <Label htmlFor="inicio">Inicio</Label>
                                    <Input
                                        id="inicio"
                                        name="inicio"
                                        type="datetime-local"
                                        required
                                    />
                                    <InputError message={errors.inicio} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="fin">Fin</Label>
                                    <Input
                                        id="fin"
                                        name="fin"
                                        type="datetime-local"
                                        required
                                    />
                                    <InputError message={errors.fin} />
                                </div>
                            </fieldset>
                            <Button
                                className="justify-self-end"
                                disabled={
                                    processing ||
                                    viviendas.length === 0 ||
                                    medios.length === 0
                                }
                            >
                                {processing
                                    ? 'Guardando…'
                                    : 'Preautorizar visita'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
