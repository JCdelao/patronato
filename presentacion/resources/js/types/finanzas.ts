export type Vigencia = {
    monto: string;
    desde: string;
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
