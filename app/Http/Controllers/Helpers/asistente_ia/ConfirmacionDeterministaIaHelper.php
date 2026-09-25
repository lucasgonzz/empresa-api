<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * EL "SÍ" DEL DUEÑO CONFIRMA LA TARJETA SIN PREGUNTARLE AL MODELO (misión
 * asistente-fotos-barras-y-compras, 24/9/2026).
 *
 * 🔴 POR QUÉ ESTO NO SE LE DEJA AL MODELO, Y ES POR UN CASO REAL. En demo3 (conv 10, 24/9/2026) el
 * dueño contestó "Dale" a la tarjeta de la foto de un artículo. El turno fue UNA sola vuelta de 28
 * tokens con el modelo Ágil: no llamó a confirmar_carga_pendiente ni a ninguna otra herramienta, y
 * escribió "la foto quedó asignada". La tarjeta siguió en `propuesta` y la foto sin asignar: el
 * dueño leyó que estaba hecho y no lo estaba. El prompt ya decía, con 🔴, que nunca diga que algo
 * quedó cargado sin que confirmar_carga_pendiente se lo conteste; el modelo chico igual lo dijo.
 *
 * Cuando el pedido es inequívoco —una afirmación corta, sin foto, contestándole a UNA tarjeta que
 * el asistente acaba de proponer— la confirmación se hace ACÁ, en código, antes de llamar al
 * modelo, por el MISMO camino que confirmar_carga_pendiente (ConfirmacionPorTextoIaHelper::
 * confirmar(): las mismas guardas, la persona autenticada, el mismo ejecutor). El modelo recibe
 * después una nota con el resultado real y lo único que hace es contarlo. Si alguien viene a
 * "simplificarlo" devolviéndoselo al modelo, vuelve el "quedó asignada" sin que nada se asigne.
 *
 * Desde las correcciones del 24/9/2026 vale también en el panel del chat (canal 'sistema'), donde
 * la ejecución es la del botón Confirmar; la tarjeta tiene que tener menos de MINUTOS_MAXIMOS; un
 * "sí" con signo de pregunta no es un sí; y si la confirmación por texto la rechazaría, no se
 * intenta. Si el modelo falla DESPUÉS de una confirmación hecha acá, al dueño le llega el resultado
 * (AsistenteIaService::responder()), no el error.
 *
 * Lo que NO se toca, y sigue decidiendo el modelo: un "no", una duda ("sí pero cambiale el monto"),
 * un "sí" con foto adjunta (puede ser OTRA carga), cualquier "sí" cuando hay dos o más tarjetas
 * pendientes (no se sabe a cuál contesta) y las tarjetas que no se deshacen (un borrado, una masiva).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class ConfirmacionDeterministaIaHelper
{
    /**
     * Largo máximo, ya normalizado, de un mensaje que se toma como "sí". Un "sí" real es corto; uno
     * de más de 40 caracteres casi siempre trae algo más adentro ("dale, pero ponele 5000").
     */
    const LARGO_MAXIMO = 40;

    /**
     * Las afirmaciones que confirman solas. Se comparan YA NORMALIZADAS (minúsculas, sin tildes, sin
     * signos y con las letras repetidas colapsadas: "Siii!!" es "si"), frase por frase: el mensaje
     * entero tiene que estar cubierto por estas frases y las de ACOMPANANTES, con al menos una de
     * éstas. Por eso "no", "pero", "cambiá" o un número dejan el mensaje afuera y decide el modelo.
     *
     * 🔴 Es una lista CERRADA a propósito. Sumar algo acá es decir "esto ejecuta una carga sin que
     * lo lea nadie": una palabra ambigua ("bueno", que también es "bueno... no sé") no entra sola.
     */
    const AFIRMACIONES = [
        'si', 'dale', 'ok', 'okey', 'okay', 'oka', 'listo', 'confirmo', 'confirmado', 'confirmada',
        'confirmalo', 'confirmala', 'confirma', 'de una', 'hacelo', 'hacela', 'cargalo', 'cargala',
        'registralo', 'registrala', 'perfecto', 'perfecta', 'va', 'joya', 'claro', 'correcto',
        'exacto', 'obvio', 'adelante', 'mandale', 'asignala', 'asignalo', 'ponela', 'ponele',
    ];

    /**
     * Palabras que pueden acompañar a una afirmación pero que SOLAS no confirman nada: un "gracias"
     * suelto no es un sí, y "dale, gracias" sí.
     */
    const ACOMPANANTES = ['gracias', 'por favor', 'porfa', 'bueno', 'che', 'nomas', 'nada mas', 'eso'];

    /**
     * Antigüedad máxima de la tarjeta que un "sí" confirma solo (correcciones del 24/9/2026). Un
     * "dale" que llega una hora después de la pregunta puede estar contestando otra cosa que el
     * dueño charló por otro lado; pasado este tope decide el modelo, que lee la conversación.
     */
    const MINUTOS_MAXIMOS = 30;

    /**
     * Si el mensaje que se está contestando es un "sí" inequívoco a UNA tarjeta pendiente, la
     * confirma y devuelve lo que devolvió la confirmación; si no, null y el turno sigue como siempre.
     *
     * Nunca lanza: si algo falla al mirar o al confirmar, el turno sigue por el modelo, que es como
     * funcionaba antes de esta misión.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return array|null  ['tarjeta_id' => int, 'resultado' => array] o null.
     */
    public static function quizas_confirmar(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {

            $accion = self::tarjeta_a_confirmar($conversation, $assistant_message);

            if (is_null($accion)) {

                return null;
            }

            /*
             * Por WhatsApp y MCP, el mismo camino que confirmar_carga_pendiente. En el panel del
             * chat (canal 'sistema'), el mismo camino que el botón Confirmar: ahí el modelo no tiene
             * confirmar_carga_pendiente, y un "Dale" TIPEADO quedaba en manos de un modelo que podía
             * contestar "quedó hecho" sin hacer nada — el bug de demo3, en el otro canal
             * (correcciones del 24/9/2026, Lucas escribe también desde el panel).
             */
            $resultado = $assistant_message->confirma_por_texto()
                ? ConfirmacionPorTextoIaHelper::confirmar($conversation, $assistant_message, (int) $accion->id)
                : ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, (int) $accion->id);

            /*
             * ⚠️ LA CARRERA CON EL BOTÓN (segundo chequeo adversarial, 24/9/2026). En el panel la
             * persona puede tocar Confirmar y tipear "dale" casi a la vez: el botón gana, y esta
             * confirmación vuelve con el 409 de "ya resuelta". Eso NO es un "no se pudo": la carga
             * quedó hecha. Si la tarjeta está confirmada, la nota es de éxito, con SU resultado.
             */
            if (empty($resultado['ok'])) {

                $resultado = self::resultado_si_ya_quedo_confirmada((int) $accion->id, $resultado);
            }

            return [
                'tarjeta_id' => (int) $accion->id,
                'resultado'  => $resultado,
            ];

        } catch (\Throwable $e) {

            Log::warning('ConfirmacionDeterministaIaHelper: no se pudo confirmar el sí del dueño; sigue el modelo', [
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant_message->id,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Si la tarjeta ya está CONFIRMADA (la confirmó otro camino —el botón— un instante antes), el
     * resultado de éxito con lo que guardó esa ejecución; si no, el rechazo tal cual vino.
     *
     * @param  int  $accion_id
     * @param  array  $rechazo  Lo que devolvió la confirmación que perdió la carrera.
     * @return array
     */
    protected static function resultado_si_ya_quedo_confirmada($accion_id, array $rechazo)
    {
        $accion = AiMessageAction::find((int) $accion_id);

        if (is_null($accion) || $accion->estado_guardado() !== AiMessageAction::ESTADO_CONFIRMADA) {

            return $rechazo;
        }

        $texto = is_object($accion->resultado) && isset($accion->resultado->texto) ? (string) $accion->resultado->texto : '';

        return [
            'ok'         => true,
            'tarjeta_id' => (int) $accion_id,
            'estado'     => AiMessageAction::ESTADO_CONFIRMADA,
            'resultado'  => $texto,
            'nota'       => ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA,
        ];
    }

    /**
     * La nota que viaja pegada al mensaje de la persona en el payload, para que el modelo cuente lo
     * que pasó de verdad en vez de volver a confirmar (o de inventar el resultado).
     *
     * @param  array  $confirmacion  Lo que devolvió quizas_confirmar().
     * @return string
     */
    public static function nota(array $confirmacion)
    {
        $id = (int) $confirmacion['tarjeta_id'];
        $resultado = $confirmacion['resultado'];

        if (!empty($resultado['ok'])) {

            $texto = isset($resultado['resultado']) ? trim((string) $resultado['resultado']) : '';

            return '[El sistema ya confirmó la tarjeta #' . $id . ' por el sí de la persona. Resultado: '
                . ($texto === '' ? 'quedó registrada (sin número que decir)' : $texto)
                . '. Contale el resultado con esas palabras y seguí con lo que haya quedado pendiente del pedido; '
                . 'no la vuelvas a confirmar.]';
        }

        $error = isset($resultado['error']) ? trim((string) $resultado['error']) : '';

        return '[El sistema intentó confirmar la tarjeta #' . $id . ' por el sí de la persona y NO se pudo: '
            . ($error === '' ? EjecutorAccionesIaHelper::MENSAJE_ERROR_GENERICO : $error)
            . '. Contale ese motivo tal cual y no digas que quedó cargado.]';
    }

    /**
     * Lo que se le contesta al dueño si el modelo falla DESPUÉS de una confirmación hecha acá: el
     * resultado de la carga, tal cual lo devolvió la ejecución. Null si la confirmación no salió
     * bien (ahí no hay nada registrado que contar y el error del modelo sigue su camino).
     *
     * @param  array  $confirmacion  Lo que devolvió quizas_confirmar().
     * @return string|null
     */
    public static function texto_de_respaldo(array $confirmacion)
    {
        $resultado = $confirmacion['resultado'];

        if (empty($resultado['ok'])) {

            return null;
        }

        $texto = isset($resultado['resultado']) ? trim((string) $resultado['resultado']) : '';

        return $texto === '' ? 'Listo, quedó registrado.' : 'Listo: ' . rtrim($texto, '. ') . '.';
    }

    /**
     * true si el texto es una afirmación corta y nada más (ver AFIRMACIONES).
     *
     * @param  string|null  $texto
     * @return bool
     */
    public static function es_afirmacion($texto)
    {
        /*
         * 🔴 Con un signo de pregunta no es un sí: "¿ok?", "¿dale?" o "¿lo cargaste?" preguntan.
         * Se mira ANTES de normalizar, porque normalizar saca los signos (correcciones del
         * 24/9/2026: "¿ok?" confirmaba).
         */
        if (preg_match('/[?¿]/u', (string) $texto)) {

            return false;
        }

        $normalizado = self::normalizar((string) $texto);

        if ($normalizado === '' || mb_strlen($normalizado) > self::LARGO_MAXIMO) {

            return false;
        }

        $palabras = explode(' ', $normalizado);

        $afirmaciones = self::frases(self::AFIRMACIONES);
        $acompanantes = self::frases(self::ACOMPANANTES);

        $hubo_afirmacion = false;
        $i = 0;
        $total = count($palabras);

        while ($i < $total) {

            $avance = 0;

            /* Primero las frases de dos palabras ("de una", "por favor"), después las de una. */
            foreach ([2, 1] as $largo) {

                if ($i + $largo > $total) {

                    continue;
                }

                $frase = implode(' ', array_slice($palabras, $i, $largo));

                if (in_array($frase, $afirmaciones, true)) {

                    $hubo_afirmacion = true;
                    $avance = $largo;
                    break;
                }

                if (in_array($frase, $acompanantes, true)) {

                    $avance = $largo;
                    break;
                }
            }

            if ($avance === 0) {

                return false;
            }

            $i += $avance;
        }

        return $hubo_afirmacion;
    }

    /**
     * true si el último mensaje del asistente dejó una tarjeta que sigue pendiente (de menos de
     * MINUTOS_MAXIMOS) y el mensaje de la persona NO la confirmó: es el turno en el que la persona
     * CORRIGE la carga ("sí, pero cambiale el nombre", "ponele otro precio").
     *
     * Misión asistente-deepseek-pro-razona (24/9/2026): ese turno lo contestaba el modelo rápido sin
     * pensar —no trae foto nueva, así que no arrancaba escalado— y en las pruebas reales con DeepSeek
     * falló 4 de 4: dijo "cambié el nombre" sin llamar a ninguna herramienta, o armó la tarjeta nueva
     * cambiando la foto de internet por la del dueño y cortando la descripción. Rearmar una carga es
     * decidir una carga: arranca escalado como un turno con foto.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return bool
     */
    public static function la_persona_corrige_una_tarjeta_pendiente(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {
            if (!$assistant_message->acciones_habilitadas) {

                return false;
            }

            $ultimo_del_asistente = AiMessage::where('ai_conversation_id', $conversation->id)
                                                ->where('rol', 'assistant')
                                                ->where('id', '<', $assistant_message->id)
                                                ->orderBy('id', 'DESC')
                                                ->value('id');

            if (is_null($ultimo_del_asistente)) {

                return false;
            }

            return AiMessageAction::where('ai_conversation_id', $conversation->id)
                                    ->where('ai_message_id', (int) $ultimo_del_asistente)
                                    ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                    ->where('created_at', '>=', Carbon::now()->subMinutes(self::MINUTOS_MAXIMOS))
                                    ->exists();

        } catch (\Throwable $e) {

            return false;
        }
    }

    /**
     * La única tarjeta que este "sí" confirma, o null si no hay una sola cosa que confirmar.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @return \App\Models\AiMessageAction|null
     */
    protected static function tarjeta_a_confirmar(AiConversation $conversation, AiMessage $assistant_message)
    {
        /*
         * (a) Con las herramientas de carga. Desde las correcciones del 24/9/2026 vale en TODOS los
         * canales: también en el panel del chat, donde la persona puede tipear "dale" en vez de
         * tocar el botón (ver quizas_confirmar()).
         */
        if (!$assistant_message->acciones_habilitadas) {

            return null;
        }

        $pedido = AiMessage::where('ai_conversation_id', $conversation->id)
                            ->where('rol', 'user')
                            ->where('id', '<', $assistant_message->id)
                            ->orderBy('id', 'DESC')
                            ->first();

        /* (b) Un "sí" corto y nada más, sin foto: con foto puede ser el pedido de OTRA carga. */
        if (is_null($pedido) || !self::es_afirmacion($pedido->contenido)) {

            return null;
        }

        if (AiMessageImagen::where('ai_message_id', $pedido->id)->exists()) {

            return null;
        }

        /* (c) Exactamente UNA tarjeta pendiente en toda la conversación... */
        $pendientes = AiMessageAction::where('ai_conversation_id', $conversation->id)
                                        ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                        ->orderBy('id')
                                        ->get()
                                        ->filter(function ($accion) {
                                            return !$accion->vencio();
                                        })
                                        ->values();

        if (count($pendientes) !== 1) {

            return null;
        }

        $accion = $pendientes[0];

        /*
         * 🔴 Lo que no se deshace (borrar, las masivas, unificar bancos, los permisos de un empleado:
         * HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES) no se confirma por un "dale" suelto que el
         * código interpretó: eso lo sigue haciendo el modelo, llamando a confirmar_carga_pendiente
         * con la pregunta y la respuesta a la vista. Un "sí" mal leído en una foto se corrige con
         * otra carga; un borrado, no.
         */
        if (in_array((string) $accion->tipo, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES, true)) {

            return null;
        }

        /* Ver MINUTOS_MAXIMOS. */
        if (is_null($accion->created_at) || $accion->created_at->lt(Carbon::now()->subMinutes(self::MINUTOS_MAXIMOS))) {

            return null;
        }

        /*
         * ...y propuesta por el ÚLTIMO mensaje del asistente antes de este "sí": el sí contesta la
         * pregunta que acaba de leer, no una tarjeta de hace media hora que quedó colgada.
         */
        $ultimo_del_asistente = AiMessage::where('ai_conversation_id', $conversation->id)
                                            ->where('rol', 'assistant')
                                            ->where('id', '<', $pedido->id)
                                            ->orderBy('id', 'DESC')
                                            ->value('id');

        if (is_null($ultimo_del_asistente) || (int) $ultimo_del_asistente !== (int) $accion->ai_message_id) {

            return null;
        }

        /*
         * 🔴 Si confirmar por texto la RECHAZARÍA (la pregunta todavía no le llegó a la persona, la
         * propusieron en la pantalla...), no se intenta: decide el modelo. Antes se intentaba y la
         * nota le hacía contarle al dueño el rechazo técnico ("No podés confirmar una carga que la
         * persona todavía no vio"), que no le dice nada (correcciones del 24/9/2026).
         */
        if ($assistant_message->confirma_por_texto()) {

            return ConfirmacionPorTextoIaHelper::se_puede_confirmar_por_texto($conversation, $assistant_message, $accion->id) ? $accion : null;
        }

        /*
         * En el panel, las guardas del botón: la pregunta ya se terminó de escribir (el mensaje que la
         * propuso está 'listo'). Que el "sí" sea posterior ya lo garantiza que la tarjeta sea del
         * último mensaje del asistente ANTERIOR al "sí".
         */
        $propuso = AiMessage::find($accion->ai_message_id);

        return (!is_null($propuso) && $propuso->estado === 'listo') ? $accion : null;
    }

    /**
     * Minúsculas, sin tildes, sin signos ni emojis, un solo espacio entre palabras y las letras
     * repetidas colapsadas ("Siiii" → "si", "Daleee!!" → "dale").
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        /* Todo lo que no sea letra o número pasa a espacio: signos, emojis, puntos suspensivos. */
        $texto = preg_replace('/[^a-z0-9]+/u', ' ', $texto);

        $texto = preg_replace('/([a-z])\1+/', '$1', (string) $texto);

        return trim(preg_replace('/\s+/', ' ', (string) $texto));
    }

    /**
     * Una lista de frases pasada por la misma normalización que el mensaje, para que "okey" y
     * "correcto" se comparen igual de los dos lados.
     *
     * @param  array<int, string>  $frases
     * @return array<int, string>
     */
    protected static function frases(array $frases)
    {
        $normalizadas = [];

        foreach ($frases as $frase) {

            $normalizadas[] = self::normalizar($frase);
        }

        return $normalizadas;
    }
}
