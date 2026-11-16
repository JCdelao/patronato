import { Head } from '@inertiajs/react';
import ParametroCobroController from '@/actions/Patronato/Presentacion/Controllers/ParametroCobroController';
import ConceptosSeccion from '@/components/finanzas/conceptos-seccion';
import GeneracionCargosSeccion from '@/components/finanzas/generacion-cargos-seccion';
import ReglaMoraSeccion from '@/components/finanzas/regla-mora-seccion';
import TarifasEspecialesSeccion from '@/components/finanzas/tarifas-especiales-seccion';
import TiposViviendaSeccion from '@/components/finanzas/tipos-vivienda-seccion';
import { Separator } from '@/components/ui/separator';
import type {
    Concepto,
    GeneracionCargos,
    ReglaMora,
    TarifaEspecial,
    TipoViviendaCuota,
} from '@/types/finanzas';
import type { OpcionVivienda } from '@/types/padron';

export default function ParametrosCobro({
    generacion,
    tipos,
    especiales,
    viviendas,
    conceptos,
    mora,
    periodoActual,
    puedeEditar,
    puedeEditarMora,
}: {
    generacion: GeneracionCargos;
    tipos: TipoViviendaCuota[];
    especiales: TarifaEspecial[];
    viviendas: OpcionVivienda[];
    conceptos: Concepto[];
    mora: ReglaMora;
    periodoActual: string;
    puedeEditar: boolean;
    puedeEditarMora: boolean;
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

                <GeneracionCargosSeccion
                    generacion={generacion}
                    periodoActual={periodoActual}
                    puedeEditar={puedeEditar}
                />

                <Separator />

                <ReglaMoraSeccion mora={mora} puedeEditar={puedeEditarMora} />

                <Separator />

                <TiposViviendaSeccion
                    tipos={tipos}
                    periodoActual={periodoActual}
                    puedeEditar={puedeEditar}
                />

                <Separator />

                <TarifasEspecialesSeccion
                    especiales={especiales}
                    viviendas={viviendas}
                    periodoActual={periodoActual}
                    puedeEditar={puedeEditar}
                />

                <Separator />

                <ConceptosSeccion
                    conceptos={conceptos.filter(
                        (concepto) => !concepto.es_cuota_unificada,
                    )}
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
