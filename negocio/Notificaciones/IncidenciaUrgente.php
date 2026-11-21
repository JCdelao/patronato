<?php

namespace Patronato\Negocio\Notificaciones;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al presidente de una incidencia que el guardia marcó como urgente
 */
class IncidenciaUrgente extends Notification
{
    public function __construct(
        public string $tipo,
        public string $guardia,
        public string $ocurridaEn,
        public string $descripcion,
        public bool $conFoto,
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
            ->subject("Incidencia urgente: {$this->tipo}")
            ->greeting('Hola')
            ->line("{$this->guardia} registró una incidencia urgente de tipo {$this->tipo}.")
            ->line("Ocurrió el {$this->ocurridaEn}.")
            ->line($this->descripcion)
            ->line($this->conFoto ? 'La incidencia tiene una foto adjunta en el sistema.' : 'La incidencia no tiene foto.')
            ->action('Ver las incidencias urgentes', route('vigilancia.incidencias.index', ['urgentes' => 1]));
    }
}
