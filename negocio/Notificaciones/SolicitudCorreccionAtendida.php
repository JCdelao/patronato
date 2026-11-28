<?php

namespace Patronato\Negocio\Notificaciones;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al residente de que la Junta Directiva resolvió o rechazó su solicitud de corrección (HU-54)
 */
class SolicitudCorreccionAtendida extends Notification
{
    public function __construct(
        public string $residencial,
        public string $vivienda,
        public string $tipo,
        public bool $resuelta,
        public string $respuesta,
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
        $resultado = $this->resuelta ? 'resuelta' : 'rechazada';

        return (new MailMessage)
            ->subject("Solicitud de corrección {$resultado} · vivienda {$this->vivienda} · {$this->residencial}")
            ->greeting('Hola')
            ->line("La Junta Directiva marcó como {$resultado} tu solicitud de corrección de la vivienda {$this->vivienda}.")
            ->line("Tipo: {$this->tipo}")
            ->line("Respuesta: {$this->respuesta}")
            ->line('Puedes ver todas tus solicitudes en el portal, en "Mis solicitudes".');
    }
}
