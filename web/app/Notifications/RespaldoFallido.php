<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RespaldoFallido extends Notification
{
    public function __construct(public string $error) {}

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
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('El respaldo de la base de datos falló')
            ->line('El respaldo automático de la base de datos no se pudo generar.')
            ->line("Detalle: {$this->error}")
            ->line('Revisa el servidor y ejecuta de nuevo: php artisan respaldos:crear');
    }
}
