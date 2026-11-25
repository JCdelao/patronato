import { Head } from '@inertiajs/react';
import jsQR from 'jsqr';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import EscaneoQrController from '@/actions/Patronato/Presentacion/Controllers/EscaneoQrController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Registro = {
    id: number;
    hora: string;
    resultado: 'permitido' | 'denegado' | 'revisar';
    decision: 'ingreso' | 'no_ingreso' | null;
};
type Escaneo = Registro & {
    nombre: string | null;
    viviendas: string[];
    foto: boolean;
};

const DECISION = { ingreso: 'Ingreso', no_ingreso: 'No ingreso' };

async function enviar(url: string, datos: object) {
    const csrf = document.cookie
        .split('; ')
        .find((parte) => parte.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrf && { 'X-XSRF-TOKEN': decodeURIComponent(csrf) }),
        },
        body: JSON.stringify(datos),
    });
    if (!respuesta.ok || respuesta.redirected) {
        const mensajes: Record<number, string> = {
            403: 'Tu cuenta no está autorizada para esta operación.',
            409: 'La decisión ya se registró o el QR perdió vigencia. Actualiza el historial o vuelve a escanear.',
            419: 'Tu sesión venció. Vuelve a iniciar sesión.',
            422: 'Inicia tu turno antes de escanear o decidir.',
            429: 'Se alcanzó el límite de solicitudes. Espera un minuto.',
        };
        throw new Error(
            mensajes[respuesta.status] ?? 'No pudimos completar la solicitud.',
        );
    }

    return respuesta;
}

