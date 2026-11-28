// Solicitudes de corrección del portal y de la bandeja de la Junta Directiva (HU-54)

export type EstadoSolicitud =
    | 'recibida'
    | 'en_revision'
    | 'resuelta'
    | 'rechazada';

export const ESTADOS_SOLICITUD: Record<EstadoSolicitud, string> = {
    recibida: 'Recibida',
    en_revision: 'En revisión',
    resuelta: 'Resuelta',
    rechazada: 'Rechazada',
};

// Tipo de solicitud → nombre visible; la lista sale del servidor
export type TiposSolicitud = Record<string, string>;

export type SolicitudPortal = {
    id: number;
    tipo: string;
    vivienda: string | null;
    descripcion: string;
    estado: EstadoSolicitud;
    respuesta: string | null;
    creada_en: string;
    actualizada_en: string;
    tiene_adjunto: boolean;
};

export type SolicitudBandeja = SolicitudPortal & {
    residente: string;
    atendida_por: string | null;
    puede_tomar: boolean;
    puede_cerrar: boolean;
};
