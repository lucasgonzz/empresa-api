<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El broadcaster de Pusher de vendor, con el espacio de nombres del sistema (misión
 * pusher-canales-por-sistema, 19/10/2026). El porqué del espacio está en `EspacioDeCanales`.
 *
 * Toca los DOS únicos puntos por donde pasan todos los canales, y nada más:
 *
 * - `formatChannels()`: el último paso antes del `trigger` de Pusher, para TODO evento y TODA
 *   notificación del repo (`PusherBroadcaster::broadcast()` lo llama siempre). Acá cada canal lógico
 *   se convierte en su nombre físico. Por eso ningún `broadcastOn()` se toca uno por uno: siguen
 *   devolviendo `global_notification.{owner}`, `new PrivateChannel('chat.user.'.$id)`, etc.
 * - `auth()`: el `POST /broadcasting/auth` de los canales privados. Saca el sufijo propio y corre los
 *   callbacks de `routes/channels.php` contra el nombre lógico, que es el que tienen los patrones.
 *
 * Lo registra `BroadcastServiceProvider::boot()` en el `Broadcast::extend('pusher', ...)`, así que
 * solo actúa con `BROADCAST_DRIVER=pusher` (producción). Con `log` o `null` no cambia nada.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, str_starts_with, str_ends_with, nullsafe (?->),
 * argumentos nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class PusherBroadcasterConEspacio extends PusherBroadcaster
{
    /**
     * Lo que ve una pestaña que quedó abierta con el SPA de ANTES del release.
     *
     * Esa pestaña pide los privados sin sufijo (`private-chat.user.5`), este broadcaster los rechaza
     * —a propósito: por ese nombre ya no sale nada de este sistema y sí puede llegar lo de otro— y el
     * interceptor de axios del SPA muestra el `message` de un 4xx como aviso amarillo. Sin texto, el
     * aviso era el genérico "Hubo un error y no pudimos leer el detalle", una vez por canal privado y en
     * cada reconexión, hasta el F5. Con este texto, el mismo aviso dice qué hacer.
     */
    const MENSAJE_PESTANA_VIEJA = 'Hay una versión nueva del sistema: recargá la página para seguir recibiendo los avisos en vivo.';

    /**
     * Convierte los canales lógicos de un evento en los nombres físicos por los que sale.
     *
     * Cada canal pasa por `EspacioDeCanales::nombres_para_emitir()`: el físico siempre, y además el
     * lógico si es un canal de tienda en modo transición. Se sacan los repetidos (dos canales lógicos
     * distintos nunca dan el mismo físico, pero un canal que llega dos veces en el mismo evento sí), y
     * se respeta el orden en que vinieron.
     *
     * @param array $channels Canales del evento (strings o instancias de `Channel`).
     * @return string[]
     */
    protected function formatChannels(array $channels)
    {
        /** Nombres finales, en orden y sin repetir. */
        $nombres = [];

        foreach ($channels as $channel) {
            foreach (EspacioDeCanales::nombres_para_emitir((string) $channel) as $nombre) {
                if (!in_array($nombre, $nombres, true)) {
                    $nombres[] = $nombre;
                }
            }
        }

        return $nombres;
    }

    /**
     * Autoriza la suscripción a un canal privado pedido con su nombre FÍSICO.
     *
     * Replica `PusherBroadcaster::auth()` de vendor (Laravel 8.83) con una sola diferencia: el nombre
     * contra el que se corren los callbacks es el lógico que devuelve
     * `EspacioDeCanales::canal_para_autorizar()`, no el `channel_name` tal cual vino. Si esa traducción
     * dice que no (sufijo de otro sistema, sin sufijo fuera de tienda, vacío), es un 403 sin llamar a
     * ningún callback.
     *
     * 🔴 La firma la sigue haciendo `validAuthenticationResponse()` de vendor, que usa
     * `$request->channel_name`: el nombre COMPLETO, con el sufijo. Tiene que ser así, porque Pusher
     * verifica la firma contra el nombre con el que el navegador se suscribió. Por eso no se reescribe
     * `channel_name` en el request: firmar el nombre lógico daría una firma válida para un canal que
     * nadie escucha, y el navegador recibiría un error de suscripción.
     *
     * ⚠️ Canales de presencia: `validAuthenticationResponse()` resuelve el usuario con
     * `normalizeChannelName($request->channel_name)`, o sea con el sufijo. Hoy `routes/channels.php`
     * no registra opciones de guard por canal, así que da igual; si alguna vez se registran, hay que
     * revisar ese método también.
     *
     * @param \Illuminate\Http\Request $request
     * @return mixed La respuesta de autorización de Pusher (`['auth' => 'key:firma']`).
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function auth($request)
    {
        /** Nombre lógico con su prefijo de tipo (`private-chat.user.5`), o null si no se autoriza. */
        $canal_logico = EspacioDeCanales::canal_para_autorizar($request->channel_name);

        if ($canal_logico === null) {
            /*
             * Un nombre SIN ningún `@` es el de una pestaña con el SPA viejo: se le dice que recargue.
             * Un nombre con un sufijo que no es el propio (otro sistema, o algo armado a mano) es un
             * 403 pelado: no hay nada que explicarle.
             */
            if (strpos((string) $request->channel_name, EspacioDeCanales::SEPARADOR) === false) {
                throw new AccessDeniedHttpException(self::MENSAJE_PESTANA_VIEJA);
            }

            throw new AccessDeniedHttpException;
        }

        /** Nombre lógico sin el prefijo de tipo: es lo que comparan los patrones de channels.php. */
        $nombre_normalizado = $this->normalizeChannelName($canal_logico);

        if ($this->isGuardedChannel($canal_logico) &&
            ! $this->retrieveUser($request, $nombre_normalizado)) {
            throw new AccessDeniedHttpException;
        }

        return $this->verifyUserCanAccessChannel($request, $nombre_normalizado);
    }
}
