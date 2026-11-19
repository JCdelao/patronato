// Estados y acciones comunes a stickers y carnets (patrón State en negocio/Credenciales)

export type EstadoCredencial =
    | 'activo'
    | 'suspendido'
    | 'vencido'
    | 'reemplazado'
    | 'dado_de_baja';

export type AccionCredencial =
    | 'suspender'
    | 'reactivar'
    | 'vencer'
    | 'reemplazar'
    | 'dar_de_baja';

export const ESTADOS_CREDENCIAL: Record<EstadoCredencial, string> = {
    activo: 'Activo',
    suspendido: 'Suspendido',
    vencido: 'Vencido',
    reemplazado: 'Reemplazado',
    dado_de_baja: 'Dado de baja',
};

// Estados en los que la credencial todavía pertenece a su dueño
export const CREDENCIAL_VIGENTE: EstadoCredencial[] = [
    'activo',
    'suspendido',
    'vencido',
];
