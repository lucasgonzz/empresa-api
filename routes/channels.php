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
 * Canal privado del chat con el asistente de IA (misión chat-ia-y-modulo-ia).
 * Autoriza SOLO por id de PERSONA, a propósito SIN la rama de owner_id del
 * canal de WhatsApp: las conversaciones son de cada persona (pueden traer
 * saldos de clientes) y el dueño no escucha las de sus empleados ni al revés.
 */
Broadcast::channel('chat.user.{auth_user_id}', function ($user, $auth_user_id) {
    return (int) $user->id === (int) $auth_user_id;
});
