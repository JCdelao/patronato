export type Turno = 'dia' | 'tarde' | 'noche' | '24_horas' | 'rotativo';

export type Guardia = {
    id: number;
    nombre: string;
    identidad: string;
    telefono: string | null;
    turno: Turno;
    activo: boolean;
    cuenta: { email: string; activa: boolean } | null;
};

export const TURNOS: Record<Turno, string> = {
    dia: 'Día',
    tarde: 'Tarde',
    noche: 'Noche',
    '24_horas': '24 horas',
    rotativo: 'Rotativo',
};

export type TipoTurno = Exclude<Turno, 'rotativo'>;

export type Sugerido = { hora: string; horas: number };

export type TurnoProgramado = {
    id: number;
    guardia_id: number;
    guardia: string;
    tipo: TipoTurno;
    // Fecha y hora de Honduras: AAAA-MM-DD HH:MM
    inicio: string;
    fin: string;
    empezo: boolean;
    // Cuando el guardia lo inició de verdad
    inicio_real: string | null;
    entrega: {
        // Null cuando terminó sin que otro guardia lo relevara
        recibe: string | null;
        entregado_en: string;
        recibido_en: string | null;
        novedades: string | null;
    } | null;
};

// Turno que otro guardia entregó y falta confirmar
export type EntregaPendiente = {
    id: number;
    entrega: string;
    entregado_en: string;
    novedades: string | null;
    tipo: TipoTurno;
    inicio: string;
    fin: string;
};

// Estado del turno para mostrarlo en el calendario y en la lista del guardia
export function estadoTurno(turno: TurnoProgramado): string | null {
    if (turno.entrega) {
        const hora = turno.entrega.entregado_en.slice(11);

        if (!turno.entrega.recibe) {
            return `Terminado a las ${hora}`;
        }

        return turno.entrega.recibido_en
            ? `Entregado a ${turno.entrega.recibe} a las ${hora}`
            : `Entregado a ${turno.entrega.recibe} a las ${hora}, sin confirmar`;
    }

    return turno.inicio_real
        ? `En curso desde las ${turno.inicio_real.slice(11)}`
        : null;
}

export type OpcionGuardia = { id: number; nombre: string; turno: Turno };

// De lunes (1) a domingo (7)
export const DIAS_SEMANA: { dia: number; corto: string }[] = [
    { dia: 1, corto: 'Lun' },
    { dia: 2, corto: 'Mar' },
    { dia: 3, corto: 'Mié' },
    { dia: 4, corto: 'Jue' },
    { dia: 5, corto: 'Vie' },
    { dia: 6, corto: 'Sáb' },
    { dia: 7, corto: 'Dom' },
];

// Convierte AAAA-MM-DD en una fecha local sin cambiarla por la zona horaria
export function fechaLocal(fecha: string): Date {
    const [anio, mes, dia] = fecha.slice(0, 10).split('-').map(Number);

    return new Date(anio, mes - 1, dia);
}

export function textoFecha(fecha: Date): string {
    const anio = fecha.getFullYear();
    const mes = String(fecha.getMonth() + 1).padStart(2, '0');
    const dia = String(fecha.getDate()).padStart(2, '0');

    return `${anio}-${mes}-${dia}`;
}

// "Miércoles 19 de noviembre" con la primera letra en mayúscula
export function nombreDia(fecha: string): string {
    const texto = fechaLocal(fecha).toLocaleDateString('es-HN', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

export type TipoIncidencia = { id: number; nombre: string; activo: boolean };

export type Incidencia = {
    id: number;
    tipo: string;
    guardia: string;
    descripcion: string;
    // Fecha y hora de Honduras: AAAA-MM-DD HH:MM
    ocurrida_en: string;
    urgente: boolean;
    tiene_foto: boolean;
};
