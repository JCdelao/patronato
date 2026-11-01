import { Form, Head } from '@inertiajs/react';
import PasswordTemporalController from '@/actions/Patronato/Modules/Identidad/Controllers/PasswordTemporalController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

export default function CambiarPassword({
    passwordRules,
}: {
    passwordRules: string;
}) {
    return (
        <>
            <Head title="Cambiar contraseña" />

            <Form
                {...PasswordTemporalController.update.form()}
                resetOnError
                resetOnSuccess
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="current_password">
                                Contraseña temporal
                            </Label>
                            <PasswordInput
                                id="current_password"
                                name="current_password"
                                autoComplete="current-password"
                                autoFocus
                                required
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Nueva contraseña</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                required
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirmar contraseña
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Guardar contraseña
                        </Button>

                        <TextLink
                            href={logout()}
                            as="button"
                            className="mx-auto block text-sm"
                        >
                            Cerrar sesión
                        </TextLink>
                    </div>
                )}
            </Form>
        </>
    );
}

CambiarPassword.layout = {
    title: 'Cambia tu contraseña',
    description:
        'Debes reemplazar la contraseña temporal antes de usar el sistema',
};
