export type Cuenta = {
    id: number;
    name: string;
    email: string;
    rol_id: number | null;
    rol: string | null;
    activo: boolean;
    residente_id: number | null;
    vivienda: string | null;
    editable: boolean;
};

export type RolOpcion = {
    id: number;
    codigo: string;
    nombre: string;
};

export type ResidenteOpcion = {
    id: number;
    nombre: string;
    vivienda: string | null;
    user_id: number | null;
};

export type ParametrosAcceso = {
    max_intentos_login: number;
    minutos_bloqueo_login: number;
    minutos_inactividad_sesion: number;
};
