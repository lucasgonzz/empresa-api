<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Una MARCA NUEVA que sale solo de leer una foto no se crea sin el sí del dueño (misión
 * asistente-marca-de-foto-y-envio-sin-bloque, 20/10/2026).
 *
 * 🔴 EL CASO REAL (repetición de la segunda prueba contra demo3, 20/10/2026 ~01:20). Foto de una
 * pinza que dice «KNIPEX 03-200 GERMANY» + «dame de alta esta pinza universal, la vendo a 18500.
 * ponele esta foto». La transcripción de la foto (Flash, `chat_transcripcion_foto`) leyó «KWB»; el
 * asistente creó la marca nueva KWB (brands 28) y el artículo 51 «Pinza universal KWB 200mm» sin
 * preguntar, y lo contó como hecho. Desde N6 (19/10) una marca que no existe se manda a crear «sin
 * preguntarle si la creás» (PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA), y en «directo» el alta
 * de la marca se ejecuta sola: un renglón mal leído de una foto quedaba como marca del negocio y
 * como marca del artículo.
 *
 * Qué cuenta como una marca «autorizada» (se crea en el acto, como hasta hoy). Cualquiera de:
 *   1. La escribió la persona: alguna palabra del nombre (de tres letras o más) está en sus últimos
 *      mensajes (ver nombra_la_marca()).
 *   2. La trajo la búsqueda por código de barras (datos de una base abierta o de la web, no una
 *      lectura de la foto): BusquedaPorCodigoDeBarrasIaHelper la anota con anotar_de_internet().
 *   3. La persona acaba de contestar «sí» a una pregunta del asistente que nombraba la marca
 *      («En la foto leo la marca KWB y no la tenés cargada: ¿la creo?» → «sí»).
 *   4. No hay ninguna foto reciente del dueño en la conversación: no hay nada que se haya podido
 *      leer mal, y la marca sale del texto o del conocimiento del modelo (como hasta hoy).
 *
 * Si no cuenta ninguna, la tarjeta de la marca se crea igual pero queda esperando el sí de la persona
 * aun en «directo» (`requiere_confirmacion`) y la respuesta le dice al modelo qué preguntar. Y si la
 * marca llega primero como un `brand_id` que no existe (resolver_marca()), el modelo recibe la
 * instrucción de preguntar, no la de crearla.
 *
 * Decisión de diseño: la lectura es del modelo y no se puede verificar contra la foto (el Pro no ve
 * imágenes); lo único determinista es QUIÉN dijo la marca. Por eso la regla es «¿la escribió el
 * dueño?» y no «¿la leyó bien?».
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class MarcaDeLaFotoIaHelper
{
    /** Cuántos mensajes de la persona se miran hacia atrás (los mismos que el proveedor de la factura). */
    const MENSAJES_DE_LA_PERSONA = ProveedorDeLaFacturaIaHelper::MENSAJES_DE_LA_PERSONA;

    /**
     * Palabras que aparecen en muchas marcas y en lo que dice cualquiera («pinza universal»,
     * «tools»): solas no prueban que el dueño haya nombrado la marca. Si la marca no tiene otra, hace
     * falta que estén todas (ver nombra_la_marca()).
     *
     * @var array<int, string>
     */
    const PALABRAS_GENERICAS = [
        'tools', 'tool', 'pro', 'plus', 'home', 'max', 'mini', 'power', 'star', 'universal', 'professional',
        'industrial', 'group', 'grupo', 'herramientas', 'electric', 'electronics', 'hogar', 'import', 'sport',
        'super', 'premium', 'classic', 'original', 'germany', 'china', 'brasil', 'italia', 'made', 'the',
    ];

    /** Prefijo de la caché de las marcas que trajo la búsqueda por código de barras. */
    const PREFIJO_DE_CACHE = 'asistente_ia:marcas_de_codigo:';

    /**
     * Lo que se le dice al modelo cuando la marca (que no existe en el negocio) sale solo de la foto:
     * NO la crea, pregunta. `%s` es el nombre.
     */
    const MARCADOR = 'sale de la lectura de la foto';

    const PREGUNTAR_LA_MARCA_DE_LA_FOTO = 'La marca "%s" no la escribió la persona: sale de la lectura de la foto, y el negocio no la tiene cargada. '
        . 'NO la crees ahora: una marca mal leída queda para siempre. Preguntale en una línea: «En la foto leo la marca %s y no la tenés cargada: ¿la creo?» '
        . '(o armá el artículo SIN marca —llamá de nuevo sin brand_id, con todo lo demás igual— y avisale que quedó sin marca). '
        . 'Mientras no la confirme, NO pongas esa marca ni en el nombre ni en la descripción del artículo (sacala de ahí si la habías puesto). '
        . 'Si te contesta que sí, ahí sí creala con proponer_alta (entidad brand) y volvé a llamar con ese nombre; si te dice cuál es la marca, usá la que diga.';

    /**
     * El aviso de la tarjeta de una marca que NO está autorizada (alta de la marca en sí): queda
     * esperando el sí aunque el modo sea «directo». `%s` es el nombre.
     */
    const AVISO_DE_LA_TARJETA = 'La marca "%s" la sacaste de la foto, no la escribió la persona: quedó esperando su sí y todavía no existe. '
        . 'Decile en una línea: «En la foto leo la marca %s y no la tenés cargada: ¿la creo?» y NO armes el artículo hasta que conteste. '
        . 'Si prefiere no crearla, o te dice que es otra marca, cancelá esa tarjeta con cancelar_carga_pendiente y cargá el artículo sin marca (o con la que diga). '
        . 'Mientras no la confirme, NO pongas esa marca ni en el nombre ni en la descripción del artículo.';

    /**
     * true si la marca `$nombre` se puede crear sin preguntar (ver el docblock de la clase).
     *
     * Nunca lanza: ante un error, del lado seguro (false: se pregunta).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @param  \App\Models\AiMessage|null  $mensaje  El assistant en curso (solo mira lo anterior a él).
     * @return bool
     */
    public static function autorizada(ContextoDeCargaIa $contexto, $nombre, $mensaje = null)
    {
        try {

            if (is_null($contexto->conversation)) {

                return true;
            }

            $palabras = self::palabras($nombre);

            if (!count($palabras)) {

                return trim((string) $nombre) === '';
            }

            if (!self::hay_fotos_recientes($contexto->conversation)) {

                return true;
            }

            if (self::la_escribio_la_persona($contexto->conversation, $palabras, $mensaje)) {

                return true;
            }

            if (self::la_trajo_el_codigo_de_barras($contexto->conversation, $nombre)) {

                return true;
            }

            return self::la_persona_dijo_que_si($contexto->conversation, $palabras, $mensaje);

        } catch (\Throwable $e) {

            Log::warning('MarcaDeLaFotoIaHelper: no se pudo decidir si la marca estaba autorizada; se pregunta.', [
                'ai_conversation_id' => is_null($contexto->conversation) ? null : (int) $contexto->conversation->id,
                'error'              => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Anota las marcas que devolvió la búsqueda por código de barras en esta conversación: vienen de
     * una base abierta o de la web, no de una lectura de la foto, así que se crean sin preguntar.
     *
     * @param  \App\Models\AiConversation|null  $conversation
     * @param  string|null  $marca
     * @return void
     */
    public static function anotar_de_internet($conversation, $marca)
    {
        if (!($conversation instanceof AiConversation) || is_null($marca) || self::clave($marca) === '') {

            return;
        }

        try {

            $clave = self::PREFIJO_DE_CACHE . (int) $conversation->id;

            $marcas = Cache::get($clave);

            $marcas = is_array($marcas) ? $marcas : [];

            $marcas[] = self::clave($marca);

            Cache::put($clave, array_values(array_unique($marcas)), Carbon::now()->addHours(FotosDeLaConversacionIaHelper::HORAS));

        } catch (\Throwable $e) {

            Log::warning('MarcaDeLaFotoIaHelper: no se pudo anotar la marca del código de barras.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * El texto para el modelo cuando la marca sale solo de la foto y todavía no existe.
     *
     * @param  string  $nombre
     * @return string
     */
    public static function instruccion_de_preguntar($nombre)
    {
        return sprintf(self::PREGUNTAR_LA_MARCA_DE_LA_FOTO, $nombre, $nombre);
    }

    /**
     * true si el texto es el de instruccion_de_preguntar() (para reconocer, en la respuesta de una
     * marca que no está, que el modelo tiene que preguntar y no crear).
     *
     * @param  mixed  $texto
     * @return bool
     */
    public static function es_la_instruccion_de_preguntar($texto)
    {
        return is_string($texto) && mb_strpos($texto, self::MARCADOR) !== false;
    }

    /**
     * El aviso que viaja con la tarjeta de la marca que espera el sí.
     *
     * @param  string  $nombre
     * @return string
     */
    public static function aviso_de_la_tarjeta($nombre)
    {
        return sprintf(self::AVISO_DE_LA_TARJETA, $nombre, $nombre);
    }

    /**
     * true si el dueño mandó alguna foto en esta conversación dentro de la ventana de las tarjetas.
     * Solo fotos de mensajes `user` (la foto que la búsqueda por código de barras guarda cuelga del
     * assistant; ver FotosDeLaConversacionIaHelper).
     *
     * @param  AiConversation  $conversation
     * @return bool
     */
    protected static function hay_fotos_recientes(AiConversation $conversation)
    {
        $ids = AiMessage::where('ai_conversation_id', $conversation->id)
                        ->where('rol', 'user')
                        ->where('created_at', '>=', Carbon::now()->subHours(FotosDeLaConversacionIaHelper::HORAS))
                        ->pluck('id')
                        ->all();

        if (!count($ids)) {

            return false;
        }

        return AiMessageImagen::whereIn('ai_message_id', $ids)->exists();
    }

    /**
     * true si TODAS las palabras del nombre están en los últimos mensajes de la persona.
     *
     * @param  AiConversation  $conversation
     * @param  array<int, string>  $palabras
     * @param  \App\Models\AiMessage|null  $mensaje
     * @return bool
     */
    protected static function la_escribio_la_persona(AiConversation $conversation, array $palabras, $mensaje)
    {
        $consulta = AiMessage::where('ai_conversation_id', $conversation->id)
                             ->where('rol', 'user')
                             ->where('created_at', '>=', Carbon::now()->subHours(FotosDeLaConversacionIaHelper::HORAS));

        if (!is_null($mensaje)) {

            $consulta->where('id', '<', (int) $mensaje->id);
        }

        $textos = $consulta->orderBy('id', 'DESC')->limit(self::MENSAJES_DE_LA_PERSONA)->pluck('contenido')->all();

        return self::nombra_la_marca(self::palabras(implode(' ', $textos)), $palabras);
    }

    /**
     * true si las palabras dichas nombran la marca: alguna de sus palabras significativas (de tres
     * letras o más) está entre ellas; si ninguna lo es («3M», «LG»), todas. Con UNA palabra alcanza
     * porque la marca sale completa del nombre que escribió la persona o se le parece: «Philips P85» con
     * «philips» dicho es Philips; lo que esta guarda frena es la marca que NO tiene nada que ver con lo
     * que escribió (KWB leído de una pinza que la persona llamó «pinza universal»).
     *
     * @param  array<int, string>  $dicho  Palabras normalizadas de lo que escribió la persona.
     * @param  array<int, string>  $palabras  Palabras normalizadas de la marca.
     * @return bool
     */
    protected static function nombra_la_marca(array $dicho, array $palabras)
    {
        $significativas = array_values(array_filter($palabras, function ($palabra) {
            return mb_strlen($palabra) >= 3 && !in_array($palabra, self::PALABRAS_GENERICAS, true);
        }));

        if (count($significativas)) {

            foreach ($significativas as $palabra) {

                if (in_array($palabra, $dicho, true)) {

                    return true;
                }
            }

            return false;
        }

        foreach ($palabras as $palabra) {

            if (!in_array($palabra, $dicho, true)) {

                return false;
            }
        }

        return true;
    }

    /**
     * true si la búsqueda por código de barras de esta conversación devolvió esta marca.
     *
     * @param  AiConversation  $conversation
     * @param  string  $nombre
     * @return bool
     */
    protected static function la_trajo_el_codigo_de_barras(AiConversation $conversation, $nombre)
    {
        $marcas = Cache::get(self::PREFIJO_DE_CACHE . (int) $conversation->id);

        return is_array($marcas) && in_array(self::clave($nombre), $marcas, true);
    }

    /**
     * true si el último mensaje de la persona es un «sí» corto y el mensaje del asistente que lo
     * precede es una pregunta que nombra la marca. El «sí» a otra pregunta no vale.
     *
     * @param  AiConversation  $conversation
     * @param  array<int, string>  $palabras
     * @param  \App\Models\AiMessage|null  $mensaje
     * @return bool
     */
    protected static function la_persona_dijo_que_si(AiConversation $conversation, array $palabras, $mensaje)
    {
        $consulta = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user');

        if (!is_null($mensaje)) {

            $consulta->where('id', '<', (int) $mensaje->id);
        }

        $ultimo = $consulta->orderBy('id', 'DESC')->first();

        if (is_null($ultimo) || !self::es_un_si($ultimo->contenido)) {

            return false;
        }

        $pregunta = AiMessage::where('ai_conversation_id', $conversation->id)
                             ->where('rol', 'assistant')
                             ->where('id', '<', (int) $ultimo->id)
                             ->orderBy('id', 'DESC')
                             ->first();

        if (is_null($pregunta)) {

            return false;
        }

        $texto = (string) $pregunta->contenido;

        if (mb_strpos($texto, '?') === false) {

            return false;
        }

        return self::nombra_la_marca(self::palabras($texto), $palabras);
    }

    /**
     * true si el mensaje es un «sí» a la pregunta de crear la marca: corto, empieza con una afirmación
     * («sí», «dale», «ok», «listo», «creala», «de una») y no trae una negación ni un «pero». Más laxo que
     * ConfirmacionDeterministaIaHelper::es_afirmacion() a propósito: «sí, creala» es la respuesta natural
     * a «¿la creo?» y ahí no hay ninguna tarjeta que un «sí» pelado confirme.
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function es_un_si($texto)
    {
        if (ConfirmacionDeterministaIaHelper::es_afirmacion($texto)) {

            return true;
        }

        if (mb_strpos((string) $texto, '?') !== false) {

            return false;
        }

        $palabras = self::palabras($texto);

        if (!count($palabras) || count($palabras) > 6) {

            return false;
        }

        if (array_intersect($palabras, ['no', 'pero', 'ni', 'nunca', 'jamas', 'tampoco', 'sin', 'ninguna', 'ninguno', 'nada', 'otra', 'otro', 'mejor']) !== []) {

            return false;
        }

        return in_array($palabras[0], ['si', 'dale', 'ok', 'okey', 'listo', 'claro', 'obvio', 'creala', 'crea', 'crealo', 'joya', 'perfecto', 'va'], true);
    }

    /**
     * Las palabras de un texto: sin tildes, en minúsculas, separadas por todo lo que no sea letra o dígito.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function palabras($texto)
    {
        $limpio = Str::lower(Str::ascii(trim((string) $texto)));

        $partes = preg_split('/[^a-z0-9]+/', $limpio, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($partes) ? array_values(array_unique($partes)) : [];
    }

    /**
     * La clave de una marca: solo letras y dígitos, en minúsculas y sin tildes (la misma que
     * PropuestaGenericaIaHelper::clave_de_marca()).
     *
     * @param  string  $nombre
     * @return string
     */
    protected static function clave($nombre)
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(trim((string) $nombre))));
    }
}
