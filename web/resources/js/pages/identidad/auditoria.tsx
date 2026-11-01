import { Form, Head, Link } from '@inertiajs/react';
import AuditoriaController from '@/actions/Patronato/Modules/Identidad/Controllers/AuditoriaController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Registro = {
    id: number;
    fecha: string;
    usuario: string;
    modulo: string;
    accion: string;
    entidad: string | null;
    entidad_id: number | null;
    valor_anterior: Record<string, unknown> | null;
    valor_nuevo: Record<string, unknown> | null;
    motivo: string | null;
};

type Filtros = {
    desde: string | null;
    hasta: string | null;
    user_id: string | null;
    modulo: string | null;
    accion: string | null;
};

const textoAccion = (accion: string) => accion.replaceAll('_', ' ');

function FiltroSelect({
    id,
    etiqueta,
    valor,
    opciones,
}: {
    id: keyof Filtros;
    etiqueta: string;
    valor: string | null;
    opciones: { valor: string; texto: string }[];
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{etiqueta}</Label>
            <Select name={id} defaultValue={valor ?? 'todos'}>
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="todos">Todos</SelectItem>
                    {opciones.map((opcion) => (
                        <SelectItem key={opcion.valor} value={opcion.valor}>
                            {opcion.texto}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

export default function Auditoria({
    registros,
    usuarios,
    modulos,
    acciones,
    filtros,
}: {
    registros: {
        data: Registro[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    usuarios: { id: number; name: string }[];
    modulos: string[];
    acciones: string[];
    filtros: Filtros;
}) {
    return (
        <>
            <Head title="Auditoría" />

            <div className="space-y-6 p-6 md:p-10">
                <div>
                    <h1 className="text-4xl font-bold tracking-tight">
                        Auditoría
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Quién hizo cada operación, cuándo y por qué. Solo
                        consulta: estos registros no se pueden cambiar.
                    </p>
                </div>

                <Form
                    {...AuditoriaController.index.form()}
                    className="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-6"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="desde">Desde</Label>
                        <Input
                            id="desde"
                            name="desde"
                            type="date"
                            defaultValue={filtros.desde ?? ''}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="hasta">Hasta</Label>
                        <Input
                            id="hasta"
                            name="hasta"
                            type="date"
                            defaultValue={filtros.hasta ?? ''}
                        />
                    </div>
                    <FiltroSelect
                        id="user_id"
                        etiqueta="Usuario"
                        valor={filtros.user_id}
                        opciones={usuarios.map((usuario) => ({
                            valor: usuario.id.toString(),
                            texto: usuario.name,
                        }))}
                    />
                    <FiltroSelect
                        id="modulo"
                        etiqueta="Módulo"
                        valor={filtros.modulo}
                        opciones={modulos.map((modulo) => ({
                            valor: modulo,
                            texto: modulo,
                        }))}
                    />
                    <FiltroSelect
                        id="accion"
                        etiqueta="Acción"
                        valor={filtros.accion}
                        opciones={acciones.map((accion) => ({
                            valor: accion,
                            texto: textoAccion(accion),
                        }))}
                    />
                    <Button>Filtrar</Button>
                </Form>

                {registros.data.length === 0 ? (
                    <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                        No hay registros con esos filtros.
                    </p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-card">
                        {registros.data.map((registro) => (
                            <li key={registro.id} className="space-y-1 p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {textoAccion(registro.accion)}
                                    </Badge>
                                    <span className="font-medium">
                                        {registro.usuario}
                                    </span>
                                    <span className="ml-auto text-sm text-muted-foreground">
                                        {new Date(
                                            registro.fecha,
                                        ).toLocaleString('es-HN')}
                                    </span>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {registro.modulo}
                                    {registro.entidad &&
                                        ` · ${registro.entidad} #${registro.entidad_id}`}
                                </p>
                                {registro.motivo && (
                                    <p className="text-sm">{registro.motivo}</p>
                                )}
                                {(registro.valor_anterior ||
                                    registro.valor_nuevo) && (
                                    <details className="text-sm">
                                        <summary className="cursor-pointer text-primary">
                                            Ver cambio
                                        </summary>
                                        <div className="mt-2 grid gap-2 md:grid-cols-2">
                                            <div className="min-w-0">
                                                <p className="font-medium">
                                                    Antes
                                                </p>
                                                <pre className="overflow-x-auto rounded-lg bg-muted p-2 text-xs">
                                                    {JSON.stringify(
                                                        registro.valor_anterior,
                                                        null,
                                                        2,
                                                    )}
                                                </pre>
                                            </div>
                                            <div className="min-w-0">
                                                <p className="font-medium">
                                                    Después
                                                </p>
                                                <pre className="overflow-x-auto rounded-lg bg-muted p-2 text-xs">
                                                    {JSON.stringify(
                                                        registro.valor_nuevo,
                                                        null,
                                                        2,
                                                    )}
                                                </pre>
                                            </div>
                                        </div>
                                    </details>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {(registros.prev_page_url || registros.next_page_url) && (
                    <nav className="flex justify-end gap-3">
                        {registros.prev_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={registros.prev_page_url}>
                                    Anterior
                                </Link>
                            </Button>
                        )}
                        {registros.next_page_url && (
                            <Button variant="outline" asChild>
                                <Link href={registros.next_page_url}>
                                    Siguiente
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

Auditoria.layout = {
    breadcrumbs: [
        {
            title: 'Auditoría',
            href: AuditoriaController.index(),
        },
    ],
};
