export type TipoResidente = 'propietario' | 'inquilino' | 'familiar';

export type Residente = {
    id: number;
    vivienda_id: number;
    tipo: TipoResidente;
    es_responsable_pago: boolean;
    nombres: string;
    apellidos: string;
    identidad: string | null;
    telefono: string | null;
    correo: string | null;
    otras_viviendas: string[];
};

export type Vivienda = {
    id: number;
    codigo: string;
    tipo_vivienda_id: number;
    tipo: string;
    vivienda_padre_id: number | null;
    pertenece_a: string | null;
    cuota_especial: string | null;
    edificio_torre: string | null;
    calle_bloque: string | null;
    numero: string | null;
    estado_ocupacion: 'ocupada' | 'desocupada';
    residentes: Residente[];
};

export type OpcionVivienda = {
    id: number;
    codigo: string;
    vivienda_padre_id: number | null;
};

export type OpcionTipoVivienda = {
    id: number;
    nombre: string;
};

export type ResidenteInactivo = {
    id: number;
    nombres: string;
    apellidos: string;
    identidad: string | null;
    telefono: string | null;
    correo: string | null;
    historial: {
        vivienda: string;
        tipo: TipoResidente;
        desde: string | null;
        hasta: string | null;
    }[];
};
