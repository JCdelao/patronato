import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import CategoriaIngresoController from '@/actions/Patronato/Presentacion/Controllers/CategoriaIngresoController';
import IngresoController from '@/actions/Patronato/Presentacion/Controllers/IngresoController';
import NuevaCategoriaDialog from '@/components/finanzas/nueva-categoria-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { OpcionCategoria } from '@/types/finanzas';

export default function IngresoNuevo({
    categorias,
    hoy,
}: {
    categorias: OpcionCategoria[];
    hoy: string;
}) {
    const [categoria, setCategoria] = useState('');
    const [nuevaCategoria, setNuevaCategoria] = useState(false);

    return (
        <>
            <Head title="Otro ingreso" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={IngresoController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Ingresos
                </Link>

                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Otro ingreso
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Para dinero que no viene de las cuotas, como un alquiler
                        o una donación. Las cuotas se registran solas al cobrar.
                    </p>
                </div>

                <Form
                    {...IngresoController.store.form()}
                    className="grid gap-4 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="fecha">Fecha</Label>
                                    <Input
                                        id="fecha"
                                        name="fecha"
                                        type="date"
                                        max={hoy}
                                        defaultValue={hoy}
                                        required
                                    />
                                    <InputError message={errors.fecha} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="monto">Monto (L.)</Label>
                                    <Input
                                        id="monto"
                                        name="monto"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        inputMode="decimal"
                                        required
                                    />
                                    <InputError message={errors.monto} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="concepto">Concepto</Label>
                                <Input
                                    id="concepto"
                                    name="concepto"
                                    maxLength={255}
                                    placeholder="Alquiler del salón comunal"
                                    required
                                />
                                <InputError message={errors.concepto} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <Label htmlFor="categoria_id">
                                            Categoría
                                        </Label>
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="sm"
                                            className="h-auto p-0"
                                            onClick={() =>
                                                setNuevaCategoria(true)
                                            }
                                        >
                                            <Plus />
                                            Agregar categoría
                                        </Button>
                                    </div>
                                    <Select
                                        name="categoria_id"
                                        value={categoria}
                                        onValueChange={setCategoria}
                                    >
                                        <SelectTrigger
                                            id="categoria_id"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Elige la categoría" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categorias.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    {item.nombre}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.categoria_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="numero_comprobante">
                                        N.º de comprobante (opcional)
                                    </Label>
                                    <Input
                                        id="numero_comprobante"
                                        name="numero_comprobante"
                                        maxLength={60}
                                    />
                                    <InputError
                                        message={errors.numero_comprobante}
                                    />
                                </div>
                            </div>

                            <Button
                                disabled={processing}
                                className="justify-self-start"
                            >
                                {processing && <Spinner />}
                                Guardar ingreso
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            {nuevaCategoria && (
                <NuevaCategoriaDialog
                    accion={CategoriaIngresoController.store.form()}
                    descripcion="Queda disponible para los ingresos de este residencial."
                    onClose={() => setNuevaCategoria(false)}
                    onCreada={(id) => setCategoria(String(id))}
                />
            )}
        </>
    );
}
