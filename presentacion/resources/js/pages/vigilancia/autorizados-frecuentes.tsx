import { Form, Head } from '@inertiajs/react';
import AutorizadoFrecuenteController from '@/actions/Patronato/Presentacion/Controllers/AutorizadoFrecuenteController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { diasSemana } from '@/types/autorizaciones-frecuentes';

export default function AutorizadosFrecuentes({
    viviendas,
}: {
    viviendas: { id: number; codigo: string }[];
}) {
    return (
        <>
            <Head title="Autorizaciones frecuentes" />
            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 md:p-10">
                <h1 className="text-3xl font-bold tracking-tight">
                    Autorizaciones frecuentes
                </h1>
                <Form
                    {...AutorizadoFrecuenteController.store.form()}
                    resetOnSuccess
                    className="grid gap-4 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="nombre">Nombre</Label>
                                <Input
                                    id="nombre"
                                    name="nombre"
                                    maxLength={150}
                                    required
                                />
                                <InputError message={errors.nombre} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">
                                    Tipo de proveedor o trabajador
                                </Label>
                                <Input
                                    id="tipo"
                                    name="tipo"
                                    maxLength={40}
                                    required
                                />
                                <InputError message={errors.tipo} />
                            </div>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Días de inicio permitidos
                                </legend>
                                <div className="flex flex-wrap gap-3">
                                    {diasSemana.map((dia, index) => (
                                        <Label key={dia}>
                                            <input
                                                type="checkbox"
                                                name="dias[]"
                                                value={index + 1}
                                            />{' '}
                                            {dia}
                                        </Label>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        errors.dias ??
                                        Object.entries(errors).find(([campo]) =>
                                            campo.startsWith('dias.'),
                                        )?.[1]
                                    }
                                />
                            </fieldset>
                            <fieldset className="grid items-start gap-3 sm:grid-cols-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Horario de Honduras
                                </legend>
                                <div className="grid gap-2">
                                    <Label htmlFor="hora_inicio">Inicio</Label>
                                    <Input
                                        id="hora_inicio"
                                        name="hora_inicio"
                                        type="time"
                                        required
                                    />
                                    <InputError message={errors.hora_inicio} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="hora_fin">Fin</Label>
                                    <Input
                                        id="hora_fin"
                                        name="hora_fin"
                                        type="time"
                                        required
                                    />
                                    <InputError message={errors.hora_fin} />
                                </div>
                            </fieldset>
                            <p className="text-sm text-muted-foreground">
                                Si el fin es anterior al inicio, la autorización
                                termina al día siguiente.
                            </p>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Viviendas de destino
                                </legend>
                                {viviendas.length === 0 && (
                                    <p>
                                        No hay viviendas activas en tu
                                        residencial.
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-3">
                                    {viviendas.map(({ id, codigo }) => (
                                        <Label key={id}>
                                            <input
                                                type="checkbox"
                                                name="viviendas[]"
                                                value={id}
                                            />{' '}
                                            {codigo}
                                        </Label>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        errors.viviendas ??
                                        Object.entries(errors).find(([campo]) =>
                                            campo.startsWith('viviendas.'),
                                        )?.[1]
                                    }
                                />
                            </fieldset>
                            <Button
                                className="justify-self-end"
                                disabled={processing || viviendas.length === 0}
                            >
                                {processing
                                    ? 'Guardando…'
                                    : 'Registrar autorización'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
