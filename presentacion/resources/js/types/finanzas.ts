export type Vigencia = {
    monto: string;
    desde: string;
    motivo: string | null;
};

export type GeneracionCargos = {
    dia: number;
    ultima: {
        fecha: string;
        cargos: number;
        automatica: boolean;
        usuario: string | null;
    } | null;
};

// Concepto que se cobra solo a algunas viviendas, para elegirlo en cada vivienda
export type OpcionConcepto = {
    id: number;
    nombre: string;
    monto: string | null;
};

export type ReglaMora = {
    dias_gracia: number;
    meses_tolerancia: number;
};

export type EstadoMora = {
    estado: 'al_dia' | 'en_mora';
    meses_vencidos: number;
    vencido: string;
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
    alcance: 'todas' | 'algunas';
    viviendas_elegidas: number;
    activo: boolean;
    es_cuota_unificada: boolean;
    tiene_cargos: boolean;
    actual: Vigencia | null;
    proximos: Vigencia[];
};
