import type { AccionCredencial, EstadoCredencial } from '@/types/credenciales';

export type TipoVehiculo = 'carro' | 'moto' | 'otro';

export type Persona = { id: number; nombre: string };

export type Sticker = {
    id: number;
    numero: string;
    estado: EstadoCredencial;
    fecha_emision: string;
    motivo: string | null;
    acciones: AccionCredencial[];
};

export type Comprobante = {
    tipo: string | null;
    descripcion: string | null;
    numero: string;
    vence: string | null;
    tiene_archivo: boolean;
};

export type Vehiculo = {
    id: number;
    vivienda_id: number;
    vivienda: string;
    tipo: TipoVehiculo;
    placa: string | null;
    // Se conserva como historial aunque el vehículo ya tenga placa
    comprobante: Comprobante | null;
    comprobante_vencido: boolean;
    autorizado: boolean;
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

// Cómo se nombra el vehículo: su placa o, si todavía no tiene, su comprobante
export const identificador = (vehiculo: Vehiculo) =>
    vehiculo.placa ?? `Comprobante N.º ${vehiculo.comprobante?.numero ?? ''}`;
