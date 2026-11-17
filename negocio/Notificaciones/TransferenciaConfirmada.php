<?php

namespace Patronato\Negocio\Notificaciones;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al residente de que tesorería confirmó su transferencia, con el comprobante en PDF
 */
class TransferenciaConfirmada extends Notification
{
    public function __construct(
        public string $residencial,
        public string $vivienda,
        public string $monto,
        public string $fechaPago,
        public string $banco,
        public string $numeroReferencia,
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
            ->subject("Transferencia confirmada · recibo N.º {$this->numeroRecibo} · {$this->residencial}")
            ->greeting('Hola')
            ->line("Tesorería confirmó la transferencia de la vivienda {$this->vivienda}.")
            ->line("Monto: L {$this->monto} · Fecha: {$this->fechaPago}")
            ->line("Banco: {$this->banco} · Referencia: {$this->numeroReferencia}")
            ->line("Ya se aplicó al estado de cuenta con el recibo N.º {$this->numeroRecibo}, que va adjunto en PDF.")
            ->attachData($this->pdf, $this->archivo, ['mime' => 'application/pdf']);
    }
}
