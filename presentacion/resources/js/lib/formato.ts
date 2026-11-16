// Monto en lempiras con dos decimales
export const formatoMonto = (monto: string | number) =>
    Number(monto).toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

// Fecha y hora en la zona de Honduras
export const formatoFechaHora = (fecha: string) =>
    new Date(fecha).toLocaleString('es-HN', {
        timeZone: 'America/Tegucigalpa',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    });

// Convierte un monto a centavos para sumar y restar sin errores de redondeo
export const centavos = (monto: string) => Math.round(Number(monto) * 100);

// Fecha sin hora (Y-m-d) con el mes en letras
export const formatoFecha = (fecha: string) =>
    new Date(`${fecha}T00:00:00`).toLocaleDateString('es-HN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

// Nombre visible de cada forma de pago
export const FORMAS_PAGO: Record<string, string> = {
    efectivo: 'Efectivo',
    transferencia: 'Transferencia',
    cheque: 'Cheque',
};
