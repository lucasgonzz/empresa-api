<?php

namespace App\Models;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use Illuminate\Database\Eloquent\Model;

/**
 * Representa un mensaje individual (entrante o saliente) dentro de un `WhatsappChat`.
 * `source` distingue si lo mandó el cliente, un empleado a mano, el agente de IA, salió
 * como plantilla de Meta, o es un mensaje de sistema (ej: comprobante de venta automático).
 */
class WhatsappChatMessage extends Model
{
    protected $guarded = [];

    /**
     * `media_src` viaja SIEMPRE en la serialización (misión whatsapp-sidebar-multimedia).
     *
     * Es appended y no un campo que arme el front a propósito: los adjuntos de un mensaje
     * vienen de dos orígenes con formas distintas (`media_url`, una URL absoluta de un archivo
     * que no es nuestro, y `media_path`, un archivo privado del disco `local` que se sirve por
     * una ruta autenticada). Si el front tuviera que decidir cuál mirar, esa regla quedaría
     * duplicada en la burbuja, en el lightbox y en el reproductor de audio, y encima tendría
     * que concatenar la base de la API a mano — que es exactamente lo que `ApiUrlHelper`
     * existe para que nadie haga (el sufijo `/public` del hosting compartido). Con el appended,
     * la SPA lee un solo campo y nunca arma URLs.
     *
     * @var array
     */
    protected $appends = ['media_src'];

    protected $casts = [
        // Momento en que la respuesta del agente se envía sola si nadie la confirma antes
        // (misión whatsapp-agente). Se castea a Carbon para que el front reciba una fecha
        // ISO y pueda armar el contador regresivo. Null cuando no hay auto-envío pendiente.
        'ai_auto_send_at' => 'datetime',
        // Contador que invalida el job de auto-envío pendiente (misión whatsapp-agente). Lo
        // escribe y lo lee `WhatsappAiAutoSendScheduler` por query builder crudo, no por el
        // modelo; el cast está para que si sale serializado en una respuesta o en un
        // broadcast salga como número y no como el string que devuelve el driver de MySQL.
        'ai_auto_send_token' => 'integer',
        // El mensaje lo inyectó el endpoint de simulación (`simulate-inbound`) o salió como
        // respuesta del agente a uno inyectado; el cliente nunca lo escribió ni lo recibió.
        // Se castea a booleano para que llegue al front y al broadcast como true/false y la
        // conversación lo pueda distinguir de un mensaje real, que es todo el punto de la
        // columna: `direction` y `source` son idénticos a los de un mensaje real a propósito.
        'is_simulated' => 'boolean',
        // Tamaño en bytes de la copia local del adjunto. Se castea a entero porque el driver de
        // MySQL devuelve los enteros como string y el front compara contra los topes de tamaño.
        'media_size' => 'integer',
    ];

    /**
     * URL con la que la SPA dibuja el adjunto: `<img :src="message.media_src">` o
     * `<audio-player :src="message.media_src">`. Es el ÚNICO campo de medio que el front mira.
     *
     * Los dos casos, y por qué no se pueden unificar en la base:
     *
     *  - `media_url` cargado → el archivo NO es nuestro y ya es una URL absoluta y pública: el
     *    PDF del comprobante de venta, o la foto del catálogo que manda el agente
     *    (`images.hosting_url`). Se devuelve tal cual; bajarla y volver a servirla sería pagar
     *    dos veces por un archivo que ya es público a propósito.
     *  - `media_path` cargado → el archivo es una conversación privada y vive en el disco
     *    `local`, fuera del docroot. Se arma la ruta autenticada
     *    (`auth:sanctum` + `check_extencion_empresa:whatsapp` + chequeo de pertenencia).
     *
     * 🔴 La base sale de `ApiUrlHelper::public_base()` y no de `url()` ni de `route()`. El
     * generador de URLs de Laravel no sabe del sufijo `/public` del hosting compartido — es
     * literalmente el problema que `ApiUrlHelper` existe para resolver, y ya rompió el link de
     * WhatsApp del comprobante de venta en producción el 27/7/2026. Si alguien "simplifica"
     * esto a `url('api/whatsapp-chats/...')`, las imágenes se ven rotas SOLO en las
     * instalaciones de hosting compartido, que son las que nadie tiene a mano para probar.
     *
     * @return string|null
     */
    public function getMediaSrcAttribute()
    {
        // Se castea y se trimea porque la columna es `text` nullable y puede tener espacios
        // sobrantes de un caller viejo; un string de espacios no es una URL.
        $media_url = trim((string) $this->media_url);
        if ($media_url !== '') {
            return $media_url;
        }

        $media_path = trim((string) $this->media_path);
        if ($media_path === '') {
            return null;
        }

        // El path NO viaja en la URL: se resuelve en el backend a partir del id del mensaje,
        // así el cliente nunca puede pedir un archivo arbitrario del disco.
        return ApiUrlHelper::public_base()
            . '/api/whatsapp-chats/' . $this->whatsapp_chat_id
            . '/media/' . $this->id;
    }

    /**
     * Relaciones a precargar cuando el controller pide el modelo completo vía
     * `Controller::fullModel()`. Sin este scope, `fullModel()` rompe.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['whatsapp_chat', 'sent_by_user']);
    }

    /**
     * Chat al que pertenece el mensaje.
     */
    public function whatsapp_chat()
    {
        return $this->belongsTo(WhatsappChat::class);
    }

    /**
     * Empleado que envió el mensaje manualmente (aplica a mensajes 'manual'/'plantilla').
     */
    public function sent_by_user()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
