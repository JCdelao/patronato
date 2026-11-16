import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import CategoriaEgresoController from '@/actions/Patronato/Presentacion/Controllers/CategoriaEgresoController';
import EgresoController from '@/actions/Patronato/Presentacion/Controllers/EgresoController';
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
import { FORMAS_PAGO } from '@/lib/formato';
import type { OpcionCategoria } from '@/types/finanzas';

export default function EgresoNuevo({
    categorias,
    formas_pago,
    hoy,
}: {
    categorias: OpcionCategoria[];
    formas_pago: string[];
    hoy: string;
}) {
    const [categoria, setCategoria] = useState('');
    const [nuevaCategoria, setNuevaCategoria] = useState(false);

    return (
        <>
            <Head title="Nuevo egreso" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <Link
                    href={EgresoController.index.url()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Egresos
                </Link>

                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Nuevo egreso
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Un egreso registrado no se borra: si hay un error, se
                        anula con su motivo.
                    </p>
                </div>

                <Form
                    {...EgresoController.store.form()}
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
                                    placeholder="Pago del servicio de vigilancia de noviembre"
                                    required
                                />
                                <InputError message={errors.concepto} />
                            </div>

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
                                        onClick={() => setNuevaCategoria(true)}
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

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="proveedor">
                                        Proveedor (opcional)
                                    </Label>
                                    <Input
                                        id="proveedor"
                                        name="proveedor"
                                        maxLength={150}
                                    />
                                    <InputError message={errors.proveedor} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="forma_pago">
                                        Forma de pago (opcional)
                                    </Label>
                                    <Select name="forma_pago">
                                        <SelectTrigger
                                            id="forma_pago"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Sin indicar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {formas_pago.map((forma) => (
                                                <SelectItem
                                                    key={forma}
                                                    value={forma}
                                                >
                                                    {FORMAS_PAGO[forma] ??
                                                        forma}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.forma_pago} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="numero_comprobante">
                                        N.º de comprobante
                                    </Label>
                                    <Input
                                        id="numero_comprobante"
                                        name="numero_comprobante"
                                        maxLength={60}
                                        required
                                    />
                                    <InputError
                                        message={errors.numero_comprobante}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="factura">
                                        Factura (opcional)
                                    </Label>
                                    <Input
                                        id="factura"
                                        name="factura"
                                        type="file"
                                        accept="application/pdf,image/jpeg,image/png"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        PDF, JPG o PNG de hasta 5 MB
                                    </p>
                                    <InputError message={errors.factura} />
                                </div>
                            </div>

                            <Button
                                disabled={processing}
                                className="justify-self-start"
                            >
                                {processing && <Spinner />}
                                Guardar egreso
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            {nuevaCategoria && (
                <NuevaCategoriaDialog
                    accion={CategoriaEgresoController.store.form()}
                    descripcion="Queda disponible para los egresos de este residencial."
                    onClose={() => setNuevaCategoria(false)}
                    onCreada={(id) => setCategoria(String(id))}
                />
            )}
        </>
    );
}
