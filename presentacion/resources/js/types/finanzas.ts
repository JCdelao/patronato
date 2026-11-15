export type Vigencia = {
    monto: string;
    desde: string;
    motivo: string | null;
};

export type TarifaEspecial = {
    vivienda_id: number;
    codigo: string;
    actual: Vigencia | null;
    proximos: Vigencia[];
};

export type TipoViviendaCuota = {
    id: number;
    nombre: string;
    activo: boolean;
    tiene_viviendas: boolean;
    actual: Vigencia | null;
    proximos: Vigencia[];
};

export type Concepto = {
    id: number;
    nombre: string;
    tipo: 'recurrente' | 'unico';
    activo: boolean;
    es_cuota_unificada: boolean;
    tiene_cargos: boolean;
    actual: Vigencia | null;
    proximos: Vigencia[];
};