export default function EscanearQr({ historial }: { historial: Registro[] }) {
    const [registros, setRegistros] = useState(historial);
    const [escaneo, setEscaneo] = useState<Escaneo | null>(null);
    const [codigo, setCodigo] = useState('');
    const [error, setError] = useState('');
    const [procesando, setProcesando] = useState(false);
    const [camara, setCamara] = useState(false);
    const video = useRef<HTMLVideoElement>(null);
    const ocupado = useRef(false);

    async function verificar(valor: string) {
        if (ocupado.current) return;
        ocupado.current = true;
        setProcesando(true);
        setCamara(false);
        setError('');
        setEscaneo(null);
        setCodigo('');
        try {
            const respuesta = await enviar(EscaneoQrController.store().url, {
                codigo: valor,
            });
            const nuevo: Escaneo = await respuesta.json();
            setEscaneo(nuevo);
            setRegistros((actual) => [
                {
                    id: nuevo.id,
                    hora: nuevo.hora,
                    resultado: nuevo.resultado,
                    decision: nuevo.decision,
                },
                ...actual.slice(0, 29),
            ]);
        } catch (fallo) {
            setError(
                fallo instanceof Error && !(fallo instanceof TypeError)
                    ? fallo.message
                    : 'Revisa la conexión e inténtalo de nuevo.',
            );
        } finally {
            ocupado.current = false;
            setProcesando(false);
        }
    }

    useEffect(() => {
        if (!camara) return;

        let cancelado = false;
        let flujo: MediaStream | undefined;
        let intervalo: ReturnType<typeof setInterval> | undefined;
        const elemento = video.current;
        const lienzo = document.createElement('canvas');
        const contexto = lienzo.getContext('2d', { willReadFrequently: true });

        async function iniciar() {
            if (!navigator.mediaDevices || !elemento || !contexto) {
                setError(
                    'La cámara requiere una conexión segura. Puedes ingresar el identificador manualmente.',
                );
                setCamara(false);
                return;
            }

            try {
                flujo = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });
                if (cancelado) {
                    flujo.getTracks().forEach((pista) => pista.stop());
                    return;
                }

                elemento.srcObject = flujo;
                await elemento.play();
                if (cancelado) return;
                intervalo = setInterval(() => {
                    if (!elemento.videoWidth || ocupado.current) return;
                    lienzo.width = Math.min(elemento.videoWidth, 800);
                    lienzo.height = Math.round(
                        (elemento.videoHeight * lienzo.width) /
                            elemento.videoWidth,
                    );
                    contexto.drawImage(
                        elemento,
                        0,
                        0,
                        lienzo.width,
                        lienzo.height,
                    );
                    const imagen = contexto.getImageData(
                        0,
                        0,
                        lienzo.width,
                        lienzo.height,
                    );
                    const qr = jsQR(imagen.data, imagen.width, imagen.height, {
                        inversionAttempts: 'dontInvert',
                    });
                    if (qr?.data) {
                        clearInterval(intervalo);
                        void verificar(qr.data);
                    }
                }, 180);
            } catch {
                if (!cancelado) {
                    setError(
                        'No se pudo abrir la cámara. Revisa el permiso o ingresa el identificador manualmente.',
                    );
                    setCamara(false);
                }
            }
        }

        void iniciar();

        return () => {
            cancelado = true;
            clearInterval(intervalo);
            flujo?.getTracks().forEach((pista) => pista.stop());
            if (elemento) elemento.srcObject = null;
        };
    }, [camara]);

    async function decidir(
        registro: Registro,
        decision: keyof typeof DECISION,
    ) {
        if (ocupado.current) return;
        ocupado.current = true;
        setProcesando(true);
        setError('');
        try {
            await enviar(EscaneoQrController.decidir(registro.id).url, {
                decision,
            });
            setRegistros((actual) =>
                actual.map((item) =>
                    item.id === registro.id ? { ...item, decision } : item,
                ),
            );
            setEscaneo((actual) =>
                actual?.id === registro.id ? { ...actual, decision } : actual,
            );
        } catch (fallo) {
            setError(
                fallo instanceof Error && !(fallo instanceof TypeError)
                    ? fallo.message
                    : 'Revisa la conexión e inténtalo de nuevo.',
            );
        } finally {
            ocupado.current = false;
            setProcesando(false);
        }
    }

    function enviarManual(evento: FormEvent<HTMLFormElement>) {
        evento.preventDefault();
        void verificar(codigo);
    }

    return (
        <main className="mx-auto grid w-full max-w-2xl gap-5 p-4 sm:p-6">
            <Head title="Escanear QR" />
            <h1 className="text-2xl font-semibold">Escanear QR</h1>
            <section className="grid gap-4 rounded-lg border p-4">
                <Button
                    type="button"
                    variant="outline"
                    disabled={procesando}
                    onClick={() => {
                        setError('');
                        setCamara(!camara);
                    }}
                >
                    {camara ? 'Apagar cámara' : 'Abrir cámara'}
                </Button>
                {camara && (
                    <video
                        ref={video}
                        muted
                        playsInline
                        className="max-h-80 w-full rounded-md bg-black object-contain"
                    />
                )}
                <form onSubmit={enviarManual} className="grid gap-2">
                    <label htmlFor="codigo-qr" className="text-sm font-medium">
                        Identificador del QR
                    </label>
                    <Input
                        id="codigo-qr"
                        value={codigo}
                        onChange={(evento) => setCodigo(evento.target.value)}
                        autoComplete="off"
                        autoCapitalize="off"
                        spellCheck={false}
                        maxLength={100}
                        required
                    />
                    <Button disabled={procesando}>Verificar código</Button>
                </form>
            </section>
            {error && (
                <p
                    role="alert"
                    className="rounded-md border border-destructive p-3 text-sm text-destructive"
                >
                    {error}
                </p>
            )}
            {procesando && <p role="status">Procesando…</p>}
            {escaneo && (
                <section
                    aria-live="polite"
                    className="grid gap-3 rounded-lg border p-4"
                >
                    <h2
                        className={
                            'text-xl font-bold uppercase ' +
                            {
                                permitido:
                                    'text-emerald-700 dark:text-emerald-400',
                                denegado: 'text-red-700 dark:text-red-400',
                                revisar: 'text-amber-800 dark:text-amber-400',
                            }[escaneo.resultado]
                        }
                    >
                        {escaneo.resultado}
                    </h2>
                    {escaneo.foto && (
                        <img
                            src={EscaneoQrController.foto(escaneo.id).url}
                            alt="Foto del residente"
                            className="size-24 rounded-md object-cover"
                            onError={(evento) => {
                                evento.currentTarget.hidden = true;
                            }}
                        />
                    )}
                    {escaneo.nombre && (
                        <p className="font-medium">{escaneo.nombre}</p>
                    )}
                    {escaneo.viviendas.length > 0 && (
                        <p>Vivienda: {escaneo.viviendas.join(', ')}</p>
                    )}
                    {escaneo.resultado === 'revisar' && (
                        <p>
                            Verifica el vínculo con la vivienda antes de
                            decidir.
                        </p>
                    )}
                    {escaneo.decision ? (
                        <p>Decisión: {DECISION[escaneo.decision]}</p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {escaneo.resultado !== 'denegado' &&
                                escaneo.viviendas.length > 0 && (
                                    <Button
                                        disabled={procesando}
                                        onClick={() =>
                                            void decidir(escaneo, 'ingreso')
                                        }
                                    >
                                        Autorizar ingreso
                                    </Button>
                                )}
                            <Button
                                variant="outline"
                                disabled={procesando}
                                onClick={() =>
                                    void decidir(escaneo, 'no_ingreso')
                                }
                            >
                                Registrar no ingreso
                            </Button>
                        </div>
                    )}
                </section>
            )}
            <section className="rounded-lg border p-4">
                <h2 className="font-semibold">Escaneos recientes</h2>
                {registros.length === 0 && (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Aún no hay escaneos.
                    </p>
                )}
                <ul className="divide-y">
                    {registros.map((registro) => (
                        <li
                            key={registro.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                        >
                            <div>
                                <time dateTime={registro.hora}>
                                    {new Date(registro.hora).toLocaleString(
                                        'es-HN',
                                        { timeZone: 'America/Tegucigalpa' },
                                    )}
                                </time>
                                <p>
                                    <span className="capitalize">
                                        {registro.resultado}
                                    </span>{' '}
                                    ·{' '}
                                    {registro.decision
                                        ? DECISION[registro.decision]
                                        : 'Pendiente'}
                                </p>
                            </div>
                            {!registro.decision &&
                                registro.id !== escaneo?.id && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={procesando}
                                        onClick={() =>
                                            void decidir(registro, 'no_ingreso')
                                        }
                                    >
                                        Cerrar sin ingreso
                                    </Button>
                                )}
                        </li>
                    ))}
                </ul>
            </section>
        </main>
    );
}
