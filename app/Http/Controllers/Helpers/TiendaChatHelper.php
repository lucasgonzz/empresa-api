<?php

namespace App\Http\Controllers\Helpers;

use App\Events\TiendaChatActualizado;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Todo lo que el submódulo "Mensajes" de Tienda Online necesita armar a mano (misión
 * mensajes-tienda-online, 28/9/2026): el payload del evento en vivo (contrato C1), las formas
 * `Chat`, `Mensaje` y `Buyer` de la API HTTP (contrato C3), la emisión del evento sin que una
 * caída de Pusher toque nada, y la regla de "un mail cada 30 minutos" (decisión 2 de Lucas).
 *
 * 🔴 Todo se arma campo por campo, con los tipos del contrato, y NUNCA con `$model->toArray()`:
 *
 * - `messages` no tiene casts, así que `from_buyer` y `read` salen de la base como 0/1, y en JS un
 *   `"0"` es verdadero. El contrato los fija booleanos.
 * - Tras un `Message::create([...])` sin `from_buyer`, el atributo no existe en el modelo: el
 *   default lo pone MySQL y el modelo en memoria no se entera. Por eso quien crea un mensaje acá
 *   pasa los tres (`from_buyer`, `read`, `type`) explícitos, y además acá se castea todo.
 * - Las fechas van con `->toJSON()` de Carbon: la misma forma que la serialización estándar de los
 *   modelos (`2026-09-28T14:05:11.000000Z`), así la SPA las trata igual que cualquier `created_at`.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class TiendaChatHelper
{
    /**
     * Largo máximo del texto que viaja en el evento, en CARACTERES (contrato C1, corregido el
     * 28/9/2026: era 2000).
     *
     * El límite de Pusher es 10 KB por evento, y el SDK codifica la data con `json_encode` sin
     * `JSON_UNESCAPED_UNICODE`: cada acento sale como `\u00e1` (6 bytes) y cada emoji como un par
     * de escapes `\ud83d\ude00` (12 bytes). Con 2000 caracteres acentuados el evento medía
     * 12.437 bytes y Pusher lo rechazaba. Con 500, el peor caso (500 emojis) queda en ~6,5 KB
     * contando el resto del payload. Si se recorta, `text_truncado` va en true y la SPA pide la
     * página 1 en silencio. tienda-api recorta igual: los dos emisores tienen que coincidir.
     */
    const LARGO_MAXIMO_TEXTO_EVENTO = 500;

    /** Largo del texto del último mensaje en una fila de la bandeja (`Chat.last_message.text`). */
    const LARGO_TEXTO_EN_BANDEJA = 200;

    /** Minutos que tienen que pasar entre dos mails al comprador por la misma conversación. */
    const MINUTOS_ENTRE_MAILS = 30;

    // -------------------------------------------------------------------------------------------
    //  El evento en vivo (C1)
    // -------------------------------------------------------------------------------------------

    /**
     * Emite `TiendaChatActualizado` al canal del dueño. Nunca tira: el mensaje ya está guardado
     * (o la conversación ya se marcó leída) cuando se llama a esto, y un 502 de Pusher no puede
     * convertirse en un 500 sobre una operación ya aplicada.
     *
     * El log va en su propio try/catch por el mismo motivo que en `BroadcastOrderCreated` de
     * tienda-api: el catch no está cubierto por el try, y un logger que falla (disco lleno,
     * permisos de storage/logs) haría escapar la excepción igual.
     *
     * @param  int  $owner_id  Dueño del comercio.
     * @param  \App\Models\Buyer  $buyer
     * @param  \App\Models\Message|null  $message  null en el evento de "leído".
     * @param  int|null  $unread_count  Si se sabe (en "leído" es 0), se evita la consulta.
     * @return bool  true si el evento salió; false si algo falló y quedó en el log.
     */
    public static function emitir($owner_id, $buyer, $message = null, $unread_count = null)
    {
        try {
            $payload = self::payload_del_evento($buyer, $message, $unread_count);

            event(new TiendaChatActualizado((int) $owner_id, $payload));

            return true;
        } catch (\Throwable $e) {
            try {
                Log::warning('TiendaChatHelper: no se pudo emitir TiendaChatActualizado, el mensaje igual quedó guardado.', [
                    'owner_id'   => $owner_id,
                    'buyer_id'   => $buyer ? $buyer->id : null,
                    'message_id' => $message ? $message->id : null,
                    'error'      => $e->getMessage(),
                ]);
            } catch (\Throwable $e_log) {
                // Si ni siquiera se puede loguear, lo único que importa es que muera acá adentro.
            }

            return false;
        }
    }

    /**
     * El payload del contrato C1, idéntico al que arma tienda-api:
     *
     * `{ buyer_id, chat: {buyer_id, unread_count, last_message_at}, buyer: {...}|null, message: {...}|null }`
     *
     * - Con `message`: `last_message_at` es el `created_at` de ese mensaje (es el último).
     * - Sin `message` (evento de "leído"): `last_message_at` es el del último mensaje del comprador
     *   por id (una consulta), así la fila de la bandeja no pierde su hora.
     *
     * @param  \App\Models\Buyer  $buyer
     * @param  \App\Models\Message|null  $message
     * @param  int|null  $unread_count  null = se cuenta con una consulta.
     * @return array
     */
    public static function payload_del_evento($buyer, $message = null, $unread_count = null)
    {
        $buyer_id = (int) $buyer->id;

        if (is_null($unread_count)) {
            $unread_count = self::unread_count($buyer_id);
        }

        if (!is_null($message)) {
            $last_message_at = self::fecha($message->created_at);
        } else {
            $ultimo = Message::where('buyer_id', $buyer_id)
                                ->orderBy('id', 'DESC')
                                ->first(['id', 'created_at']);
            $last_message_at = $ultimo ? self::fecha($ultimo->created_at) : null;
        }

        return [
            'buyer_id' => $buyer_id,
            'chat'     => [
                'buyer_id'        => $buyer_id,
                'unread_count'    => (int) $unread_count,
                'last_message_at' => $last_message_at,
            ],
            'buyer'    => self::comprador_del_evento($buyer),
            'message'  => is_null($message) ? null : self::mensaje_del_evento($message),
        ];
    }

    /**
     * Mensajes del comprador sin leer por el comercio (`from_buyer = 1 AND read = 0`). Una sola
     * consulta `COUNT`, sobre el índice de `messages.buyer_id`.
     *
     * @param  int  $buyer_id
     * @return int
     */
    public static function unread_count($buyer_id)
    {
        return (int) Message::where('buyer_id', $buyer_id)
                                ->where('from_buyer', 1)
                                ->where('read', 0)
                                ->count();
    }

    /**
     * `buyer` del evento: `{id, name, surname, email, phone}`.
     *
     * @param  \App\Models\Buyer  $buyer
     * @return array
     */
    protected static function comprador_del_evento($buyer)
    {
        return [
            'id'      => (int) $buyer->id,
            'name'    => $buyer->name,
            'surname' => $buyer->surname,
            'email'   => $buyer->email,
            'phone'   => $buyer->phone,
        ];
    }

    /**
     * `message` del evento, con el texto recortado a 500 caracteres.
     *
     * @param  \App\Models\Message  $message
     * @return array
     */
    protected static function mensaje_del_evento($message)
    {
        $text = (string) $message->text;
        $text_truncado = mb_strlen($text) > self::LARGO_MAXIMO_TEXTO_EVENTO;

        if ($text_truncado) {
            $text = mb_substr($text, 0, self::LARGO_MAXIMO_TEXTO_EVENTO);
        }

        return [
            'id'            => (int) $message->id,
            'buyer_id'      => (int) $message->buyer_id,
            'user_id'       => self::entero_o_null($message->user_id),
            'text'          => $text,
            'text_truncado' => $text_truncado,
            'type'          => $message->type,
            'from_buyer'    => (bool) $message->from_buyer,
            'read'          => (bool) $message->read,
            'article_id'    => self::entero_o_null($message->article_id),
            'order_id'      => self::entero_o_null($message->order_id),
            'created_at'    => self::fecha($message->created_at),
        ];
    }

    // -------------------------------------------------------------------------------------------
    //  Las formas de la API HTTP (C3)
    // -------------------------------------------------------------------------------------------

    /**
     * `Buyer` de la API: `{id, name, surname, email, phone, comercio_city_client_id,
     * comercio_city_client: {id, name}|null}`. Espera la relación `comercio_city_client` cargada
     * (si no lo está, Eloquent la carga: una consulta).
     *
     * @param  \App\Models\Buyer  $buyer
     * @return array
     */
    public static function comprador_para_la_spa($buyer)
    {
        $cliente = $buyer->comercio_city_client;

        return [
            'id'                      => (int) $buyer->id,
            'name'                    => $buyer->name,
            'surname'                 => $buyer->surname,
            'email'                   => $buyer->email,
            'phone'                   => $buyer->phone,
            'comercio_city_client_id' => self::entero_o_null($buyer->comercio_city_client_id),
            'comercio_city_client'    => $cliente ? ['id' => (int) $cliente->id, 'name' => $cliente->name] : null,
        ];
    }

    /**
     * `Mensaje` de la API: `{id, buyer_id, user_id, text, type, from_buyer: bool, read: bool,
     * article_id, order_id, created_at, article: {id, name, slug, image_url}|null}`.
     *
     * El texto va completo (el recorte es solo del evento). `article` es liviano: la foto es la
     * primera (la marcada con `first`, o la primera) resuelta a URL pública absoluta por
     * `ArticleHelper::primera_imagen_publica()`, la misma que usa el asistente para pintarla en la
     * SPA. Nada de `colors/sizes/questions`. Espera `article.images` cargado.
     *
     * @param  \App\Models\Message  $message
     * @return array
     */
    public static function mensaje_para_la_spa($message)
    {
        $article = $message->article_id ? $message->article : null;

        return [
            'id'         => (int) $message->id,
            'buyer_id'   => (int) $message->buyer_id,
            'user_id'    => self::entero_o_null($message->user_id),
            'text'       => $message->text,
            'type'       => $message->type,
            'from_buyer' => (bool) $message->from_buyer,
            'read'       => (bool) $message->read,
            'article_id' => self::entero_o_null($message->article_id),
            'order_id'   => self::entero_o_null($message->order_id),
            'created_at' => self::fecha($message->created_at),
            'article'    => $article ? [
                'id'        => (int) $article->id,
                'name'      => $article->name,
                'slug'      => $article->slug,
                'image_url' => ArticleHelper::primera_imagen_publica($article),
            ] : null,
        ];
    }

    /**
     * `last_message` de una fila de la bandeja: `{id, text (recortado a 200), from_buyer: bool,
     * read: bool, type, created_at}`.
     *
     * @param  \App\Models\Message  $message
     * @return array
     */
    public static function ultimo_mensaje_para_la_bandeja($message)
    {
        return [
            'id'         => (int) $message->id,
            'text'       => mb_substr((string) $message->text, 0, self::LARGO_TEXTO_EN_BANDEJA),
            'from_buyer' => (bool) $message->from_buyer,
            'read'       => (bool) $message->read,
            'type'       => $message->type,
            'created_at' => self::fecha($message->created_at),
        ];
    }

    // -------------------------------------------------------------------------------------------
    //  El mail al comprador (decisión 2 de Lucas)
    // -------------------------------------------------------------------------------------------

    /**
     * ¿Corresponde mandarle mail al comprador por esta respuesta del comercio?
     *
     * Sí, salvo que el comercio le haya escrito a ese comprador (`from_buyer = 0`, cualquier tipo)
     * en los últimos 30 minutos, sin contar este mensaje. Es "como mucho un mail cada 30 minutos
     * por conversación": una charla de diez mensajes seguidos no le llena la casilla.
     *
     * Se decide en el request, antes de responder, y viaja al Job como un booleano: así el Job no
     * depende de cuándo corre, y dos respuestas mandadas con un segundo de diferencia no pueden
     * decidir las dos "sí" (la segunda ya ve a la primera guardada).
     *
     * @param  \App\Models\Message  $message  El mensaje del comercio recién guardado.
     * @return bool
     */
    public static function corresponde_mail($message)
    {
        $desde = Carbon::now()->subMinutes(self::MINUTOS_ENTRE_MAILS);

        return !Message::where('buyer_id', $message->buyer_id)
                        ->where('from_buyer', 0)
                        ->where('id', '!=', $message->id)
                        ->where('created_at', '>=', $desde)
                        ->exists();
    }

    /**
     * Asunto del mail al comprador: "{company_name del dueño} respondió tu mensaje".
     *
     * @param  \App\Models\User|null  $commerce  El dueño del comercio.
     * @return string
     */
    public static function titulo_del_mail($commerce)
    {
        $nombre = null;

        if (!is_null($commerce)) {
            $nombre = trim((string) $commerce->company_name) !== '' ? $commerce->company_name : $commerce->name;
        }

        if (is_null($nombre) || trim((string) $nombre) === '') {
            $nombre = 'El comercio';
        }

        return $nombre.' respondió tu mensaje';
    }

    // -------------------------------------------------------------------------------------------
    //  Tipos
    // -------------------------------------------------------------------------------------------

    /**
     * Fecha serializada igual que cualquier `created_at` de un modelo, o null.
     *
     * @param  mixed  $fecha  Carbon, string o null.
     * @return string|null
     */
    public static function fecha($fecha)
    {
        if (is_null($fecha) || $fecha === '') {
            return null;
        }

        if (!($fecha instanceof Carbon)) {
            $fecha = Carbon::parse($fecha);
        }

        return $fecha->toJSON();
    }

    /**
     * @param  mixed  $valor
     * @return int|null
     */
    public static function entero_o_null($valor)
    {
        return is_null($valor) ? null : (int) $valor;
    }
}
