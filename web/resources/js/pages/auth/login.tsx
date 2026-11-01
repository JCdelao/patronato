import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Iniciar sesión" />

            {status && (
                <div className="mb-4 text-sm font-medium text-primary">
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Correo</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Contraseña</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            Entrar
                        </Button>

                        <div className="grid gap-2 text-center text-sm text-muted-foreground">
                            {canResetPassword && (
                                <Link
                                    href={request()}
                                    className="hover:text-foreground"
                                >
                                    ¿Olvidaste tu contraseña?
                                </Link>
                            )}
                            <Link
                                href={register()}
                                className="hover:text-foreground"
                            >
                                ¿No tienes cuenta? Regístrate
                            </Link>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Iniciar sesión',
};
