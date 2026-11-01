import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    return (
        <>
            <Head title="Crear cuenta" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                disableWhileProcessing
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nombre completo</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Correo</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
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
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            data-test="register-user-button"
                        >
                            {processing && <Spinner />}
                            Registrarme
                        </Button>

                        <Link
                            href={login()}
                            className="text-center text-sm text-muted-foreground hover:text-foreground"
                        >
                            ¿Ya tienes cuenta? Inicia sesión
                        </Link>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Crear cuenta',
};
