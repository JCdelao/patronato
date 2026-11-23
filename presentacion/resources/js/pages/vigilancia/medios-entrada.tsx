import { Head, useForm } from '@inertiajs/react';
import type { SubmitEvent } from 'react';
import MedioEntradaController from '@/actions/Patronato/Presentacion/Controllers/MedioEntradaController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Medio = {
    id: number;
    nombre: string;
    requiere_placa: boolean;
    activo: boolean;
};

function FormularioMedio({ medio }: { medio?: Medio }) {
    const id = medio?.id ?? 'nuevo';
    const form = useForm({
        nombre: medio?.nombre ?? '',
        requiere_placa: medio?.requiere_placa ?? false,
        activo: medio?.activo ?? true,
    });
    const motorizado = ['moto', 'carro'].includes(
        form.data.nombre.trim().toLowerCase(),
    );

    function guardar(event: SubmitEvent<HTMLFormElement>) {
        event.preventDefault();

        if (medio) {
            form.put(MedioEntradaController.update.url(medio.id), {
                preserveScroll: true,
            });
        } else {
            form.post(MedioEntradaController.store.url(), {
                preserveScroll: true,
                onSuccess: () => form.reset(),
            });
        }
    }

    return (
        <form onSubmit={guardar} className="grid gap-4 rounded-xl border p-4">
            <div className="grid gap-2">
                <Label htmlFor={`nombre-${id}`}>Nombre</Label>
                <Input
                    id={`nombre-${id}`}
                    value={form.data.nombre}
                    onChange={(event) =>
                        form.setData('nombre', event.target.value)
                    }
                    maxLength={40}
                    required
                />
                <InputError message={form.errors.nombre} />
            </div>
            <div className="flex flex-wrap gap-4">
                <Label>
                    <input
                        type="checkbox"
                        checked={motorizado || form.data.requiere_placa}
                        disabled={motorizado}
                        onChange={(event) =>
                            form.setData('requiere_placa', event.target.checked)
                        }
                    />
                    Requiere placa
                </Label>
                <Label>
                    <input
                        type="checkbox"
                        checked={form.data.activo}
                        onChange={(event) =>
                            form.setData('activo', event.target.checked)
                        }
                    />
                    Activo
                </Label>
            </div>
            <InputError
                message={form.errors.requiere_placa ?? form.errors.activo}
            />
            <div className="flex justify-end">
                <Button
                    variant={medio ? 'outline' : 'default'}
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    {medio ? 'Guardar cambios' : 'Agregar medio'}
                </Button>
            </div>
        </form>
    );
}

export default function MediosEntrada({ medios }: { medios: Medio[] }) {
    return (
        <>
            <Head title="Medios de entrada" />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-10">
                <h1 className="text-3xl font-bold tracking-tight">
                    Medios de entrada
                </h1>
                <FormularioMedio />
                {medios.map((medio) => (
                    <FormularioMedio
                        key={`${medio.id}-${medio.nombre}-${medio.requiere_placa}-${medio.activo}`}
                        medio={medio}
                    />
                ))}
            </div>
        </>
    );
}
