import { Head } from '@inertiajs/react';
import ParametroCobroController from '@/actions/Patronato/Presentacion/Controllers/ParametroCobroController';
import ConceptosSeccion from '@/components/finanzas/conceptos-seccion';
import type { Concepto } from '@/types/finanzas';

export default function ParametrosCobro({
    conceptos,
    periodoActual,
    puedeEditar,
}: {
    conceptos: Concepto[];
    periodoActual: string;
    puedeEditar: boolean;
}) {
    return (
        <>
            <Head title="Parámetros de cobro" />

            <div className="space-y-10 p-6 md:p-10">
                <div>
                    <h1 className="text-4xl font-bold tracking-tight">
                        Parámetros de cobro
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Qué se cobra a las viviendas y cuánto
                    </p>
                </div>

                <ConceptosSeccion
                    conceptos={conceptos}
                    periodoActual={periodoActual}
                    puedeEditar={puedeEditar}
                />
            </div>
        </>
    );
}

ParametrosCobro.layout = {
    breadcrumbs: [
        {
            title: 'Parámetros de cobro',
            href: ParametroCobroController.index(),
        },
    ],
};
