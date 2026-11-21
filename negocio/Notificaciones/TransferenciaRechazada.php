<?php

namespace Patronato\Negocio\Notificaciones;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al residente de que tesorería rechazó su transferencia, con el motivo
 */
class TransferenciaRechazada extends Notification
{
    public function __construct(
        public string $residencial,
        public string $vivienda,
        public string $monto,
        public string $fechaPago,
        public string $banco,
        public string $numeroReferencia,
        public string $motivo,
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
            ->subject("Transferencia rechazada · vivienda {$this->vivienda} · {$this->residencial}")
            ->greeting('Hola')
            ->line("Tesorería no pudo confirmar la transferencia de la vivienda {$this->vivienda}.")
            ->line("Monto: L {$this->monto} · Fecha: {$this->fechaPago}")
            ->line("Banco: {$this->banco} · Referencia: {$this->numeroReferencia}")
            ->line("Motivo: {$this->motivo}")
            ->line('No se aplicó al estado de cuenta. Si crees que es un error, comunícate con tesorería.');
    }
}
