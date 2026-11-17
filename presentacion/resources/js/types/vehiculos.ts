export type TipoVehiculo = 'carro' | 'moto' | 'otro';

export type Persona = { id: number; nombre: string };

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
    conductores: Persona[];
};

export type OpcionVivienda = { id: number; codigo: string };

export const TIPOS_VEHICULO: Record<TipoVehiculo, string> = {
    carro: 'Carro',
    moto: 'Moto',
    otro: 'Otro',
};
