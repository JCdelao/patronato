import { Form, Head } from '@inertiajs/react';
import { CircleCheck, DoorOpen } from 'lucide-react';
import { useState } from 'react';
import VisitaController from '@/actions/Patronato/Presentacion/Controllers/VisitaController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const claseSelect =
    'h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const campos = [
    {
        nombre: 'nombre_visitante',
        etiqueta: 'Nombre del visitante',
        limite: 150,
    },
    { nombre: 'identidad', etiqueta: 'Identidad', limite: 30 },
    { nombre: 'motivo', etiqueta: 'Motivo de la visita', limite: 255 },
];

export default function EntradaVisitas({
    guardia,
    viviendas,
    medios,
    identificador,
}: {
    guardia: string;
    viviendas: { id: number; codigo: string }[];
    medios: { id: number; nombre: string; requiere_placa: boolean }[];
    identificador: string | null;
}) {
    const [medioId, setMedioId] = useState('');
    const requierePlaca = medios.some(
        (medio) => String(medio.id) === medioId && medio.requiere_placa,
    );

    return (
        <>
            <Head title="Entrada de visitas" />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Entrada de visitas
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Guardia: {guardia}
                    </p>
                </div>

                {identificador && (
                    <Alert role="status">
                        <CircleCheck />
                        <AlertTitle>Entrada registrada</AlertTitle>
                        <AlertDescription className="break-all">
                            Identificador: {identificador}
                        </AlertDescription>
                    </Alert>
                )}

                {medios.length === 0 && (
                    <Alert>
                        <AlertDescription>
                            No hay medios de entrada activos. Solicita que
                            habiliten uno en el catálogo del residencial.
                        </AlertDescription>
                    </Alert>
                )}
                {viviendas.length === 0 && (
                    <Alert>
                        <AlertDescription>
                            No hay viviendas registradas en tu residencial.
                        </AlertDescription>
                    </Alert>
                )}

                <Form
                    {...VisitaController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => setMedioId('')}
                    className="grid gap-4 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError
                                message={errors.guardia ?? errors.turno}
                            />
                            {campos.map(({ nombre, etiqueta, limite }) => (
                                <div key={nombre} className="grid gap-2">
                                    <Label htmlFor={nombre}>{etiqueta}</Label>
                                    <Input
                                        id={nombre}
                                        name={nombre}
                                        maxLength={limite}
                                        required
                                    />
                                    <InputError message={errors[nombre]} />
                                </div>
                            ))}
                            <div className="grid gap-2">
                                <Label htmlFor="vivienda_id">
                                    Vivienda de destino
                                </Label>
                                <select
                                    className={claseSelect}
                                    id="vivienda_id"
                                    name="vivienda_id"
                                    defaultValue=""
                                    required
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
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="medio_entrada_id">
                                        Medio de entrada
                                    </Label>
                                    <select
                                        className={claseSelect}
                                        id="medio_entrada_id"
                                        name="medio_entrada_id"
                                        value={medioId}
                                        onChange={(event) =>
                                            setMedioId(event.target.value)
                                        }
                                        required
                                    >
                                        <option value="" disabled>
                                            Elige el medio de entrada
                                        </option>
                                        {medios.map(({ id, nombre }) => (
                                            <option key={id} value={id}>
                                                {nombre}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.medio_entrada_id}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="placa">
                                        {requierePlaca
                                            ? 'Placa (obligatoria)'
                                            : 'Placa (opcional)'}
                                    </Label>
                                    <Input
                                        id="placa"
                                        name="placa"
                                        maxLength={20}
                                        required={requierePlaca}
                                    />
                                    <InputError message={errors.placa} />
                                </div>
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    disabled={
                                        processing ||
                                        medios.length === 0 ||
                                        viviendas.length === 0
                                    }
                                >
                                    {processing ? <Spinner /> : <DoorOpen />}
                                    Registrar entrada
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
