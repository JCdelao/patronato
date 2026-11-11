import { Form, Head } from '@inertiajs/react';
import ResidencialController from '@/actions/Patronato/Presentacion/Controllers/ResidencialController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function CrearResidencial() {
    return (
        <>
            <Head title="Registrar residencial" />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Registrar residencial"
                    description="Crea el residencial y la cuenta de su administrador técnico"
                />

                <Form
                    {...ResidencialController.store.form()}
                    className="space-y-8"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="nombre">Nombre</Label>
                                    <Input
                                        id="nombre"
                                        name="nombre"
                                        required
                                        autoFocus
                                        maxLength={150}
                                    />
                                    <InputError message={errors.nombre} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="direccion">Dirección</Label>
                                    <Input
                                        id="direccion"
                                        name="direccion"
                                        maxLength={255}
                                    />
                                    <InputError message={errors.direccion} />
                                </div>

                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="telefono">
                                            Teléfono
                                        </Label>
                                        <Input
                                            id="telefono"
                                            name="telefono"
                                            type="tel"
                                            maxLength={30}
                                        />
                                        <InputError message={errors.telefono} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="correo">Correo</Label>
                                        <Input
                                            id="correo"
                                            name="correo"
                                            type="email"
                                            maxLength={255}
                                        />
                                        <InputError message={errors.correo} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="logo">Logo</Label>
                                    <Input
                                        id="logo"
                                        name="logo"
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        PNG, JPG o WEBP, hasta 2 MB.
                                    </p>
                                    <InputError message={errors.logo} />
                                </div>
                            </div>

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title="Administrador técnico"
                                    description="Recibirá una contraseña temporal que debe cambiar en su primer inicio de sesión"
                                />

                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="admin_nombre">
                                            Nombre
                                        </Label>
                                        <Input
                                            id="admin_nombre"
                                            name="admin_nombre"
                                            required
                                            maxLength={255}
                                        />
                                        <InputError
                                            message={errors.admin_nombre}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="admin_correo">
                                            Correo
                                        </Label>
                                        <Input
                                            id="admin_correo"
                                            name="admin_correo"
                                            type="email"
                                            required
                                            maxLength={255}
                                        />
                                        <InputError
                                            message={errors.admin_correo}
                                        />
                                    </div>
                                </div>
                            </div>

                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Registrar residencial
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CrearResidencial.layout = {
    breadcrumbs: [
        {
            title: 'Residenciales',
            href: ResidencialController.index(),
        },
        {
            title: 'Registrar',
            href: ResidencialController.create(),
        },
    ],
};
