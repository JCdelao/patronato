import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import TipoIncidenciaController from '@/actions/Patronato/Presentacion/Controllers/TipoIncidenciaController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { TipoIncidencia } from '@/types/vigilancia';

// Catálogo de tipos de incidencia: agregar, renombrar y activar o desactivar
export default function TiposIncidenciaDialog({
    tipos,
    onClose,
}: {
    tipos: TipoIncidencia[];
    onClose: () => void;
}) {
    const [editando, setEditando] = useState<number | null>(null);
    // Cambia la clave para limpiar el campo después de agregar
    const [nuevo, setNuevo] = useState(0);

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-lg">
                <DialogTitle className="text-2xl font-bold tracking-tight">
                    Tipos de incidencia
                </DialogTitle>
                <DialogDescription>
                    Los guardias eligen uno al registrar una incidencia. Un tipo
                    desactivado ya no se elige, pero las incidencias anteriores
                    lo conservan.
                </DialogDescription>

                <Form
                    key={nuevo}
                    {...TipoIncidenciaController.store.form()}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => setNuevo((valor) => valor + 1)}
                    className="grid gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex gap-2">
                                <Input
                                    name="nombre"
                                    maxLength={80}
                                    placeholder="Nuevo tipo"
                                    aria-label="Nombre del nuevo tipo"
                                    required
                                />
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Agregar
                                </Button>
                            </div>
                            <InputError message={errors.nombre} />
                        </>
                    )}
                </Form>

                <ul className="divide-y rounded-xl border">
                    {tipos.map((tipo) => (
                        <li key={tipo.id} className="p-3">
                            {editando === tipo.id ? (
                                <Form
                                    {...TipoIncidenciaController.update.form(
                                        tipo.id,
                                    )}
                                    options={{
                                        preserveScroll: true,
                                        preserveState: true,
                                    }}
                                    onSuccess={() => setEditando(null)}
                                    className="grid gap-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="flex gap-2">
                                                <Input
                                                    name="nombre"
                                                    defaultValue={tipo.nombre}
                                                    maxLength={80}
                                                    aria-label={`Nuevo nombre de ${tipo.nombre}`}
                                                    required
                                                    autoFocus
                                                />
                                                <Button disabled={processing}>
                                                    Guardar
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditando(null)
                                                    }
                                                >
                                                    Cancelar
                                                </Button>
                                            </div>
                                            <InputError
                                                message={errors.nombre}
                                            />
                                        </>
                                    )}
                                </Form>
                            ) : (
                                <div className="flex items-center justify-between gap-2">
                                    <span
                                        className={
                                            tipo.activo
                                                ? ''
                                                : 'text-muted-foreground line-through'
                                        }
                                    >
                                        {tipo.nombre}
                                    </span>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Renombrar ${tipo.nombre}`}
                                            onClick={() => setEditando(tipo.id)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Form
                                            {...(tipo.activo
                                                ? TipoIncidenciaController.desactivar.form(
                                                      tipo.id,
                                                  )
                                                : TipoIncidenciaController.activar.form(
                                                      tipo.id,
                                                  ))}
                                            options={{
                                                preserveScroll: true,
                                                preserveState: true,
                                            }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {tipo.activo
                                                        ? 'Desactivar'
                                                        : 'Activar'}
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
