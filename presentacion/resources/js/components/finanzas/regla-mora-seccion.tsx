import { Form } from '@inertiajs/react';
import MoraController from '@/actions/Patronato/Presentacion/Controllers/MoraController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ReglaMora } from '@/types/finanzas';

// Explica la regla con los valores actuales
const explicacion = ({ dias_gracia, meses_tolerancia }: ReglaMora) => {
    const vence =
        dias_gracia === 0
            ? 'Cada mes vence el día 1'
            : `Cada mes vence el día ${dias_gracia + 1}`;
    const mora =
        meses_tolerancia === 0
            ? 'la vivienda está en mora desde el primer mes vencido sin pagar'
            : `la vivienda está en mora cuando debe más de ${meses_tolerancia === 1 ? '1 mes vencido' : `${meses_tolerancia} meses vencidos`}`;

    return `${vence}, y ${mora}.`;
};

export default function ReglaMoraSeccion({
    mora,
    puedeEditar,
}: {
    mora: ReglaMora;
    puedeEditar: boolean;
}) {
    return (
        <section className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold tracking-tight">
                    Regla de mora
                </h2>
                <p className="mt-1 text-muted-foreground">
                    Cuándo una vivienda deja de estar al día
                </p>
            </div>

            <div className="rounded-2xl border bg-card p-6">
                <p className="text-lg font-medium">{explicacion(mora)}</p>

                {puedeEditar ? (
                    <Form
                        {...MoraController.update.form()}
                        options={{ preserveScroll: true }}
                        className="mt-5 grid gap-5 md:grid-cols-[1fr_1fr_auto] md:items-start"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="dias_gracia">
                                        Días de gracia
                                    </Label>
                                    <Input
                                        id="dias_gracia"
                                        name="dias_gracia"
                                        type="number"
                                        min="0"
                                        max="28"
                                        defaultValue={mora.dias_gracia}
                                        className="w-28"
                                        required
                                    />
                                    <InputError message={errors.dias_gracia} />
                                    <p className="text-sm text-muted-foreground">
                                        Días después del 1 para pagar el mes, de
                                        0 a 28.
                                    </p>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="meses_tolerancia">
                                        Meses de tolerancia
                                    </Label>
                                    <Input
                                        id="meses_tolerancia"
                                        name="meses_tolerancia"
                                        type="number"
                                        min="0"
                                        max="12"
                                        defaultValue={mora.meses_tolerancia}
                                        className="w-28"
                                        required
                                    />
                                    <InputError
                                        message={errors.meses_tolerancia}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Meses vencidos que se permiten antes de
                                        la mora, de 0 a 12.
                                    </p>
                                </div>

                                <Button
                                    disabled={processing}
                                    className="md:mt-7"
                                >
                                    {processing && <Spinner />}
                                    Guardar
                                </Button>
                            </>
                        )}
                    </Form>
                ) : (
                    <p className="mt-2 text-muted-foreground">
                        {mora.dias_gracia} días de gracia ·{' '}
                        {mora.meses_tolerancia} meses de tolerancia
                    </p>
                )}
            </div>
        </section>
    );
}
