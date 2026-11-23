import { Head } from '@inertiajs/react';
import { QrCode, RefreshCw, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import PortalQrController from '@/actions/Patronato/Presentacion/Controllers/PortalQrController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

function ImagenQr({ imagen }: { imagen: Blob }) {
    const ref = useRef<HTMLImageElement>(null);

    useEffect(() => {
        const elemento = ref.current;

        if (!elemento) {
            return;
        }

        const url = URL.createObjectURL(imagen);
        elemento.src = url;

        return () => URL.revokeObjectURL(url);
    }, [imagen]);

    return (
        <img
            ref={ref}
            alt="Mi código QR de residente"
            width={256}
            height={256}
            className="h-auto max-w-full rounded-lg bg-white"
        />
    );
}

function mensajeError(respuesta: Response): string {
    if (respuesta.redirected || [401, 419].includes(respuesta.status)) {
        return 'Tu sesión venció o tu cuenta no está activa. Vuelve a iniciar sesión.';
    }

    if (respuesta.status === 403) {
        return 'Para obtener tu QR necesitas una cuenta vinculada a un residente activo, permiso de portal y un carnet activo y vigente.';
    }

    if (respuesta.status === 429) {
        return 'Hiciste varias solicitudes seguidas. Espera un minuto e inténtalo de nuevo.';
    }

    return 'No pudimos mostrar tu código QR. Inténtalo de nuevo.';
}

export default function MiQr() {
    const [imagen, setImagen] = useState<Blob | null>(null);
    const [confirmar, setConfirmar] = useState(false);
    const [procesando, setProcesando] = useState(false);
    const [error, setError] = useState('');
    const [aviso, setAviso] = useState('');

    async function solicitarQr(regenerar: boolean) {
        setConfirmar(false);
        setProcesando(true);
        setError('');
        setAviso('');

        if (regenerar) {
            setImagen(null);
        }

        try {
            const csrf = document.cookie.match(
                /(?:^|;\s*)XSRF-TOKEN=([^;]*)/,
            )?.[1];
            const url = regenerar
                ? PortalQrController.regenerar.url()
                : PortalQrController.emitir.url();
            const respuesta = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'image/svg+xml',
                    ...(csrf && { 'X-XSRF-TOKEN': decodeURIComponent(csrf) }),
                },
            });

            if (
                !respuesta.ok ||
                !respuesta.headers
                    .get('Content-Type')
                    ?.startsWith('image/svg+xml')
            ) {
                setError(mensajeError(respuesta));
                return;
            }

            setImagen(await respuesta.blob());
            setAviso(regenerar ? 'Tu código QR se actualizó.' : '');
        } catch {
            setError(
                'No pudimos conectar para mostrar tu QR. Inténtalo de nuevo.',
            );
        } finally {
            setProcesando(false);
        }
    }

    return (
        <>
            <Head title="Mi código QR" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-10">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Mi código QR
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        Muestra este código al personal de seguridad cuando te
                        lo solicite.
                    </p>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-col items-center gap-4 rounded-xl border p-6">
                    {imagen && <ImagenQr imagen={imagen} />}
                    <Button
                        disabled={procesando}
                        variant={imagen ? 'outline' : 'default'}
                        onClick={() => {
                            if (imagen) {
                                setConfirmar(true);
                            } else {
                                void solicitarQr(false);
                            }
                        }}
                    >
                        {procesando ? (
                            <Spinner />
                        ) : imagen ? (
                            <RefreshCw />
                        ) : (
                            <QrCode />
                        )}
                        {imagen ? 'Regenerar QR' : 'Mostrar mi QR'}
                    </Button>
                    {aviso && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {aviso}
                        </p>
                    )}
                </div>
            </div>

            <Dialog open={confirmar} onOpenChange={setConfirmar}>
                <DialogContent className="rounded-2xl">
                    <DialogTitle className="text-2xl font-bold tracking-tight">
                        ¿Regenerar tu código QR?
                    </DialogTitle>
                    <DialogDescription>
                        El código anterior dejará de funcionar de inmediato.
                    </DialogDescription>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmar(false)}
                        >
                            Cancelar
                        </Button>
                        <Button onClick={() => void solicitarQr(true)}>
                            Regenerar QR
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
