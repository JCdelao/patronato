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
