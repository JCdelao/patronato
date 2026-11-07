export type Cambio = {
    campo: string;
    antes: string | null;
    despues: string | null;
};

export const textoAccion = (accion: string) => accion.replaceAll('_', ' ');

export default function TablaCambios({ cambios }: { cambios: Cambio[] }) {
    const conAntes = cambios.some((cambio) => cambio.antes !== null);
    const conDespues = cambios.some((cambio) => cambio.despues !== null);

    return (
        <div className="mt-2 overflow-x-auto rounded-lg border">
            <table className="w-full text-left text-sm">
                <thead className="bg-muted text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 font-medium">Dato</th>
                        {conAntes && (
                            <th className="px-3 py-2 font-medium">
                                {conDespues ? 'Antes' : 'Valor eliminado'}
                            </th>
                        )}
                        {conDespues && (
                            <th className="px-3 py-2 font-medium">
                                {conAntes ? 'Después' : 'Valor registrado'}
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {cambios.map((cambio) => (
                        <tr key={cambio.campo}>
                            <td className="px-3 py-2 font-medium">
                                {cambio.campo}
                            </td>
                            {conAntes && (
                                <td className="px-3 py-2 break-words">
                                    {cambio.antes ?? '—'}
                                </td>
                            )}
                            {conDespues && (
                                <td className="px-3 py-2 break-words">
                                    {cambio.despues ?? '—'}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
