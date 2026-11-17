export type TipoVehiculo = 'carro' | 'moto' | 'otro';

export type Persona = { id: number; nombre: string };

export type EstadoSticker =
    | 'activo'
    | 'suspendido'
    | 'vencido'
    | 'reemplazado'
    | 'dado_de_baja';

export type AccionSticker =
    | 'suspender'
    | 'reactivar'
    | 'vencer'
    | 'reemplazar'
    | 'dar_de_baja';

export type Sticker = {
    id: number;
    numero: string;
    estado: EstadoSticker;
    fecha_emision: string;
    motivo: string | null;
    acciones: AccionSticker[];
};

export const ESTADOS_STICKER: Record<EstadoSticker, string> = {
    activo: 'Activo',
    suspendido: 'Suspendido',
    vencido: 'Vencido',
    reemplazado: 'Reemplazado',
    dado_de_baja: 'Dado de baja',
};

// Estados en los que el sticker todavía pertenece al vehículo
export const STICKER_VIGENTE: EstadoSticker[] = [
    'activo',
    'suspendido',
    'vencido',
];

export type Vehiculo = {
    id: number;
    vivienda_id: number;
    vivienda: string;
    tipo: TipoVehiculo;
    placa: string;
    marca: string | null;
    modelo: string | null;
    color: string | null;
    activo: boolean;
    stickers: Sticker[];
    conductores: Persona[];
};

export type OpcionVivienda = { id: number; codigo: string };

export const TIPOS_VEHICULO: Record<TipoVehiculo, string> = {
    carro: 'Carro',
    moto: 'Moto',
    otro: 'Otro',
};
