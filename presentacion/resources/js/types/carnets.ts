import type { AccionCredencial, EstadoCredencial } from '@/types/credenciales';

export type Carnet = {
    id: number;
    numero: string;
    estado: EstadoCredencial;
    fecha_emision: string;
    vigente_hasta: string | null;
    motivo: string | null;
    acciones: AccionCredencial[];
};

export type ResidenteCarnets = {
    id: number;
    nombre: string;
    viviendas: string[];
    carnets: Carnet[];
};
