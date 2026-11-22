import { Form, Head, router } from '@inertiajs/react';
import { CircleAlert, Search, X } from 'lucide-react';
import { useRef, useState } from 'react';
import CobroController from '@/actions/Patronato/Presentacion/Controllers/CobroController';
import EstadoMora from '@/components/finanzas/estado-mora';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { EstadoMora as EstadoMoraVivienda } from '@/types/finanzas';

type OpcionVivienda = {
    id: number;
    codigo: string;
    responsable: string | null;
    mora: EstadoMoraVivienda;
};

type CargoPendiente = {
    cargo_id: number;
    periodo: string;
    concepto: string;
    monto: string;
    pendiente: string;
};

const formatoMonto = (monto: number) =>
    monto.toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatoPeriodo = (periodo: string) =>
    new Date(`${periodo}T00:00:00`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
    });

// Suma en centavos para evitar errores de redondeo
const centavos = (monto: string) => Math.round(Number(monto) * 100);

export default function CobroEfectivo({
    cajaAbierta,
    buscar,
    viviendas,
    vivienda,
    pendientes,
    avisoIntegracion,
    permiteSaldoFavor,
}: {
    cajaAbierta: boolean;
    buscar: string;
    viviendas: OpcionVivienda[];
    vivienda: OpcionVivienda | null;
    pendientes: CargoPendiente[] | null;
    avisoIntegracion: string | null;
    permiteSaldoFavor: boolean;
}) {
    const [elegidos, setElegidos] = useState<number[]>([]);
    const [monto, setMonto] = useState('');
    const espera = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    // Sin meses elegidos, el pago se aplica a todos los pendientes
    const sinMeses = elegidos.length === 0;
    const sinPendientes = pendientes !== null && pendientes.length === 0;
    const totalElegido =
        (pendientes ?? [])
            .filter((cargo) => sinMeses || elegidos.includes(cargo.cargo_id))
            .reduce((suma, cargo) => suma + centavos(cargo.pendiente), 0) / 100;

    // Lo que sobra de un pago sin meses queda como saldo a favor
    const excedente =
        permiteSaldoFavor && sinMeses
            ? Math.max(0, centavos(monto) - Math.round(totalElegido * 100)) /
              100
            : 0;

    const alBuscar = (valor: string) => {
        clearTimeout(espera.current);

        espera.current = setTimeout(() => {
            router.get(
                CobroController.create.url(),
                valor ? { buscar: valor } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

    const elegirVivienda = (id: number | null) => {
        setElegidos([]);
        router.get(
            CobroController.create.url(),
            id === null ? {} : { vivienda: id },
            { preserveScroll: true },
        );
    };

    const alternarMes = (cargoId: number, marcado: boolean) =>
        setElegidos((actuales) =>
            marcado
                ? [...actuales, cargoId]
                : actuales.filter((id) => id !== cargoId),
        );

    return (
        <>
            <Head title="Registrar cobro" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Registrar cobro
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Pago en efectivo casa por casa
                    </p>
                </div>

                <p className="text-sm text-muted-foreground">
                    {cajaAbierta
                        ? 'Tu caja de hoy está abierta.'
                        : 'Tu caja de hoy se abrirá con el primer cobro.'}
                </p>

                {vivienda === null ? (
                    <div className="space-y-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                aria-label="Buscar vivienda"
                                placeholder="Código de vivienda o responsable"
                                defaultValue={buscar}
                                onChange={(evento) =>
                                    alBuscar(evento.target.value)
                                }
                                className="h-12 rounded-full pl-11"
                            />
                        </div>

                        {buscar !== '' && viviendas.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No se encontraron viviendas activas.
                            </p>
                        )}

                        <div className="grid gap-2">
                            {viviendas.map((opcion) => (
                                <button
                                    key={opcion.id}
                                    type="button"
                                    onClick={() => elegirVivienda(opcion.id)}
                                    className="rounded-xl border p-4 text-left hover:bg-muted"
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        <span className="font-semibold">
                                            {opcion.codigo}
                                        </span>
                                        <EstadoMora mora={opcion.mora} />
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {opcion.responsable ??
                                            'Sin responsable de pago'}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between rounded-xl border p-4">
                            <div>
                                <p className="font-semibold">
                                    {vivienda.codigo}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {vivienda.responsable ??
                                        'Sin responsable de pago'}
                                </p>
                                <EstadoMora
                                    mora={vivienda.mora}
                                    detalle
                                    className="mt-2"
                                />
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Cambiar vivienda"
                                onClick={() => elegirVivienda(null)}
                            >
                                <X />
                            </Button>
                        </div>

                        {avisoIntegracion !== null ? (
                            <Alert>
                                <CircleAlert />
                                <AlertTitle>
                                    Períodos pendientes no disponibles
                                </AlertTitle>
                                <AlertDescription>
                                    {avisoIntegracion}
                                </AlertDescription>
                            </Alert>
                        ) : sinPendientes && !permiteSaldoFavor ? (
                            <p className="text-muted-foreground">
                                Esta vivienda no tiene meses pendientes.
                            </p>
                        ) : (
                            <Form
                                {...CobroController.store.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setElegidos([])}
                                className="grid gap-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="vivienda_id"
                                            value={vivienda.id}
                                        />
                                        {elegidos.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="cargos[]"
                                                value={id}
                                            />
                                        ))}

                                        {sinPendientes ? (
                                            <p className="text-muted-foreground">
                                                Esta vivienda no tiene meses
                                                pendientes: todo el pago quedará
                                                como saldo a favor.
                                            </p>
                                        ) : (
                                            <fieldset className="grid gap-2">
                                                <legend className="mb-2 text-sm font-medium">
                                                    Meses que se pagan
                                                </legend>
                                                {(pendientes ?? []).map(
                                                    (cargo) => (
                                                        <Label
                                                            key={cargo.cargo_id}
                                                            className="flex items-center gap-3 rounded-xl border p-4 font-normal"
                                                        >
                                                            <Checkbox
                                                                checked={elegidos.includes(
                                                                    cargo.cargo_id,
                                                                )}
                                                                onCheckedChange={(
                                                                    marcado,
                                                                ) =>
                                                                    alternarMes(
                                                                        cargo.cargo_id,
                                                                        marcado ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            <span className="flex-1">
                                                                <span className="block capitalize">
                                                                    {formatoPeriodo(
                                                                        cargo.periodo,
                                                                    )}
                                                                </span>
                                                                <span className="text-sm text-muted-foreground">
                                                                    {
                                                                        cargo.concepto
                                                                    }
                                                                </span>
                                                            </span>
                                                            <span className="font-medium">
                                                                L{' '}
                                                                {formatoMonto(
                                                                    Number(
                                                                        cargo.pendiente,
                                                                    ),
                                                                )}
                                                            </span>
                                                        </Label>
                                                    ),
                                                )}
                                                <InputError
                                                    message={errors.cargos}
                                                />
                                            </fieldset>
                                        )}

                                        <p className="text-sm text-muted-foreground">
                                            {sinMeses
                                                ? 'Pendiente de la vivienda:'
                                                : 'Pendiente de los meses elegidos:'}{' '}
                                            <span className="font-semibold text-foreground">
                                                L {formatoMonto(totalElegido)}
                                            </span>
                                        </p>
                                        {sinMeses && !sinPendientes && (
                                            <p className="text-sm text-muted-foreground">
                                                Sin meses elegidos, el pago se
                                                aplica a los meses pendientes,
                                                del más antiguo al más reciente.
                                            </p>
                                        )}

                                        <div className="grid gap-2">
                                            <Label htmlFor="monto">
                                                Monto recibido
                                            </Label>
                                            <Input
                                                id="monto"
                                                name="monto"
                                                type="number"
                                                inputMode="decimal"
                                                step="0.01"
                                                min="0.01"
                                                className="h-12 text-lg"
                                                onChange={(evento) =>
                                                    setMonto(
                                                        evento.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={errors.monto}
                                            />
                                            {excedente > 0 && (
                                                <p className="text-sm text-muted-foreground">
                                                    El excedente de L{' '}
                                                    {formatoMonto(excedente)}{' '}
                                                    quedará como saldo a favor.
                                                </p>
                                            )}
                                        </div>

                                        <InputError message={errors.caja} />
                                        <InputError
                                            message={errors.vivienda_id}
                                        />

                                        <Button
                                            className="h-12 w-full text-base"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Registrar cobro
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
