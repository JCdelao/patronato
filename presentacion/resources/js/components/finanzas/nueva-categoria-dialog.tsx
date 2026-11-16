import { Form } from '@inertiajs/react';
import { useState } from 'react';
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
import type { RouteFormDefinition } from '@/wayfinder';

type Categoria = { id: number; nombre: string };

// Agrega una categoría propia y devuelve su id, buscándola en la lista actualizada
export default function NuevaCategoriaDialog({
    accion,
    descripcion,
    onClose,
    onCreada,
}: {
    accion: RouteFormDefinition<'post'>;
    descripcion: string;
    onClose: () => void;
    onCreada: (id: number) => void;
}) {
    const [nombre, setNombre] = useState('');

    const alGuardar = (categorias: Categoria[]) => {
        const creada = categorias.find(
            (item) =>
                item.nombre.toLocaleLowerCase() ===
                nombre.trim().toLocaleLowerCase(),
        );

        if (creada) {
            onCreada(creada.id);
        }

        onClose();
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-md">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Nueva categoría
                </DialogTitle>
                <DialogDescription>{descripcion}</DialogDescription>
                <Form
                    {...accion}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={(pagina) =>
                        alGuardar(pagina.props.categorias as Categoria[])
                    }
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="nombre_categoria">Nombre</Label>
                                <Input
                                    id="nombre_categoria"
                                    name="nombre"
                                    value={nombre}
                                    onChange={(evento) =>
                                        setNombre(evento.target.value)
                                    }
                                    maxLength={100}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.nombre} />
                            </div>
                            <Button className="w-full" disabled={processing}>
                                {processing && <Spinner />}
                                Agregar
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
