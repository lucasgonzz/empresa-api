<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Un hilo por tarea en el canal de WhatsApp (misión asistente-capacidades-y-hilos, P4, 22/9/2026).
 *
 * El pedido de Lucas, textual: "quiero que con cada nuevo mensaje la IA detecte si el usuario ya
 * está hablando de otra tarea para crear una nueva conversación y no tener toda una conversación
 * demasiado larga. Ya que el usuario le va a hablar siempre desde WhatsApp, es decir, desde una
 * única conversación."
 *
 * El caso real que lo motivó: la conversación 7 de demo3 juntó 76 mensajes y OCHO tareas sin
 * relación entre sí (mover stock, aumentar costos, asignar una foto, permisos de una empleada, un
 * pago con cheque, una venta, un presupuesto y una compra), con el título de lo primero que se
 * pidió. Y no es solo prolijidad: con 70 mensajes encima, el asistente dijo que no podía leer una
 * foto que él mismo había recibido 20 mensajes antes.
 *
 * Esto NO reemplaza el corte por tiempo de AsistenteCanalHelper::HORAS_CORTE: se suma entre el
 * paso 2 (hay conversación vigente) y el 3 (abrir una nueva). Con conversación vigente, un llamado
 * corto al modelo general del proveedor del dueño decide si el mensaje nuevo SIGUE el tema o es
 * OTRO; con `nueva` se abre conversación y el título sale solo por el camino de siempre
 * (InferirTituloConversacionIaJob).
 *
 * 🔴 LA CONVERSACIÓN NUNCA SE PIERDE POR UN PROBLEMA DE IA. Cualquier falla del llamado —sin
 * credenciales, timeout, HTTP de error, respuesta ilegible, excepción— devuelve false, que es el
 * comportamiento de hoy: se sigue en la conversación vigente. Cortar de más rompe una tarea en dos
 * hilos; no cortar solo deja la conversación un poco más larga, que es exactamente donde estamos.
 *
 * 🔴 Y ANTES DE GASTAR UNA SOLA LLAMADA HAY GUARDAS DURAS (ver `motivo_para_no_cortar()`). La más
 * importante es la tarjeta en `propuesta`: cortar ahí rompería la confirmación por texto, porque
 * el "dale" caería en un hilo nuevo y ConfirmacionPorTextoIaHelper::rechazo() exige que la tarjeta
 * sea de la MISMA conversación. En la conversación real de Lucas hay varios "Si" sueltos que
 * confirman una carga propuesta cuatro mensajes antes.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class HiloPorTemaIaHelper
{
    /**
     * Largo mínimo del mensaje, en caracteres, para que valga la pena preguntarle a la IA si es
     * otro tema.
     *
     * El umbral sale de la conversación real de demo3, no de la intuición: los mensajes que NO
     * dicen nada del tema y que cortar habría roto son "Dale" (4), "Si" (2), "Y?" (2), "Listo"
     * (5), "Gracias" (7), "Ya terminó" (10) y "De florida" (10). El más largo de esa familia tiene
     * 10 caracteres. Con 15 quedan todos afuera con margen, y del otro lado siguen entrando los
     * pedidos cortos que SÍ son un tema nuevo: "Hacé un presupuesto" (19), "Cuánto vendí hoy?"
     * (17), "Aumentá los costos" (18).
     *
     * El sesgo es deliberado y va para el mismo lado que todo este helper: ante la duda no se
     * corta. Un tema nuevo que no se corta se corta en el mensaje siguiente; un "dale" que corta
     * deja una carga sin confirmar y al dueño sin entender por qué.
     */
    const LARGO_MINIMO_TEXTO = 15;

    /** Cuántos mensajes de la conversación vigente se le muestran al modelo para que compare. */
    const MENSAJES_DE_CONTEXTO = 6;

    /** Recorte de cada mensaje del contexto. Para reconocer un tema sobra con el arranque. */
    const MAX_LARGO_MENSAJE = 300;

    /**
     * Techo de tokens de la respuesta. Se contesta UNA palabra; 10 deja lugar para que el modelo
     * arranque con un salto de línea o una comilla sin quedarse sin techo.
     */
    const MAX_TOKENS = 10;

    /**
     * Timeout de la llamada, en segundos.
     *
     * 🔴 Es MÁS CORTO que el de InferirTituloConversacionIaJob (30 s) a propósito: aquel corre en
     * la cola y no lo espera nadie; este corre en el camino del webhook, con el admin esperando el
     * 202 para seguir con su polling. Diez segundos es el techo del peor caso; una respuesta de
     * una palabra con el modelo general tarda alrededor de un segundo.
     */
    const TIMEOUT_SEGUNDOS = 10;

    /** La palabra con la que el modelo pide abrir conversación nueva. Cualquier otra cosa no corta. */
    const RESPUESTA_NUEVA = 'nueva';

    /** Con qué se registra el gasto de esta llamada en ai_token_usages. */
    const PROCESO = 'chat_hilo';

    /**
     * Lo único que se le pide al modelo. Sin system, sin tools y con el contexto justo.
     *
     * "Ante la duda, sigue" está escrito adentro del prompt además de estar en el código: el
     * default del parseo ya es no cortar, pero decírselo baja los cortes falsos en vez de
     * taparlos después.
     */
    const PROMPT = 'Un comerciante le habla a su asistente por WhatsApp, siempre desde la misma '
        . 'conversación de WhatsApp, así que ahí se le mezclan tareas que no tienen nada que ver '
        . 'entre sí.' . "\n\n"
        . 'Tu única tarea es decidir si el MENSAJE NUEVO sigue el tema de la conversación que '
        . 'viene, o si arranca una tarea distinta.' . "\n\n"
        . 'Respondé UNA sola palabra, sin comillas, sin punto y sin nada más:' . "\n"
        . '- sigue: el mensaje continúa, corrige, confirma, responde, agradece o pregunta algo '
        . 'sobre lo que se viene hablando.' . "\n"
        . '- nueva: el mensaje arranca una tarea o una consulta que no tiene relación con lo '
        . 'anterior.' . "\n\n"
        . 'Ante la duda, respondé sigue.';

    /**
     * true si este mensaje arranca otra tarea y hay que abrirle conversación nueva.
     *
     * @param  \App\Models\AiConversation  $vigente  La conversación que el corte por tiempo eligió.
     * @param  string  $texto  El texto del mensaje nuevo (vacío si vino una foto sola).
     * @param  mixed  $ai_conversation_id_del_admin  Lo que mandó el admin, o null.
     * @return bool
     */
    public static function abre_otro_hilo(AiConversation $vigente, $texto, $ai_conversation_id_del_admin = null)
    {
        $texto = trim((string) $texto);

        $motivo = self::motivo_para_no_cortar($vigente, $texto, $ai_conversation_id_del_admin);

        if (!is_null($motivo)) {

            return false;
        }

        $contexto = self::contexto_de($vigente);

        /*
         * Sin título y sin un solo mensaje con texto no hay CONTRA QUÉ comparar: una conversación
         * recién abierta por el corte de 6 h, o una que solo tiene fotos sin epígrafe. Preguntarle
         * a la IA "¿esto sigue el tema?" sin tema es pagar una llamada para que adivine.
         */
        if (is_null($vigente->titulo) && $contexto === '') {

            return false;
        }

        return self::el_modelo_dice_que_es_otro_tema($vigente, $contexto, $texto);
    }

    /**
     * Por qué este mensaje NO se puede cortar, o null si se le puede preguntar a la IA.
     *
     * Devuelve el motivo como texto y no un booleano porque es lo que después se loguea: cuando
     * Lucas diga "esto tendría que haber abierto un hilo nuevo", la respuesta está en una línea.
     *
     * @param  \App\Models\AiConversation  $vigente
     * @param  string  $texto  Ya recortado.
     * @param  mixed  $ai_conversation_id_del_admin
     * @return string|null
     */
    protected static function motivo_para_no_cortar(AiConversation $vigente, $texto, $ai_conversation_id_del_admin)
    {
        /*
         * GUARDA 2 del plan — la intención explícita manda sobre cualquier inferencia. El admin
         * manda `ai_conversation_id` SOLO cuando lo dedujo de una cita o de un informe que él mismo
         * mandó: el dueño respondió citando algo, y eso ya dice en qué hilo quiere estar.
         *
         * Se mira el valor CRUDO y no la conversación resuelta: si el id era de una conversación
         * borrada o de otro dueño, AsistenteCanalHelper lo ignora y cae acá — pero el dueño citó
         * igual, y abrirle un hilo nuevo por un id que no se pudo honrar sería lo peor de los dos
         * mundos.
         */
        if (!is_null($ai_conversation_id_del_admin) && trim((string) $ai_conversation_id_del_admin) !== '') {

            return 'el admin mandó ai_conversation_id (el dueño citó un mensaje)';
        }

        /*
         * GUARDA 3 del plan, primera mitad — una foto sola. Pasó en demo3: el mensaje #97 vino sin
         * epígrafe y el "cargá la compra" recién llegó después. Sin texto no hay tema que leer.
         * (Un audio que Kapso no pudo transcribir llega acá como texto vacío por decisión del
         * llamador, y por el mismo motivo: no dice nada del tema.)
         */
        if ($texto === '') {

            return 'el mensaje no trae texto';
        }

        /* GUARDA 3 del plan, segunda mitad — ver el comentario de LARGO_MINIMO_TEXTO. */
        if (mb_strlen($texto) < self::LARGO_MINIMO_TEXTO) {

            return 'el mensaje tiene menos de ' . self::LARGO_MINIMO_TEXTO . ' caracteres';
        }

        /*
         * 🔴 GUARDA 1 del plan, LA MÁS IMPORTANTE DE TODAS — hay una tarjeta esperando el "dale".
         *
         * Cortar acá rompe la confirmación por texto: el "dale" caería en un hilo nuevo y
         * ConfirmacionPorTextoIaHelper::rechazo() no encontraría la tarjeta, porque su primera
         * guarda exige que sea de la MISMA conversación. En la conversación real de Lucas hay
         * varios "Si" sueltos que confirman una carga propuesta cuatro mensajes antes.
         *
         * Va después de las de texto y no antes porque las otras dos no tocan la base y esta sí:
         * el orden es el de costo creciente, y el resultado es el mismo.
         *
         * `estado` se compara contra la COLUMNA y el vencimiento se filtra por created_at: el
         * accessor de AiMessageAction lee una propuesta vencida como 'vencida' pero en la base
         * sigue diciendo 'propuesta', así que un where sobre el modelo no lo vería.
         */
        $hay_propuesta = AiMessageAction::where('ai_conversation_id', $vigente->id)
                                        ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                        ->where('created_at', '>', Carbon::now()->subHours(AiMessageAction::HORAS_VENCIMIENTO))
                                        ->exists();

        if ($hay_propuesta) {

            return 'hay una tarjeta en propuesta esperando confirmación';
        }

        /*
         * 🔴 GUARDA EXTRA, que no está en el plan y se suma por lo que se midió en demo3: la foto
         * de la factura y el "cargá la compra de tal proveedor" llegan en DOS mensajes distintos
         * (#97 y #98). La foto viaja en su AiMessage y el modelo la ve por el payload de la
         * conversación (AsistenteIaService::build_messages_payload), que está scopeado a la
         * conversación: si el segundo mensaje abriera un hilo nuevo, la foto quedaría en el
         * anterior y el asistente volvería a decir "no puedo leer la imagen" — el defecto exacto
         * que esta misión vino a arreglar.
         *
         * Se mira SOLO el último mensaje del dueño y solo si su foto sigue sin gestionar: acotado
         * así, una foto que nadie usó nunca no deja la conversación sin poder cortarse para
         * siempre.
         */
        if (self::el_ultimo_mensaje_dejo_una_foto_sin_usar($vigente)) {

            return 'el mensaje anterior trajo una foto que todavía no usó ninguna carga';
        }

        return null;
    }

    /**
     * true si el último mensaje del dueño en esta conversación trajo una foto que todavía no usó
     * ninguna carga.
     *
     * @param  \App\Models\AiConversation  $vigente
     * @return bool
     */
    protected static function el_ultimo_mensaje_dejo_una_foto_sin_usar(AiConversation $vigente)
    {
        $ultimo = AiMessage::where('ai_conversation_id', $vigente->id)
                            ->where('rol', 'user')
                            ->orderBy('id', 'DESC')
                            ->first();

        if (is_null($ultimo)) {

            return false;
        }

        return AiMessageImagen::where('ai_message_id', $ultimo->id)
                                ->sinGestionar()
                                ->exists();
    }

    /**
     * Los últimos mensajes de la conversación, del más viejo al más nuevo, ya recortados y
     * etiquetados con quién habló. Vacío si no hay ninguno con texto.
     *
     * Solo se miran los que TIENEN texto: un assistant 'pendiente' tiene `contenido` null y un
     * mensaje de foto sola no aporta nada a la comparación de temas.
     *
     * @param  \App\Models\AiConversation  $vigente
     * @return string
     */
    protected static function contexto_de(AiConversation $vigente)
    {
        $mensajes = AiMessage::where('ai_conversation_id', $vigente->id)
                                ->whereNotNull('contenido')
                                ->where('contenido', '!=', '')
                                ->orderBy('id', 'DESC')
                                ->limit(self::MENSAJES_DE_CONTEXTO)
                                ->get()
                                ->reverse();

        $lineas = [];

        foreach ($mensajes as $mensaje) {

            $texto = trim((string) $mensaje->contenido);

            if ($texto === '') {

                continue;
            }

            $quien = (string) $mensaje->rol === 'user' ? 'Comerciante' : 'Asistente';

            $lineas[] = $quien . ': ' . mb_substr($texto, 0, self::MAX_LARGO_MENSAJE);
        }

        return implode("\n", $lineas);
    }

    /**
     * El llamado corto: modelo general del proveedor del dueño, max_tokens chico, sin tools y sin
     * system. Devuelve true SOLO si la respuesta es exactamente la palabra `nueva`.
     *
     * @param  \App\Models\AiConversation  $vigente
     * @param  string  $contexto
     * @param  string  $texto
     * @return bool
     */
    protected static function el_modelo_dice_que_es_otro_tema(AiConversation $vigente, $contexto, $texto)
    {
        $eleccion  = ProveedorIaHelper::modelo_general(User::find($vigente->user_id));
        $proveedor = $eleccion['proveedor'];

        /* Sin clave no se sale a la red: se sigue en la conversación vigente, como hasta hoy. */
        if (!ProveedorIaHelper::hay_credenciales($proveedor)) {

            return false;
        }

        try {

            $prompt = self::PROMPT
                . "\n\n<titulo_de_la_conversacion>\n"
                . (is_null($vigente->titulo) ? '(todavía sin título)' : (string) $vigente->titulo)
                . "\n</titulo_de_la_conversacion>"
                . "\n\n<ultimos_mensajes>\n" . $contexto . "\n</ultimos_mensajes>"
                . "\n\n<mensaje_nuevo>\n" . mb_substr($texto, 0, self::MAX_LARGO_MENSAJE) . "\n</mensaje_nuevo>";

            /* El mismo payload para los dos proveedores; `thinking` solo viaja con DeepSeek. */
            $response = ProveedorIaHelper::cliente_http($proveedor, self::TIMEOUT_SEGUNDOS)
                ->post(ProveedorIaHelper::url_messages($proveedor), ProveedorIaHelper::agregar_thinking([
                    'model'      => $eleccion['modelo'],
                    'max_tokens' => self::MAX_TOKENS,
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ], $eleccion['thinking']));

            if (!$response->successful()) {

                Log::info('HiloPorTemaIaHelper: la API devolvió error, se sigue en la conversación vigente', [
                    'ai_conversation_id' => $vigente->id,
                    'status'             => $response->status(),
                ]);

                return false;
            }

            $body = is_array($response->json()) ? $response->json() : [];

            /* Esta decisión también se paga: una fila de consumo por llamada, como el título. */
            AiTokenUsageHelper::registrar([
                'user_id'            => $vigente->user_id,
                'auth_user_id'       => $vigente->auth_user_id,
                'proceso'            => self::PROCESO,
                'proveedor'          => $proveedor,
                'modelo'             => $eleccion['modelo'],
                'body'               => $body,
                'ai_conversation_id' => $vigente->id,
            ]);

            $corta = self::es_respuesta_de_corte(
                isset($body['content'][0]['text']) ? (string) $body['content'][0]['text'] : ''
            );

            if ($corta) {

                Log::info('HiloPorTemaIaHelper: el mensaje arranca otro tema, se abre conversación nueva', [
                    'ai_conversation_id' => $vigente->id,
                    'titulo'             => $vigente->titulo,
                ]);
            }

            return $corta;

        } catch (\Throwable $e) {

            /* Un timeout o un problema de red NO parten la conversación: se sigue en la vigente. */
            Log::info('HiloPorTemaIaHelper: falló la decisión de hilo, se sigue en la conversación vigente -- ' . $e->getMessage());

            return false;
        }
    }

    /**
     * true si la respuesta del modelo es la palabra `nueva`, y nada más.
     *
     * 🔴 Se mira la PRIMERA PALABRA y se exige que sea exacta. Con un `strpos(...) === 0` un
     * "nuevamente sigue" cortaría la conversación, que es el error caro; con la palabra entera,
     * cualquier cosa que el modelo escriba de más —una explicación, una disculpa, un JSON— no
     * corta nada. Una respuesta ilegible se comporta igual que una falla: no corta.
     *
     * @param  string  $respuesta
     * @return bool
     */
    protected static function es_respuesta_de_corte($respuesta)
    {
        $limpia = mb_strtolower(trim((string) $respuesta));

        /*
         * Comillas, guiones, backticks y puntuación afuera: los modelos contestan `nueva`, "nueva"
         * o - nueva con la misma intención. preg_replace con /u porque las comillas tipográficas
         * son multibyte.
         */
        $limpia = (string) preg_replace('/[^\p{L}\s]+/u', ' ', $limpia);

        $palabras = preg_split('/\s+/u', trim($limpia));

        if (!is_array($palabras) || !count($palabras)) {

            return false;
        }

        return $palabras[0] === self::RESPUESTA_NUEVA;
    }
}
