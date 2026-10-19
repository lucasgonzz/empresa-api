<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * 🔴 Los patrones de este archivo son nombres LÓGICOS (misión pusher-canales-por-sistema, 19/10/2026).
 *
 * Toda la flota emite a una sola app de Pusher, así que cada canal sale con el sufijo del sistema:
 * el navegador se suscribe a `private-chat.user.5@26d5013a6846`, no a `private-chat.user.5`. Los
 * patrones de acá NO llevan ese sufijo y no tienen que llevarlo: quien lo saca antes de correr el
 * callback es `App\Broadcasting\PusherBroadcasterConEspacio::auth()`, que además corta con 403, SIN
 * llamar a ningún callback, un sufijo de OTRO sistema o un nombre sin sufijo (salvo
 * `tienda-mensajes.{owner_id}` en el modo de transición con la tienda, ver
 * `config/broadcasting.php`).
 *
 * Ese corte previo no es redundante con los callbacks: Laravel compara el patrón con `Str::is` y un
 * `*`, así que `chat.user.{auth_user_id}` también matchea `chat.user.5@otro`, el parámetro llega como
 * `'5@otro'` y `(int) '5@otro'` da 5. Sin el corte, el usuario 5 de cualquier sistema quedaba
 * autorizado al canal del usuario 5 de este.
 *
 * Un canal nuevo se registra acá con su nombre lógico, igual que los de abajo, y su `broadcastOn()`
 * también devuelve el lógico: el sufijo lo pone el broadcaster al emitir.
 */

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Canal privado del módulo de WhatsApp con clientes (grupo 137). Autoriza al dueño
 * (owner_id null cuyo id matchea) y a sus empleados (owner_id === $owner_id) a
 * escuchar los eventos WhatsappChatUpdated de esa empresa.
 */
Broadcast::channel('whatsapp.{owner_id}', function ($user, $owner_id) {
    return (int) $user->id === (int) $owner_id || (int) $user->owner_id === (int) $owner_id;
});

/**
 * Canal privado de los mensajes de la tienda online (misión mensajes-tienda-online, 28/9/2026).
 * Por acá viaja `TiendaChatActualizado`, que emiten tienda-api (el comprador escribió) y
 * empresa-api (el comercio respondió o marcó leído). `owner_id` es el DUEÑO del comercio
 * (`buyers.user_id`). Mismo criterio que `whatsapp.{owner_id}`: el dueño y sus empleados.
 *
 * 🔴 El `(int) $owner_id > 0` va primero y no es decorativo: un dueño tiene `owner_id` null, que
 * castea a 0, así que sin esa guarda cualquier dueño quedaba autorizado a `tienda-mensajes.0`.
 */
Broadcast::channel('tienda-mensajes.{owner_id}', function ($user, $owner_id) {
    return (int) $owner_id > 0
        && ((int) $user->id === (int) $owner_id || (int) $user->owner_id === (int) $owner_id);
});

/**
 * Canal privado del chat con el asistente de IA (misión chat-ia-y-modulo-ia).
 * Autoriza SOLO por id de PERSONA, a propósito SIN la rama de owner_id del
 * canal de WhatsApp: las conversaciones son de cada persona (pueden traer
 * saldos de clientes) y el dueño no escucha las de sus empleados ni al revés.
 */
Broadcast::channel('chat.user.{auth_user_id}', function ($user, $auth_user_id) {
    return (int) $user->id === (int) $auth_user_id;
});
