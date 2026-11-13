<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\IaImagenPedido;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * LA IMAGEN QUE EL DUEÑO PIDE POR WHATSAPP (misión asistente-truvari-pedidos-fallidos, C1, 13/11/2026).
 *
 * 🔴 EL CASO REAL (Truvari, conversación 4). Fernando, con el paquete de imágenes asignado ese día, le
 * escribió a su asistente por WhatsApp «generá para ese producto una imagen de 6 botellas» y la respuesta
 * fue «andá a ia.comerciocity.com». pedir_imagen existía solo en la app porque el resultado se mostraba
 * únicamente ahí (el bloque `imagen_generada`, que la app consulta cada 8 s).
 *
 * Por WhatsApp no hay bloque que dibujar, así que la imagen VUELVE COMO UN AVISO: cuando el admin la
 * entrega (PedidosDeImagenIaHelper::recibir_resultado) se deja una fila en `ai_avisos_del_asistente` con
 * el tipo TIPO_IMAGEN_POR_WHATSAPP, y AvisosDelAsistenteHelper la sirve por el camino que el admin YA
 * consulta cada minuto (GET admin-sync/asistente/avisos-pendientes, con `url` = el link público de la
 * imagen), dentro de la ventana de 24 h que el dueño acaba de abrir al pedirla. Sin cambios en el admin:
 * su AsistenteAvisosService manda `texto` + "\n\n" + `url` para cualquier tipo que no sea
 * `importacion_lista` (ver el informe del bloque C).
 *
 * 🔴 SOLO SE OFRECE SI SE PUEDE CUMPLIR (disponible()): la misma condición que hasta hoy daba la dirección
 * de la app (la extensión Agentes de IA y una persona que puede usar el asistente: IaAppHelper::
 * puede_usar_el_asistente) MÁS que el admin de este dueño haya anunciado que consulta los avisos
 * (AvisosDelAsistenteHelper::admin_avisa). Sin ese anuncio, la imagen no le llegaría nunca y «te la mando
 * por acá» sería una promesa rota: queda el texto de antes, que manda a la app.
 *
 * 🔴 EL LINK ES PÚBLICO Y NO SE ADIVINA. El archivo del resultado vive en el disco `local` (privado, se sirve
 * con sesión por GET ia-imagenes/{uuid}); WhatsApp necesita un link que abra sin sesión. Se copia al disco
 * `public` con un nombre que es un HMAC del uuid con la APP_KEY (no se deduce del uuid ni de otra imagen) y
 * en png o jpg, que WhatsApp sabe abrir: si llegó en webp y hay GD con webp, se convierte a png. Es una
 * imagen del negocio pensada para publicarse (una placa, una foto de producto), no un medio de una
 * conversación privada: esos siguen fuera del docroot (ver WhatsappChatController).
 *
 * También avisa cuando NO se pudo (fallida, tope, no disponible): el turno ya le dijo «te la mando por acá».
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class ImagenPorWhatsappIaHelper
{
    /** Carpeta del disco `public` donde quedan las copias que se mandan por WhatsApp. */
    const CARPETA_PUBLICA = 'ia_imagenes_whatsapp';

    /** Largo máximo de la descripción de la imagen que se cita en el aviso. */
    const LARGO_DE_LA_CITA = 80;

    /** Lo que se le dice al modelo al pedir la imagen por WhatsApp (va en lugar de «aparece acá»). */
    const FRASE_PARA_EL_DUENO = 'la pedí; te la mando por acá cuando esté lista';

    /**
     * true si esta conversación de WhatsApp puede pedir imágenes: la extensión, una persona que puede usar
     * el asistente, el anuncio de avisos del admin y las dos tablas. Nunca lanza.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  mixed  $owner
     * @return bool
     */
    public static function disponible(AiConversation $conversation, $owner): bool
    {
        try {

            if (!($owner instanceof User) || !AsistenteCanalHelper::tiene_extension($owner)) {

                return false;
            }

            $persona = User::find($conversation->auth_user_id);

            if (!($persona instanceof User) || !IaAppHelper::puede_usar_el_asistente($persona)) {

                return false;
            }

            if (!AvisosDelAsistenteHelper::admin_avisa((int) $owner->id)) {

                return false;
            }

            return AvisosDeTurnosIaHelper::hay_tabla() && ColumnasDeLaAppIaHelper::hay_tabla('ia_imagen_pedidos');

        } catch (\Throwable $e) {

            return false;
        }
    }

    /**
     * true si el pedido nació de un mensaje de WhatsApp.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @return bool
     */
    public static function es_de_whatsapp(IaImagenPedido $pedido): bool
    {
        if (empty($pedido->ai_message_id)) {

            return false;
        }

        $mensaje = AiMessage::find((int) $pedido->ai_message_id);

        return $mensaje instanceof AiMessage && $mensaje->es_de_whatsapp();
    }

    /**
     * Deja el aviso de la imagen para que salga por WhatsApp, si el pedido es de ese canal. Uno por pedido
     * (un reintento del admin no deja dos). Con la imagen lista, la publica primero; si no se pudo publicar,
     * el aviso sale igual, sin link y diciendo que la ve en la app. Nunca lanza: el resultado del admin ya
     * quedó guardado y esto no lo puede voltear.
     *
     * @param  \App\Models\IaImagenPedido  $pedido  Con su estado final (lista, fallida o no_disponible).
     * @param  string|null  $para_el_dueno  El motivo para el dueño cuando no se pudo (el del tope, por ejemplo).
     * @return \App\Models\AiAvisoDelAsistente|null
     */
    public static function avisar(IaImagenPedido $pedido, $para_el_dueno = null)
    {
        try {

            if (!AvisosDeTurnosIaHelper::hay_tabla() || !self::es_de_whatsapp($pedido)) {

                return null;
            }

            $mensaje = AiMessage::find((int) $pedido->ai_message_id);

            $conversation = AiConversation::find((int) $mensaje->ai_conversation_id);

            if (is_null($conversation) || !AvisosDelAsistenteHelper::admin_avisa((int) $conversation->user_id)) {

                return null;
            }

            $ya = AiAvisoDelAsistente::where('tipo', AiAvisoDelAsistente::TIPO_IMAGEN_POR_WHATSAPP)
                                    ->where('uuid', (string) $pedido->uuid)
                                    ->first();

            if (!is_null($ya)) {

                return $ya;
            }

            $publicada = $pedido->estado === IaImagenPedido::ESTADO_LISTA ? self::publicar($pedido) : null;

            /* «Tocá el link» solo si de verdad va a haber link (revisión independiente, D6). */
            $con_link = !is_null($publicada) && !is_null(self::url_publica((string) $pedido->uuid, (int) $pedido->user_id));

            return AiAvisoDelAsistente::create([
                'uuid'               => (string) $pedido->uuid,
                'owner_id'           => (int) $conversation->user_id,
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $mensaje->id,
                'tipo'               => AiAvisoDelAsistente::TIPO_IMAGEN_POR_WHATSAPP,
                'texto'              => self::texto($pedido, $con_link, $para_el_dueno),
            ]);

        } catch (\Throwable $e) {

            Log::warning('ImagenPorWhatsappIaHelper: no se pudo dejar el aviso de la imagen', [
                'ia_imagen_pedido_id' => (int) $pedido->id,
                'error'               => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * El texto del aviso, SIN el link (el admin lo agrega abajo, desde `url`).
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @param  bool  $con_link
     * @param  string|null  $para_el_dueno
     * @return string
     */
    public static function texto(IaImagenPedido $pedido, $con_link, $para_el_dueno = null): string
    {
        $de_que = self::de_que($pedido);

        if ($pedido->estado === IaImagenPedido::ESTADO_LISTA) {

            if (!$con_link) {

                return 'Ya está la imagen que me pediste' . $de_que . ', pero no pude prepararte el link para verla por acá. '
                    . 'La tenés en la app ComercioCity IA, en esta misma conversación.';
            }

            return '🖼️ Ya está la imagen que me pediste' . $de_que . '. Tocá el link para verla y guardarla.';
        }

        $motivo = trim((string) $para_el_dueno);

        if ($motivo === '') {

            $motivo = $pedido->estado === IaImagenPedido::ESTADO_NO_DISPONIBLE
                ? PedidosDeImagenIaHelper::NO_DISPONIBLE_PARA_EL_DUENO
                : PedidosDeImagenIaHelper::ERROR_PARA_EL_DUENO;
        }

        return 'No pude generar la imagen que me pediste' . $de_que . '. ' . $motivo;
    }

    /**
     * " (Aguma Carmenere)" o " («placa con la promo…»)": de qué imagen se habla, o vacío.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @return string
     */
    protected static function de_que(IaImagenPedido $pedido): string
    {
        if (!empty($pedido->article_id)) {

            $articulo = Article::withTrashed()->find((int) $pedido->article_id);

            if ($articulo instanceof Article && trim((string) $articulo->name) !== '') {

                return ' de *' . trim(str_replace('*', '', (string) $articulo->name)) . '*';
            }
        }

        /* El prompt puede traer la línea de la cantidad que suma pedir(): se cita lo que pidió el modelo. */
        $prompt = trim((string) preg_replace('/^Cantidad exacta de unidades a mostrar: \d+\.\s*/u', '', (string) $pedido->prompt));
        $prompt = trim((string) preg_replace('/\s+/u', ' ', str_replace('*', '', $prompt)));

        if ($prompt === '') {

            return '';
        }

        if (mb_strlen($prompt) > self::LARGO_DE_LA_CITA) {

            $prompt = rtrim(mb_substr($prompt, 0, self::LARGO_DE_LA_CITA - 1)) . '…';
        }

        return ' («' . $prompt . '»)';
    }

    /**
     * El nombre en el disco `public` de la copia de un pedido, sin extensión: no se deduce del uuid.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @return string
     */
    protected static function base_publica(IaImagenPedido $pedido): string
    {
        $clave = (string) config('app.key');

        return self::CARPETA_PUBLICA . '/' . (int) $pedido->user_id . '/' . hash_hmac('sha256', 'ia-imagen-whatsapp:' . (string) $pedido->uuid, $clave === '' ? 'comerciocity' : $clave);
    }

    /**
     * Publica la imagen lista en el disco `public` (png o jpg) y devuelve su ruta en ese disco, o null si no
     * se pudo (sin archivo, formato raro, disco sin permiso).
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @return string|null
     */
    public static function publicar(IaImagenPedido $pedido)
    {
        /*
         * 🔴 NUNCA LANZA (revisión independiente, D6): corre adentro de avisar(), y un disco sin permiso o un
         * archivo ilegible dejaban al dueño sin ningún aviso. Si no se puede publicar, el aviso sale igual, sin
         * link y diciendo dónde la ve.
         */
        try {

            $existente = self::ruta_publica($pedido);

            if (!is_null($existente)) {

                return $existente;
            }

            if (empty($pedido->path) || !Storage::disk('local')->exists((string) $pedido->path)) {

                return null;
            }

            $binario = (string) Storage::disk('local')->get((string) $pedido->path);
            $mime = AsistenteImagenHelper::media_type($binario);

            if ($mime === 'image/webp') {

                $binario = self::webp_a_png($binario);
                $mime = is_null($binario) ? null : 'image/png';
            }

            if ($mime !== 'image/png' && $mime !== 'image/jpeg') {

                return null;
            }

            $ruta = self::base_publica($pedido) . ($mime === 'image/png' ? '.png' : '.jpg');

            return Storage::disk('public')->put($ruta, $binario) ? $ruta : null;

        } catch (\Throwable $e) {

            Log::warning('ImagenPorWhatsappIaHelper: no se pudo publicar la imagen; el aviso sale sin link', [
                'ia_imagen_pedido_id' => (int) $pedido->id,
                'error'               => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * La ruta en el disco `public` de la copia ya publicada, o null.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @return string|null
     */
    public static function ruta_publica(IaImagenPedido $pedido)
    {
        $base = self::base_publica($pedido);

        foreach (['.png', '.jpg'] as $extension) {

            if (Storage::disk('public')->exists($base . $extension)) {

                return $base . $extension;
            }
        }

        return null;
    }

    /**
     * El link público de la imagen de un pedido, o null si no está publicada.
     *
     * @param  string  $uuid
     * @param  int  $owner_id
     * @return string|null
     */
    public static function url_publica($uuid, $owner_id)
    {
        $pedido = IaImagenPedido::where('uuid', (string) $uuid)->where('user_id', (int) $owner_id)->first();

        if (is_null($pedido) || $pedido->estado !== IaImagenPedido::ESTADO_LISTA) {

            return null;
        }

        $ruta = self::ruta_publica($pedido);

        if (is_null($ruta)) {

            return null;
        }

        return AvisosDelAsistenteHelper::url_segura(ApiUrlHelper::storage($ruta));
    }

    /**
     * Un webp a png con GD, o null si GD no lee webp en esta instalación.
     *
     * @param  string  $binario
     * @return string|null
     */
    protected static function webp_a_png($binario)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {

            return null;
        }

        $imagen = @imagecreatefromstring($binario);

        if ($imagen === false) {

            return null;
        }

        ob_start();
        $ok = imagepng($imagen);
        $png = ob_get_clean();
        imagedestroy($imagen);

        return $ok && is_string($png) && $png !== '' ? $png : null;
    }
}
