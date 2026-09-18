<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class SessionForcedLogoutNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast'];
    }

    /**
     * Sin `broadcastOn()` propio: cae en el canal automático `App.Models.User.{id}`, ya
     * autorizado en `routes/channels.php` (solo el propio usuario escucha su propio canal).
     * No hace falta declarar un canal nuevo para esto.
     *
     * 🔴 `onConnection('sync')`: mismo motivo que `GlobalNotification::toBroadcast()`. Sin esto
     * el aviso sale por `BroadcastChannel` (ShouldBroadcast, no Now) y con `QUEUE_CONNECTION`
     * de cola queda esperando al worker del scheduler -hasta 75 minutos, ver
     * informes/20260831-avisos-en-tiempo-real-que-no-llegaban.md-. Acá el aviso tiene que salir
     * en el instante en que se fuerza el login, no en el próximo ciclo del worker.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        return (new BroadcastMessage([
            'message' => 'Tu sesión se cerró en este dispositivo porque se inició sesión en otro.',
        ]))->onConnection('sync');
    }
}
