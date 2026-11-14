<?php

namespace Patronato\Negocio\Notificaciones;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo con el comprobante de un pago adjunto en PDF
 */
class ComprobantePago extends Notification
{
    public function __construct(
        public string $residencial,
        public string $numeroRecibo,
        public string $pdf,
        public string $archivo,
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
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Comprobante de pago N.º {$this->numeroRecibo} · {$this->residencial}")
            ->greeting('Hola')
            ->line("Te enviamos el comprobante del pago con recibo N.º {$this->numeroRecibo}.")
            ->line('Lo encuentras adjunto en PDF.')
            ->attachData($this->pdf, $this->archivo, ['mime' => 'application/pdf']);
    }
}
