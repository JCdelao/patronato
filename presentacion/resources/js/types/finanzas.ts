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

export type EstadoEgreso = 'vigente' | 'anulado';

export type FilaEgreso = {
    id: number;
    fecha: string;
    concepto: string;
    categoria: string;
    proveedor: string | null;
    numero_comprobante: string;
    monto: string;
    estado: EstadoEgreso;
};

export type DetalleEgreso = FilaEgreso & {
    forma_pago: string | null;
    tiene_factura: boolean;
    registrado_por: string | null;
    registrado_en: string;
    anulacion: {
        motivo: string | null;
        anulado_por: string | null;
        anulado_en: string | null;
    } | null;
    puede_anular: boolean;
};

export type CategoriaEgreso = {
    id: number;
    nombre: string;
};
