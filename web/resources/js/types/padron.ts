export type Residente = {
    id: number;
    vivienda_id: number;
    tipo: 'propietario' | 'inquilino' | 'familiar';
    es_responsable_pago: boolean;
    nombres: string;
    apellidos: string;
    identidad: string | null;
    telefono: string | null;
    correo: string | null;
};

export type Vivienda = {
    id: number;
    codigo: string;
    tipo: 'casa' | 'apartamento';
    direccion: string | null;
    cuota: string | null;
    residentes: Residente[];
};

export type OpcionVivienda = {
    id: number;
    codigo: string;
};
