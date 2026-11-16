<?php

namespace Patronato\Negocio\Notificaciones;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a tesorería de una transferencia que un vecino reportó desde el portal
 */
class TransferenciaReportada extends Notification
{
    public function __construct(
        public int $pagoId,
        public string $vivienda,
        public string $residente,
        public string $monto,
        public string $fechaPago,
        public string $banco,
        public string $numeroReferencia,
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
            ->subject("Transferencia reportada por la vivienda {$this->vivienda}")
            ->greeting('Hola')
            ->line("{$this->residente} reportó desde el portal una transferencia de la vivienda {$this->vivienda}.")
            ->line("Monto: L {$this->monto} · Fecha: {$this->fechaPago}")
            ->line("Banco: {$this->banco} · Referencia: {$this->numeroReferencia}")
            ->line('No reduce el saldo de la vivienda hasta que se verifique que el dinero ingresó.')
            // Quien registra transferencias lo abre aunque no vea los cobros
            ->action('Ver la transferencia', route('finanzas.transferencias.reportes.show', $this->pagoId));
    }
}
