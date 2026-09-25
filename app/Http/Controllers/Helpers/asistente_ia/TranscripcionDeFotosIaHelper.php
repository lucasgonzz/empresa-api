<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Las fotos de un turno de DeepSeek, pasadas a TEXTO para que las pueda leer el Pro (misión
 * asistente-deepseek-pro-razona, 24/9/2026).
 *
 * 🔴 POR QUÉ EXISTE. Lucas quiere que cuando hay que razonar más, el asistente use DeepSeek Pro y
 * no Claude. Pero `deepseek-v4-pro` NO VE IMÁGENES, y no lo avisa con un error: medido el 24/9/2026
 * contra la API real, a Pro con una foto le llega sólo el texto (110 tokens de entrada) y contesta
 * "No puedo ver la imagen". Por eso hasta hoy un turno con foto corría en Flash (`model_vision`)
 * aunque fuera escalado —y un turno con foto es justamente el de cargar algo, el que más razona—.
 * Flash, en cambio, lee bien la foto (el código de barras 7 798111 212032 en 1,8 s).
 *
 * La salida es partir el trabajo en dos: Flash MIRA (una llamada al arrancar el turno, sin pensar,
 * con todas las fotos del payload) y escribe lo que hay en cada foto; Pro RAZONA sobre esa
 * transcripción, pensando desde la vuelta 0. Los bloques `image` del payload se reemplazan por el
 * texto, así que Pro nunca recibe una imagen que no ve.
 *
 * 🔴 SI LA TRANSCRIPCIÓN FALLA, NADA SE ROMPE. Cualquier falla (HTTP, timeout, clave, una respuesta
 * que no trae una sección por foto) devuelve null, y el service sigue como antes de esta misión:
 * Flash con visión y el thinking del turno escalado. Una transcripción a medias NO se usa: si una
 * foto quedó sin texto, Pro trabajaría sin ella sin saberlo, que es el mismo camino de falla mudo.
 *
 * 🔴 NO ES UNA INTERACCIÓN DEL DUEÑO, PERO SÍ ES GASTO. La llamada se registra en ai_token_usages
 * con su propio proceso (PROCESO): TopeDeTokensHelper cuenta interacciones sólo por las filas
 * `chat_mensaje` (una transcripción no es una pregunta del dueño y no le come el tope diario), y
 * los tokens del mes los suma sobre TODAS las filas del dueño, así que los de la transcripción sí
 * cuentan contra el tope mensual — son tokens que se pagaron por su turno.
 *
 * Caché: la transcripción de cada foto se guarda 24 h por (ai_message_imagenes.id + hash de los
 * bytes). Las fotos sin usar de la última tanda vuelven a viajar hasta tres mensajes después
 * (AsistenteIaService::con_las_fotos_de_la_ultima_tanda); sin el caché cada turno de esos pagaría
 * de nuevo la misma lectura. El hash va por si el archivo de una fila cambia: otra foto, otra clave.
 *
 * Anthropic no pasa nunca por acá: todos sus modelos ven.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TranscripcionDeFotosIaHelper
{
    /** El proceso con el que la transcripción queda en ai_token_usages (no es `chat_mensaje`: ver el docblock). */
    const PROCESO = 'chat_transcripcion_foto';

    /** Cuánto vive la transcripción de una foto en el caché. */
    const HORAS_DE_CACHE = 24;

    /** Prefijo de la clave de caché: `<prefijo><ai_message_imagenes.id>:<sha1>`. */
    const PREFIJO_DE_CACHE = 'asistente_ia:transcripcion_foto:';

    /**
     * Timeout de la llamada de transcripción, en segundos. Medido: 1,8 s para una foto. Corre ANTES
     * del loop y adentro de su presupuesto (AsistenteIaService::PRESUPUESTO_SEGUNDOS arranca antes
     * de transcribir), así que la cadena de techos del service sigue valiendo.
     */
    const TIMEOUT_SEGUNDOS = 30;

    /** Techo de salida de la transcripción: alcanza para varias fotos con texto abundante. */
    const MAX_TOKENS = 2000;

    /**
     * Lo que se le pide al modelo que ve. Las facturas se describen SIN montos ni renglones: el chat
     * tiene prohibido transcribir facturas (los datos de una compra los lee el escaneo de facturas);
     * alcanza con saber qué comprobante es, de quién y de cuándo.
     */
    const PROMPT_DE_TRANSCRIPCION = "Sos un transcriptor de fotos. Otro asistente, que NO puede ver imágenes, va a trabajar sólo con lo que vos escribas: lo que no anotes, para él no existe.\n"
        . "\n"
        . "Por cada foto escribí, en español y sin adornos:\n"
        . "- Qué se ve: un producto (marca, nombre, variedad, presentación, contenido neto), un documento (de qué tipo), una etiqueta, una góndola, una pantalla, etc.\n"
        . "- Todo el texto legible, tal cual está escrito.\n"
        . "- Si hay un código de barras: los dígitos impresos debajo de las barras TAL CUAL aparecen, con sus espacios, sin corregirlos ni completarlos. Si no se leen, decí \"código de barras ilegible\".\n"
        . "- Si es una factura, remito, ticket o cualquier comprobante: SÓLO el tipo (con su letra si la tiene), el emisor (nombre o razón social y CUIT) y la fecha. NO transcribas montos, totales, precios, cantidades ni renglones: esos datos los lee otro proceso.\n"
        . "- Si algo no se lee, decilo. No inventes ni deduzcas nada que no esté en la foto.\n"
        . "- Letra chica (páginas web, mails, direcciones, teléfonos, ingredientes, códigos de lote): copiala SOLO si se lee con total claridad. Si está borrosa, cortada o a medias, escribí \"[texto chico ilegible]\" y NO la completes: un dato inventado es peor que uno faltante.\n"
        . "\n"
        . "Formato: una sección por foto, cada una arrancando con una línea \"FOTO k:\" (k es el número que acompaña a esa foto), sin nada antes de la primera.";

    /**
     * true si el interruptor de config está prendido (`services.deepseek.pro_con_transcripcion`,
     * DEEPSEEK_PRO_CON_TRANSCRIPCION, default true). Con false todo corre como antes de esta misión.
     *
     * @return bool
     */
    public static function activa(): bool
    {
        return (bool) config('services.deepseek.pro_con_transcripcion', true);
    }

    /**
     * true si ESTE turno tiene que transcribir sus fotos y correr en Pro: el proveedor efectivo es
     * DeepSeek, el interruptor está prendido y el turno va escalado DESDE LA VUELTA 0 — el dueño eligió
     * Profundo, o el turno arranca escalado (foto propia en el mensaje, o una carga ya confirmada por
     * el sí de la persona).
     *
     * 🔴 El escalado A MITAD de turno (el modelo pidió una tool de carga en una vuelta del Ágil) NO
     * entra acá y no puede entrar: pasar de Flash a Pro con el thinking prendido después de un
     * tool_use sin bloque `thinking` es el 400 de DeepSeek de 1e9711bd
     * (ProveedorIaHelper::thinking_apto_para_historial). Ese turno sigue como hoy.
     *
     * No mira si hay fotos: eso lo sabe el service (AsistenteIaService::lleva_imagenes).
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $proveedor  El proveedor efectivo del turno (ProveedorIaHelper::proveedor_de).
     * @param  bool  $arranca_escalado  true si el turno ya está escalado antes de la primera llamada.
     * @return bool
     */
    public static function corresponde($owner, $proveedor, $arranca_escalado): bool
    {
        if ((string) $proveedor !== ProveedorIaHelper::DEEPSEEK || ! self::activa()) {

            return false;
        }

        if ($arranca_escalado) {

            return true;
        }

        return ProveedorIaHelper::pensamiento_de($owner, $proveedor) === 'profundo';
    }

    /**
     * Devuelve los mensajes con cada bloque `image` reemplazado por el texto de su transcripción, o
     * null si no se pudo transcribir alguna (el llamador sigue con los mensajes originales y el
     * modelo con visión). Nunca lanza.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  array<string, \App\Models\AiMessageImagen>  $fotos_por_hash  sha1 del `data` base64 del
     *         bloque → la fila de la foto; es lo que AsistenteIaService fue anotando al armar el payload.
     * @param  \App\Models\AiConversation  $conversation
     * @return array<int, array{role: string, content: string|array}>|null
     */
    public static function reemplazar_fotos(array $messages, array $fotos_por_hash, $conversation)
    {
        try {
            $fotos = self::fotos_del_payload($messages, $fotos_por_hash);

            if (! count($fotos)) {

                return null;
            }

            $textos = [];
            $faltan = [];

            foreach ($fotos as $foto) {
                $cacheada = Cache::get($foto['clave']);

                if (is_string($cacheada) && trim($cacheada) !== '') {
                    $textos[$foto['numero']] = $cacheada;
                } else {
                    $faltan[] = $foto;
                }
            }

            if (count($faltan)) {
                $nuevos = self::transcribir($faltan, $conversation);

                if (is_null($nuevos)) {

                    return null;
                }

                foreach ($faltan as $foto) {
                    $textos[$foto['numero']] = $nuevos[$foto['numero']];

                    Cache::put($foto['clave'], $nuevos[$foto['numero']], Carbon::now()->addHours(self::HORAS_DE_CACHE));
                }
            }

            return self::con_las_transcripciones($messages, $fotos, $textos);
        } catch (\Throwable $e) {
            Log::warning('TranscripcionDeFotosIaHelper: no se pudieron transcribir las fotos; el turno sigue con el modelo con visión.', [
                'ai_conversation_id' => is_null($conversation) ? null : $conversation->id,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Las fotos del payload, en orden y numeradas desde 1, con su clave de caché y su rótulo.
     *
     * @param  array  $messages
     * @param  array<string, \App\Models\AiMessageImagen>  $fotos_por_hash
     * @return array<int, array{numero:int, posicion:string, bloque:array, clave:string, rotulo:string}>
     */
    protected static function fotos_del_payload(array $messages, array $fotos_por_hash): array
    {
        $fotos = [];
        $numero = 0;

        foreach ($messages as $i => $message) {

            if (! isset($message['content']) || ! is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $j => $bloque) {

                if (! is_array($bloque) || ($bloque['type'] ?? '') !== 'image') {
                    continue;
                }

                $numero++;

                $hash = sha1(isset($bloque['source']['data']) ? (string) $bloque['source']['data'] : '');
                $imagen = isset($fotos_por_hash[$hash]) ? $fotos_por_hash[$hash] : null;

                $fotos[] = [
                    'numero'   => $numero,
                    'posicion' => $i . ':' . $j,
                    'bloque'   => $bloque,
                    'clave'    => self::PREFIJO_DE_CACHE . (is_null($imagen) ? 0 : (int) $imagen->id) . ':' . $hash,
                    'rotulo'   => self::rotulo($imagen),
                ];
            }
        }

        return $fotos;
    }

    /**
     * "la que mandó la persona hoy a las 10:32" (o "ayer a las…", o "el 22/9 a las…"): así el modelo
     * que razona puede decir "la foto que me mandaste recién" sin confundirla con otra.
     *
     * @param  \App\Models\AiMessageImagen|null  $imagen
     * @return string
     */
    protected static function rotulo($imagen): string
    {
        if (is_null($imagen) || is_null($imagen->created_at)) {

            return 'la que mandó la persona';
        }

        $cuando = Carbon::parse($imagen->created_at);

        if ($cuando->isToday()) {
            $dia = 'hoy';
        } elseif ($cuando->isYesterday()) {
            $dia = 'ayer';
        } else {
            $dia = 'el ' . $cuando->format('j/n');
        }

        return 'la que mandó la persona ' . $dia . ' a las ' . $cuando->format('H:i');
    }

    /**
     * UNA llamada al modelo con visión (Flash, thinking apagado) con todas las fotos que faltan.
     * Devuelve [numero => texto] con un texto no vacío para CADA foto pedida, o null.
     *
     * @param  array<int, array>  $fotos
     * @param  \App\Models\AiConversation  $conversation
     * @return array<int, string>|null
     */
    protected static function transcribir(array $fotos, $conversation)
    {
        $proveedor = ProveedorIaHelper::DEEPSEEK;
        $modelo    = ProveedorIaHelper::modelo_de_vision_de_deepseek();

        $content = [];

        foreach ($fotos as $foto) {
            $content[] = ['type' => 'text', 'text' => 'FOTO ' . $foto['numero']];
            $content[] = $foto['bloque'];
        }

        $content[] = ['type' => 'text', 'text' => 'Transcribí ' . (count($fotos) === 1 ? 'la foto' : 'las ' . count($fotos) . ' fotos') . ' con el formato indicado.'];

        /*
         * Thinking APAGADO explícito: DeepSeek lo trae prendido por defecto, y para leer una foto no
         * hace falta razonar (el que razona es Pro, después). Además deja el max_tokens como está.
         */
        $payload = ProveedorIaHelper::agregar_thinking([
            'model'      => $modelo,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => self::PROMPT_DE_TRANSCRIPCION,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ], ['type' => 'disabled']);

        $response = ProveedorIaHelper::cliente_http($proveedor, self::TIMEOUT_SEGUNDOS)
            ->post(ProveedorIaHelper::url_messages($proveedor), $payload);

        if (! $response->successful()) {
            /* El body de un error de DeepSeek no trae la clave; igual se recorta, es para diagnosticar. */
            Log::warning('TranscripcionDeFotosIaHelper: la API de DeepSeek rechazó la transcripción.', [
                'ai_conversation_id' => $conversation->id,
                'status'             => $response->status(),
                'body'               => mb_substr((string) $response->body(), 0, 300),
            ]);

            return null;
        }

        $body = $response->json();

        /* Se registra apenas contestó: los tokens se pagaron aunque después el texto no sirva. */
        AiTokenUsageHelper::registrar([
            'user_id'            => $conversation->user_id,
            'auth_user_id'       => $conversation->auth_user_id,
            'proceso'            => self::PROCESO,
            'proveedor'          => $proveedor,
            'modelo'             => $modelo,
            'body'               => is_array($body) ? $body : [],
            'ai_conversation_id' => $conversation->id,
        ]);

        $texto = '';

        if (is_array($body) && isset($body['content']) && is_array($body['content'])) {

            foreach ($body['content'] as $bloque) {

                if (is_array($bloque) && ($bloque['type'] ?? '') === 'text') {
                    $texto .= (string) ($bloque['text'] ?? '');
                }
            }
        }

        $por_numero = self::separar_por_foto($texto, $fotos);

        if (is_null($por_numero)) {
            Log::warning('TranscripcionDeFotosIaHelper: la transcripción no trajo una sección por foto.', [
                'ai_conversation_id' => $conversation->id,
                'fotos'              => count($fotos),
                'largo'              => mb_strlen($texto),
            ]);
        }

        return $por_numero;
    }

    /**
     * Parte la respuesta en secciones "FOTO k:". Con una sola foto pedida, una respuesta sin rótulo
     * se toma entera. Si alguna foto pedida queda sin texto, null (ver el 🔴 del docblock: una
     * transcripción a medias no se usa).
     *
     * @param  string  $texto
     * @param  array<int, array>  $fotos
     * @return array<int, string>|null
     */
    protected static function separar_por_foto($texto, array $fotos)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {

            return null;
        }

        /* Tolera "FOTO 1:", "**FOTO 1:**", "[FOTO 1]" y "Foto 1 -". */
        $partes = preg_split('/^[ \t]*[\*#]*[ \t]*\[?FOTO[ \t]+(\d+)\]?[ \t]*[:.\-]?/mi', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);

        $secciones = [];

        for ($k = 1; $k + 1 < count($partes); $k += 2) {
            $numero = (int) $partes[$k];
            $seccion = trim((string) $partes[$k + 1], " \t\n\r*");

            if ($seccion !== '') {
                $secciones[$numero] = isset($secciones[$numero]) ? $secciones[$numero] . "\n" . $seccion : $seccion;
            }
        }

        if (! count($secciones) && count($fotos) === 1) {
            $secciones[$fotos[0]['numero']] = $texto;
        }

        foreach ($fotos as $foto) {

            if (! isset($secciones[$foto['numero']])) {

                return null;
            }
        }

        return $secciones;
    }

    /**
     * Los mensajes con cada bloque `image` cambiado por su transcripción. Por mensaje, las fotos van
     * juntas en un bloque de texto al principio —con el aviso de que las leyó otro modelo— y
     * después el resto de sus bloques. Si todo lo que queda es texto, el `content` pasa a string,
     * la forma más básica del formato (la misma de un turno sin fotos).
     *
     * @param  array  $messages
     * @param  array<int, array>  $fotos
     * @param  array<int, string>  $textos  numero => transcripción.
     * @return array
     */
    protected static function con_las_transcripciones(array $messages, array $fotos, array $textos): array
    {
        $por_posicion = [];

        foreach ($fotos as $foto) {
            $por_posicion[$foto['posicion']] = $foto;
        }

        foreach ($messages as $i => $message) {

            if (! isset($message['content']) || ! is_array($message['content'])) {
                continue;
            }

            $lineas = [];
            $resto = [];

            foreach ($message['content'] as $j => $bloque) {

                if (isset($por_posicion[$i . ':' . $j])) {
                    $foto = $por_posicion[$i . ':' . $j];
                    $lineas[] = '[Foto ' . $foto['numero'] . ' (' . $foto['rotulo'] . '): ' . trim($textos[$foto['numero']]) . ']';

                    continue;
                }

                $resto[] = $bloque;
            }

            if (! count($lineas)) {
                continue;
            }

            $bloques = array_merge(
                [['type' => 'text', 'text' => self::aviso() . "\n" . implode("\n", $lineas)]],
                $resto
            );

            $solo_texto = true;

            foreach ($bloques as $bloque) {

                if (! is_array($bloque) || ($bloque['type'] ?? '') !== 'text') {
                    $solo_texto = false;
                }
            }

            if ($solo_texto) {
                $partes = [];

                foreach ($bloques as $bloque) {

                    if (trim((string) $bloque['text']) !== '') {
                        $partes[] = trim((string) $bloque['text']);
                    }
                }

                $messages[$i]['content'] = implode("\n\n", $partes);
            } else {
                $messages[$i]['content'] = $bloques;
            }
        }

        return $messages;
    }

    /**
     * El aviso que encabeza las transcripciones. 🔴 Sin él, el modelo que razona dice lo que hoy
     * dice Pro con una foto: "no puedo ver la imagen". La foto la vio otro modelo, y para la persona
     * es lo mismo que si la hubiera visto el asistente.
     *
     * @return string
     */
    protected static function aviso(): string
    {
        return '[Transcripción de las fotos: vos no ves las imágenes; las miró otro modelo que sí las ve y '
            . 'escribió lo que hay en cada una. Tomala como si las hubieras visto vos: no le digas a la persona '
            . 'que no podés ver la foto ni le pidas que te la describa. De una factura o comprobante sólo se '
            . 'transcriben el tipo, el emisor y la fecha: los montos y los renglones los lee el escaneo.]';
    }
}
