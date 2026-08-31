<?php

namespace App\Notifications;

use App\Models\ImportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImportStatusNotification extends Notification
{
    // use Queueable;

    public $import_status;
    public $owner_id;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($import_status_id, $owner_id)
    {
        $this->import_status = ImportStatus::where('id', $import_status_id)
                                            ->withAll()
                                            ->first();
        $this->owner_id = $owner_id;
    }

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

    public function broadcastOn() {
        return 'import_status.'.$this->owner_id;
    }


    /**
     * El aviso de progreso de la importacion sale en el momento, sin pasar por la cola.
     *
     * 🔴 Este es el aviso que le mueve la barra de progreso al usuario mientras importa. Encolado
     * llegaba tarde o no llegaba: ver el porque completo en el docblock de
     * `App\Notifications\Channels\InstantBroadcastChannel`, que ademas es quien ataja una caida de
     * Pusher para que no vuelva como excepcion al emisor.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\BroadcastMessage
     */
    public function toBroadcast($notifiable) {
        return (new BroadcastMessage([
            'import_status'              => $this->import_status,
        ]))->onConnection('sync');
    }

    /**
     * ⚠️ Aca vivian tres metodos que intentaban lo mismo que ahora hace el `onConnection('sync')`
     * de arriba, y los tres eran CODIGO MUERTO. Se sacaron el 31/8/2026, medido uno por uno:
     *
     * - `shouldBroadcastNow()`: devolvia true, pero `BroadcastManager::queue()` consulta ese
     *   metodo sobre el EVENTO (`BroadcastNotificationCreated`), no sobre la notificacion, y ese
     *   evento no lo tiene.
     * - `viaConnections()`: **no existe en ninguna parte del framework** en esta version de
     *   Laravel. Grep sobre `vendor/laravel/framework/src/` completo: cero coincidencias.
     * - `viaQueues()`: solo lo lee `NotificationSender::queueNotification()`, que corre unicamente
     *   si la notificacion `implements ShouldQueue`, y esta clase no lo implementa.
     *
     * Su comentario decia "Fuerza el canal broadcast a ejecutarse en conexion sync (no queda
     * encolado)" y era falso: medido con `BROADCAST_DRIVER=log`, la notificacion **igual dejaba su
     * fila en `jobs`**. Alguien ya habia intentado arreglar este mismo problema y quedo creyendo
     * que estaba resuelto, que es exactamente por lo que se sacan en vez de dejarlos "por las
     * dudas": un metodo muerto que aparenta funcionar es peor que no tener nada.
     *
     * Con ellos se fue el comentario `// 🔥 Esta es la parte clave` que los encabezaba, y que
     * senalaba justamente al que menos hacia.
     */
}
