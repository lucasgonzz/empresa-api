<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\Article;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappAiAutoSendScheduler;
use App\Services\WhatsappBotAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera la respuesta del agente de WhatsApp después de la demora configurada, y solo si
 * mientras tanto el cliente no siguió escribiendo (debounce por token, ver
 * `WhatsappAgentScheduler`).
 *
 * Hasta esta misión esto corría inline adentro del request del webhook de Kapso: la llamada
 * a Anthropic (y antes el RAG, que en MySQL carga todos los artículos en memoria) se comía
 * varios segundos del pedido HTTP que Kapso espera contestado. Ahora vive acá.
 */
class GenerateWhatsappAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tope de caracteres del texto para que la respuesta pueda viajar como epígrafe de la
     * imagen (D20).
     *
     * Meta corta el `caption` de una imagen en 1024 caracteres, y no lo trunca: rechaza el
     * mensaje entero. Los 1000 dejan margen y se miden sobre el texto YA limpio de marcador,
     * que es exactamente lo que viaja como epígrafe. Arriba de ese tope la respuesta sale como
     * texto plano y sin foto: perder la foto es muchísimo más barato que perder la respuesta.
     */
    private const PHOTO_CAPTION_LIMIT = 1000;

    /**
     * @var int ID del chat al que hay que responder.
     */
    private $chat_id;

    /**
     * @var int Token de debounce; tiene que seguir siendo el vigente al ejecutarse.
     */
    private $schedule_token;

    /**
     * @param int $chat_id
     * @param int $schedule_token
     */
    public function __construct(int $chat_id, int $schedule_token)
    {
        $this->chat_id = $chat_id;
        $this->schedule_token = $schedule_token;
    }

    /**
     * Genera la respuesta si el token sigue vigente y todavía hay algo sin contestar.
     *
     * @param WhatsappAgentScheduler $scheduler Inyectado por el container.
     *
     * @return void
     */
    public function handle(WhatsappAgentScheduler $scheduler): void
    {
        try {
            // ESTO ES LA AGRUPACIÓN: de los tres jobs que despacharon los tres mensajes
            // seguidos del cliente, solo el último trae el token vigente. Los dos primeros
            // se auto-descartan acá sin gastar una llamada a Anthropic.
            if (! $scheduler->is_token_current($this->chat_id, $this->schedule_token)) {
                Log::channel('daily')->debug('GenerateWhatsappAiReplyJob: omitido (token de debounce obsoleto).', [
                    'chat_id'        => $this->chat_id,
                    'schedule_token' => $this->schedule_token,
                ]);

                return;
            }

            $chat = WhatsappChat::find($this->chat_id);
            if (is_null($chat)) {
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: chat no encontrado.', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
            if (is_null($config)) {
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: sin configuración de WhatsApp para la empresa.', [
                    'chat_id' => $this->chat_id,
                    'user_id' => $chat->user_id,
                ]);

                return;
            }

            // Se vuelve a chequear porque durante la espera pudieron apagar el bot de la
            // empresa o la IA de este chat puntual.
            if (! $config->is_active || ! $chat->ai_enabled) {
                Log::channel('daily')->debug('GenerateWhatsappAiReplyJob: omitido (bot inactivo o IA apagada en el chat).', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            // Guard de la ventana de 24 h de Meta: con una demora configurada larga (o con la
            // cola atrasada) la ventana se pudo cerrar entre el mensaje entrante y este job.
            // Mandar texto libre fuera de ventana falla en Meta, así que ni se genera.
            if (! $chat->is_within_service_window()) {
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: omitido (ventana de 24 h cerrada).', [
                    'chat_id'         => $this->chat_id,
                    'last_inbound_at' => (string) $chat->last_inbound_at,
                ]);

                return;
            }

            if (! $this->has_unanswered_inbound($chat)) {
                Log::channel('daily')->debug('GenerateWhatsappAiReplyJob: omitido (no hay mensajes del cliente sin responder).', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            // Se pide la variante con foto porque este es el ÚNICO consumidor que puede hacer
            // algo con el código de barras marcado. El texto que devuelve ya viene sin el
            // marcador: el recorte lo hace el service, que es el único que produce el texto, y
            // así ningún otro consumidor (hoy `suggest()`, mañana lo que aparezca) puede
            // filtrarlo.
            $respuesta = (new WhatsappBotAiService())->generate_response_with_photo($chat, $config);
            $texto     = $respuesta['body'];

            // El corte es contra la respuesta ENTERA, cuerpo y marcador: si el modelo contestó
            // solamente `[FOTO:123]`, el cuerpo limpio queda vacío pero la respuesta no lo
            // estaba, y esa sí hay que seguir procesándola (más abajo se ve si el producto se
            // pudo resolver). Mirando solo `$texto` se descartaría acá una respuesta con foto
            // válida.
            if ($texto === '' && $respuesta['bar_code'] === '') {
                // Mismo comportamiento que tenía el controller: si la IA no devolvió nada
                // (error HTTP, sin API key, historial vacío) no se manda ni se persiste nada.
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: respuesta IA vacía, no se persiste nada.', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            // 🔴 Re-chequeo del token DESPUÉS de la llamada a Anthropic, y no es opcional: la
            // API tarda segundos y el cliente puede haber mandado otro mensaje mientras tanto.
            // Si pasó eso, esta respuesta ya está desactualizada — se tira y el job del token
            // nuevo va a generar una que sí contemple todo lo que escribió.
            if (! $scheduler->is_token_current($this->chat_id, $this->schedule_token)) {
                Log::channel('daily')->info('GenerateWhatsappAiReplyJob: respuesta descartada (llegaron mensajes nuevos mientras respondía la IA).', [
                    'chat_id'        => $this->chat_id,
                    'schedule_token' => $this->schedule_token,
                ]);

                return;
            }

            // La foto del producto se resuelve ACÁ ABAJO y no apenas llega la respuesta, aunque
            // el código de barras ya estuviera en la mano: entre las dos posiciones está el
            // re-chequeo del token, que descarta la respuesta entera si el cliente siguió
            // escribiendo. Resolviéndola antes, cada respuesta descartada se llevaría puesta una
            // query al catálogo para nada. Después del re-chequeo, todo lo que se consulta se usa.
            $extra = $this->resolve_product_photo($chat, $texto, $respuesta['bar_code']);

            if ($texto === '' && empty($extra)) {
                // Caso de borde real: el modelo contestó SOLO el marcador y encima no se pudo
                // resolver el producto. Con el marcador sacado no queda nada que decir, y una
                // fila con el cuerpo vacío se le dibujaría al operador como una burbuja en
                // blanco esperando que la confirme. Mismo criterio que la respuesta vacía de
                // más arriba: no se persiste nada.
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: la respuesta era solo el marcador de foto y el producto no se pudo resolver, no se persiste nada.', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            $message = WhatsappChatHelper::store_pending_ai_message($chat, $texto, $extra);

            // El auto-envío decide si sale al instante (ai_confirm_delay_seconds = 0) o si
            // espera la confirmación de una persona.
            (new WhatsappAiAutoSendScheduler())->schedule_for_message($message, $config);
        } catch (\Throwable $exception) {
            // No se re-lanza: el job no declara $tries, así que re-lanzar solo lo marcaría
            // como fallido sin reintentar nada. Todo lo caro (Anthropic, Kapso) ya tiene su
            // propio manejo de errores adentro de los services.
            Log::channel('daily')->error('GenerateWhatsappAiReplyJob: excepción al generar la respuesta del agente.', [
                'chat_id' => $this->chat_id,
                'error'   => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Resuelve la foto del producto que el agente marcó con `[FOTO:<código de barras>]`, para
     * que la respuesta salga como UNA imagen con epígrafe en lugar de dos mensajes sueltos (D20).
     *
     * 🔴 ACÁ NO SE RECORTA NADA, Y NO ES UN OLVIDO: el marcador ya vino sacado del texto por
     * `WhatsappBotAiService::generate_response_with_photo()`, que es el único lugar que produce
     * la respuesta y por eso el único que puede garantizar que ningún consumidor la vea con el
     * marcador puesto. Este método recibe el cuerpo ya limpio y el código por separado, así que
     * decida lo que decida —que el artículo no existe, que no tiene foto, que el texto es
     * larguísimo, que se cayó la base— el texto que se persiste sale limpio igual. La
     * degradación de esta funcionalidad es "sale como texto normal", NUNCA "sale con el marcador
     * a la vista": es basura de implementación y quien la leería es el cliente del negocio.
     *
     * Si alguien vuelve a meter el recorte acá adentro, que es lo que hacía antes, el marcador
     * se le filtra otra vez al otro consumidor del service (hoy el botón "Sugerir respuesta")
     * y encima nadie se entera, porque el agente automático sigue andando igual de bien.
     *
     * Ningún camino de falla lanza: todos devuelven un array vacío de columnas, y el `catch`
     * final está para lo que no se previó (la query, el modelo, el disco). Un problema con la
     * foto no puede costar la respuesta.
     *
     * El artículo se busca acotado a `user_id` del chat y no solo por `bar_code`: el código de
     * barras se repite entre negocios (es del fabricante, no del comercio), así que sin ese
     * filtro un cliente podría terminar viendo la foto que cargó otra empresa del sistema.
     *
     * @param WhatsappChat $chat     Chat que se está respondiendo; de acá sale el dueño del catálogo.
     * @param string       $texto    Cuerpo de la respuesta YA sin marcador; es lo que va a viajar
     *                               como epígrafe, y por eso es lo que se mide contra el tope.
     * @param string       $bar_code Código que marcó el agente, o cadena vacía si no marcó ninguno.
     *
     * @return array Columnas de medio para `store_pending_ai_message()`. Vacío = la respuesta
     *               sale como texto.
     */
    private function resolve_product_photo(WhatsappChat $chat, string $texto, string $bar_code): array
    {
        // Sin marcador no hay nada que resolver: es el 100% de las respuestas de antes de esta
        // misión y tienen que seguir saliendo byte por byte iguales.
        if ($bar_code === '') {
            return [];
        }

        try {
            // El tope se mide en caracteres y no en bytes porque es lo que cuenta Meta, y una
            // respuesta en español lleva acentos: con `strlen` el mismo texto mediría más de lo
            // que mide para ellos y se perderían fotos que entraban bien.
            if (mb_strlen($texto, 'UTF-8') > self::PHOTO_CAPTION_LIMIT) {
                Log::channel('daily')->info('GenerateWhatsappAiReplyJob: respuesta demasiado larga para epígrafe, la foto del producto no se adjunta.', [
                    'chat_id'    => $this->chat_id,
                    'caracteres' => mb_strlen($texto, 'UTF-8'),
                ]);

                return [];
            }

            $article = Article::where('user_id', $chat->user_id)
                ->where('bar_code', $bar_code)
                ->with('images')
                ->first();

            if (is_null($article)) {
                // Pasa y no es un error del sistema: el modelo puede inventar un código, o
                // copiar mal uno del catálogo. Se loguea en info porque lo que importa es poder
                // contar cuán seguido el agente marca un producto que no existe.
                Log::channel('daily')->info('GenerateWhatsappAiReplyJob: el agente marcó una foto de un artículo que no existe en el catálogo del negocio.', [
                    'chat_id'  => $this->chat_id,
                    'bar_code' => $bar_code,
                ]);

                return [];
            }

            // `hosting_url` es una URL pública absoluta del catálogo (la misma que ve cualquiera
            // en la tienda del negocio), y por eso esta es la única foto de todo el módulo que
            // sale por `link` en vez de subirse por multipart (D1): bajarla para volver a
            // subirla sería pagar dos viajes por un archivo que ya es público a propósito.
            $image       = $article->images->first();
            $hosting_url = is_null($image) ? '' : trim((string) $image->hosting_url);

            if ($hosting_url === '') {
                Log::channel('daily')->info('GenerateWhatsappAiReplyJob: el artículo marcado no tiene foto cargada, la respuesta sale como texto.', [
                    'chat_id'    => $this->chat_id,
                    'bar_code'   => $bar_code,
                    'article_id' => $article->id,
                ]);

                return [];
            }

            return [
                'media_type' => 'image',
                'media_url'  => $hosting_url,
            ];
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('GenerateWhatsappAiReplyJob: excepción al resolver la foto del producto, la respuesta sale como texto.', [
                'chat_id' => $this->chat_id,
                'error'   => $exception->getMessage(),
            ]);
        }

        // Solo se llega acá desde el `catch`: cualquier cosa que no se previó deja la respuesta
        // saliendo como texto, que ya está limpio desde el service.
        return [];
    }

    /**
     * Indica si el último mensaje del chat es uno entrante del cliente, o sea si todavía hay
     * algo sin responder.
     *
     * Los mensajes `a_confirmar` se excluyen a propósito: todavía no se dijeron (nadie los
     * envió), así que no cuentan como respuesta. Es el mismo criterio que usa el historial
     * que ve la IA.
     *
     * @param WhatsappChat $chat
     *
     * @return bool
     */
    private function has_unanswered_inbound(WhatsappChat $chat): bool
    {
        $last_message = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where(function ($query) {
                $query->whereNull('ai_status')->orWhere('ai_status', '!=', 'a_confirmar');
            })
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        if (is_null($last_message)) {
            return false;
        }

        return $last_message->direction === 'in';
    }
}
