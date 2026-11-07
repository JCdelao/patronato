<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CuentaCreada extends Notification
{
    public function __construct(
        public string $passwordTemporal,
        public string $rol,
    ) {}

    /**
     * Canales de envío
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Contenido del correo
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu cuenta en el sistema del Patronato')
            ->greeting("Hola, {$notifiable->name}")
            ->line("Se creó tu cuenta con el rol {$this->rol}.")
            ->line("Correo: {$notifiable->email}")
            ->line("Contraseña temporal: {$this->passwordTemporal}")
            ->line('Al iniciar sesión por primera vez, el sistema te pedirá cambiarla.')
            ->action('Iniciar sesión', route('login'));
    }
}
