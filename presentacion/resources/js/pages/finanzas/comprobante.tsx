import { Form, Head, Link } from '@inertiajs/react';
import { Copy, Download, Mail, MessageCircle, Plus } from 'lucide-react';
import { toast } from 'sonner';
import CobroController from '@/actions/Patronato/Presentacion/Controllers/CobroController';
import ComprobanteController from '@/actions/Patronato/Presentacion/Controllers/ComprobanteController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Periodo = {
    periodo: string;
    concepto: string;
    monto: string;
};

type Comprobante = {
    pago_id: number;
    residencial: string;
    numero_recibo: string;
    fecha: string;
    hora: string;
    vivienda: string;
    monto: string;
    periodos: Periodo[];
    cobrador: string;
};

const formatoMonto = (monto: string) =>
    Number(monto).toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatoFecha = (fecha: string) =>
    new Date(`${fecha}T00:00:00`).toLocaleDateString('es-HN', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

const formatoPeriodo = (periodo: string) =>
    new Date(`${periodo}T00:00:00`).toLocaleDateString('es-HN', {
        month: 'long',
        year: 'numeric',
    });

export default function ComprobantePago({
    comprobante,
    correos,
    enlace,
    diasEnlace,
    puedeCobrar,
}: {
    comprobante: Comprobante;
    correos: string[];
    enlace: string;
    diasEnlace: number;
    puedeCobrar: boolean;
}) {
    const mensaje = `Comprobante de pago N.º ${comprobante.numero_recibo} de ${comprobante.residencial}: ${enlace}`;

    const copiarEnlace = async () => {
        try {
            await navigator.clipboard.writeText(enlace);
            toast.success('Enlace copiado.');
        } catch {
            toast.error('No se pudo copiar el enlace.');
        }
    };

    return (
        <>
            <Head title={`Comprobante N.º ${comprobante.numero_recibo}`} />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Comprobante de pago
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Recibo N.º {comprobante.numero_recibo} ·{' '}
                        {comprobante.residencial}
                    </p>
                </div>

                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-xl border p-4">
                    <dt className="text-muted-foreground">Recibo</dt>
                    <dd className="font-semibold">
                        N.º {comprobante.numero_recibo}
                    </dd>
                    <dt className="text-muted-foreground">Fecha</dt>
                    <dd className="font-semibold">
                        {formatoFecha(comprobante.fecha)}, {comprobante.hora}
                    </dd>
                    <dt className="text-muted-foreground">Vivienda</dt>
                    <dd className="font-semibold">{comprobante.vivienda}</dd>
                    <dt className="text-muted-foreground">Monto</dt>
                    <dd className="font-semibold">
                        L {formatoMonto(comprobante.monto)}
                    </dd>
                    <dt className="text-muted-foreground">Cobrador</dt>
                    <dd className="font-semibold">{comprobante.cobrador}</dd>
                </dl>

                <div className="space-y-2">
                    <h2 className="text-sm font-medium">Períodos pagados</h2>
                    <ul className="divide-y rounded-xl border">
                        {comprobante.periodos.map((periodo) => (
                            <li
                                key={`${periodo.periodo}-${periodo.concepto}`}
                                className="flex items-center justify-between p-4"
                            >
                                <span>
                                    <span className="block capitalize">
                                        {formatoPeriodo(periodo.periodo)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {periodo.concepto}
                                    </span>
                                </span>
                                <span className="font-medium">
                                    L {formatoMonto(periodo.monto)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="grid gap-2 sm:grid-cols-3">
                    <Button asChild className="h-12">
                        <a
                            href={ComprobanteController.pdf.url(
                                comprobante.pago_id,
                            )}
                        >
                            <Download />
                            Descargar PDF
                        </a>
                    </Button>
                    <Button asChild variant="outline" className="h-12">
                        <a
                            href={`https://wa.me/?text=${encodeURIComponent(mensaje)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <MessageCircle />
                            WhatsApp
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        className="h-12"
                        onClick={copiarEnlace}
                    >
                        <Copy />
                        Copiar enlace
                    </Button>
                </div>
                <p className="text-sm text-muted-foreground">
                    El enlace para compartir abre el comprobante sin iniciar
                    sesión y vence en {diasEnlace} días.
                </p>

                <Form
                    {...ComprobanteController.enviar.form(comprobante.pago_id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-2 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor="correos">Enviar por correo</Label>
                            <Input
                                id="correos"
                                name="correos"
                                type="text"
                                inputMode="email"
                                defaultValue={correos.join(', ')}
                                placeholder="correo@ejemplo.com"
                                className="h-12"
                            />
                            <p className="text-sm text-muted-foreground">
                                Puedes cambiarlo o escribir varios separados por
                                coma.
                            </p>
                            <InputError message={errors.correos} />
                            <Button
                                variant="secondary"
                                className="h-12"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <Mail />}
                                Enviar comprobante
                            </Button>
                        </>
                    )}
                </Form>

                {puedeCobrar && (
                    <Button asChild variant="ghost" className="h-12 w-full">
                        <Link href={CobroController.create.url()}>
                            <Plus />
                            Registrar otro cobro
                        </Link>
                    </Button>
                )}
            </div>
        </>
    );
}
