import type { EstadoCredencial } from '@/types/credenciales';
import type { TipoVehiculo } from '@/types/vehiculos';

// Lo que el residente ve de su vivienda en el portal (HU-53); es de solo lectura

export type ComprobantePortal = {
    tipo: string | null;
    descripcion: string | null;
    numero: string | null;
    vence: string | null;
    tiene_archivo: boolean;
};

export type VehiculoPortal = {
    id: number;
    tipo: TipoVehiculo;
    placa: string | null;
    // Solo viene mientras el vehículo no tiene placa
    comprobante: ComprobantePortal | null;
    comprobante_vencido: boolean;
    marca: string | null;
    modelo: string | null;
    color: string | null;
    sticker: {
        numero: string;
        estado: EstadoCredencial;
        fecha_emision: string;
    } | null;
    conductores: string[];
};

export type ResidentePortal = {
    id: number;
    nombre: string;
    carnet: {
        numero: string;
        estado: EstadoCredencial;
        fecha_emision: string;
        vigente_hasta: string | null;
    } | null;
};

export type ViviendaPortal = {
    id: number;
    codigo: string;
    activa: boolean;
    vehiculos: VehiculoPortal[];
    residentes: ResidentePortal[];
};
