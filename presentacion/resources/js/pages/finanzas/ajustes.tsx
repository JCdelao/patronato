import { Form, Head, Link, router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useRef } from 'react';
import AjusteController from '@/actions/Patronato/Presentacion/Controllers/AjusteController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatoFecha, formatoMonto } from '@/lib/formato';

type OpcionVivienda = {
    id: number;
    codigo: string;
    responsable: string | null;
    activa: boolean;
};

type AjusteRegistrado = {
    id: number;
    fecha: string;
    monto: string;
    tipo: string;
    motivo: string;
    autorizado_por: string | null;
};

type Detalle = {
    vivienda: OpcionVivienda;
    saldo: string;
    ajustes: AjusteRegistrado[];
};

const claseTexto =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function Ajustes({
    buscar,
    viviendas,
    detalle,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
    detalle: Detalle | null;
}) {
    return (
        <>
            <Head title="Ajustes de saldo" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Ajustes de saldo
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Corrige el saldo general de una vivienda dejando el
                        motivo. No cambia sus cuotas, pagos ni mora.
                    </p>
                </div>

                {detalle === null ? (
                    <Buscador buscar={buscar} viviendas={viviendas} />
                ) : (
                    <Correccion detalle={detalle} />
                )}
            </div>
        </>
    );
}

function Buscador({
    buscar,
    viviendas,
}: {
    buscar: string;
    viviendas: OpcionVivienda[];
}) {
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                AjusteController.index.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    return (
        <div className="space-y-3">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    aria-label="Buscar vivienda"
                    placeholder="Código de vivienda o responsable"
                    defaultValue={buscar}
                    onChange={(evento) => alBuscar(evento.target.value)}
                    className="h-12 rounded-full pl-11"
                />
            </div>

            {buscar !== '' && viviendas.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No se encontraron viviendas.
                </p>
            )}

            <div className="grid gap-2">
                {viviendas.map((opcion) => (
                    <Link
                        key={opcion.id}
                        href={AjusteController.index.url({
                            query: { vivienda: opcion.id },
                        })}
                        className="rounded-xl border p-4 hover:bg-muted"
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span className="font-semibold">
                                {opcion.codigo}
                            </span>
                            {!opcion.activa && (
                                <Badge variant="outline">Inactiva</Badge>
                            )}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {opcion.responsable ?? 'Sin responsable de pago'}
                        </span>
                    </Link>
                ))}
            </div>
        </div>
    );
}

function Correccion({ detalle }: { detalle: Detalle }) {
    const { vivienda, saldo, ajustes } = detalle;

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between rounded-xl border p-4">
                <div>
                    <p className="flex items-center gap-2 font-semibold">
                        {vivienda.codigo}
                        {!vivienda.activa && (
                            <Badge variant="outline">Inactiva</Badge>
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {vivienda.responsable ?? 'Sin responsable de pago'}
                    </p>
                    <p className="mt-2 text-sm">
                        Saldo actual:{' '}
                        <span className="font-semibold">
                            L {formatoMonto(saldo)}
                        </span>
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Cambiar vivienda"
                    asChild
                >
                    <Link href={AjusteController.index.url()}>
                        <X />
                    </Link>
                </Button>
            </div>

            <Form
                {...AjusteController.store.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-4 rounded-xl border p-4"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="vivienda_id"
                            value={vivienda.id}
                        />

                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                La corrección
                            </legend>
                            <Label className="flex items-center gap-3 rounded-xl border p-3 font-normal">
                                <input
                                    type="radio"
                                    name="sentido"
                                    value="aumenta"
                                />
                                Aumenta el saldo (la vivienda debe más)
                            </Label>
                            <Label className="flex items-center gap-3 rounded-xl border p-3 font-normal">
                                <input
                                    type="radio"
                                    name="sentido"
                                    value="disminuye"
                                />
                                Disminuye el saldo (la vivienda debe menos)
                            </Label>
                            <InputError message={errors.sentido} />
                        </fieldset>

                        <div className="grid gap-2">
                            <Label htmlFor="monto">Monto</Label>
                            <Input
                                id="monto"
                                name="monto"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0.01"
                                className="h-12 text-lg"
                            />
                            <InputError message={errors.monto} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="motivo">Motivo</Label>
                            <textarea
                                id="motivo"
                                name="motivo"
                                rows={3}
                                minLength={10}
                                maxLength={1000}
                                className={claseTexto}
                            />
                            <InputError message={errors.motivo} />
                        </div>

                        <p className="text-sm text-muted-foreground">
                            Se registra con la fecha de hoy y con tu usuario
                            como quien la autoriza.
                        </p>

                        <InputError message={errors.vivienda_id} />

                        <Button
                            className="h-12 w-full text-base"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Registrar corrección
                        </Button>
                    </>
                )}
            </Form>

            <div className="space-y-2">
                <h2 className="text-lg font-semibold">
                    Ajustes de la vivienda
                </h2>
                {ajustes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Esta vivienda no tiene ajustes.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {ajustes.map((ajuste) => (
                            <li key={ajuste.id} className="space-y-1 p-4">
                                <p className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {ajuste.tipo} ·{' '}
                                        {formatoFecha(ajuste.fecha)}
                                    </span>
                                    <span className="font-semibold">
                                        {Number(ajuste.monto) > 0 ? '+' : '−'}L{' '}
                                        {formatoMonto(
                                            Math.abs(Number(ajuste.monto)),
                                        )}
                                    </span>
                                </p>
                                <p className="text-sm">{ajuste.motivo}</p>
                                <p className="text-sm text-muted-foreground">
                                    Autorizó{' '}
                                    {ajuste.autorizado_por ??
                                        'migración de datos'}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
