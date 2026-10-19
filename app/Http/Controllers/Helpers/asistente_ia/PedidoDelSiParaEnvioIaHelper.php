<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EL PEDIDO DEL «SÍ» DE UN ENVÍO A CLIENTES LO ARMA EL SISTEMA, Y LA PROPUESTA QUEDA ATADA A ESE MENSAJE
 * (misión asistente-arreglos-segunda-prueba, N2, 19/10/2026).
 *
 * 🔴 EL CASO REAL. Segunda prueba del agente personal contra demo3 (registro del 19/10, A8): el dueño
 * pidió los recordatorios de cobro, el asistente mostró el bloque (a quiénes, el texto y «¿Lo mando?
 * Respondé sí para mandarlo.») y el dueño contestó «ok». Un «ok» NO es un sí para mandar (B1, decisión
 * fijada en el test 78), así que el modelo contestó «necesito un sí»… en un mensaje NUEVO, sin el
 * bloque. El «sí, mandalo» siguiente rebotó con ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO,
 * porque la regla 1 de envio_no_visto() exige que el último mensaje del asistente antes del sí sea el
 * que PROPUSO la tarjeta; el modelo volvió a copiar el bloque sin volver a proponer, y otra vez. Tres
 * «sí» y unos cinco minutos para mandar un recordatorio que el dueño había aprobado al primer intento.
 *
 * El arreglo es determinista y vive acá, en tres piezas que usan las mismas reglas:
 *
 *   1. EL REGISTRO DE DÓNDE SE MOSTRÓ: `ai_message_actions.datos['mostrado_en']`, la lista de ids de
 *      `ai_messages` del asistente, POSTERIORES a la propuesta, en los que EL SISTEMA volvió a pegar el
 *      bloque de esa tarjeta. Solo lo escribe registrar_mostrado_en() (nunca el modelo: un bloque que el
 *      modelo copió por su cuenta no cuenta). Sin migración: es una clave más del JSON de `datos`, que
 *      los ejecutores de envío leen por clave (ninguno lo itera entero ni lo compara con un hash).
 *   2. EL «CASI SÍ» (respuesta_al_casi_si()): si el dueño contesta «ok» al bloque que acaba de ver, el
 *      sistema le pide el sí SIN LLAMAR AL MODELO, con el bloque otra vez, y registra ese mensaje.
 *   3. EL SÍ QUE LLEGA CON EL BLOQUE YA FUERA DE LA VISTA (envio_a_volver_a_mostrar()): si entre el
 *      bloque y el sí hubo otra cosa, el sí sigue sin mandar, pero el sistema le vuelve a pegar el
 *      bloque al final del mensaje de ese turno (AsistenteIaService::con_los_bloques_de_envio()) y le
 *      deja al modelo una nota (ConfirmacionPorTextoIaHelper::rechazo()). UNA SOLA FUNCIÓN decide «hay
 *      que volver a mostrarlo» para el rechazo y para el pegado, así no se contradicen.
 *
 * 🔴 LAS GARANTÍAS DE B1 NO SE TOCAN, solo se suma un mensaje válido para la regla 1: el último mensaje
 * del asistente antes del sí tiene que ser el que propuso O uno de `mostrado_en`, `listo` y con el bloque
 * adentro (mensaje_que_lo_muestra()). Un «sí» a otra pregunta sigue sin confirmar; nada del dueño después
 * del sí; un solo envío esperando; una tarjeta de más de 30 minutos no; por MCP no; y en el panel (canal
 * 'sistema') nada cambia: ahí está la tarjeta con su botón y nada de esto corre.
 *
 * Nada de acá lanza: ante cualquier falla, el turno sigue como antes de esta misión (decide el modelo y el
 * sí rebota con el mensaje de siempre), que es lo seguro.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types, promoción de constructor,
 * readonly, enum ni #[...].
 */
class PedidoDelSiParaEnvioIaHelper
{
    /** La clave de `datos` con la lista de mensajes en los que el sistema volvió a mostrar el bloque. */
    const CLAVE_MOSTRADO_EN = 'mostrado_en';

    /**
     * Cuántos ids guarda `mostrado_en` como mucho (los últimos). La regla del sí solo mira el ÚLTIMO
     * mensaje del asistente, así que alcanza con pocos; el tope evita que un dueño que contesta «ok» una
     * y otra vez haga crecer el JSON de la tarjeta sin límite.
     */
    const MAXIMO_DE_MOSTRADOS = 10;

    /**
     * Lo que contesta el sistema, sin el modelo, a un «casi sí» al bloque recién mostrado. Va seguido de
     * una línea en blanco y el bloque, que ya cierra con DestinatariosIaHelper::PREGUNTA («¿Lo mando?
     * Respondé sí para mandarlo.»). Texto pedido en el plan de la misión, palabra por palabra.
     */
    const RESPUESTA_AL_CASI_SI = 'Para mandarlo necesito que me contestes «sí». Te lo dejo de nuevo:';

    /**
     * La línea con la que el sistema presenta el bloque que vuelve a pegar al final del mensaje de un
     * turno en el que el bloque ya no estaba a la vista.
     *
     * Existe porque el pegado lo decide el CÓDIGO, no el modelo: si el modelo estaba contestando otra
     * cosa (el detalle que el dueño pidió con ese mismo «sí»), sin esta línea el bloque aparecía pegado a
     * esa respuesta como si fuera un envío nuevo. Con ella se lee como lo que es: el que quedó esperando.
     */
    const ENCABEZADO_DEL_REMOSTRADO = 'Te dejo de nuevo el envío que quedó esperando:';

    /**
     * La nota que viaja con el rechazo de un sí a un envío que ya no estaba a la vista, cuando el sistema
     * se lo va a volver a mostrar al dueño en este mismo turno.
     *
     * 🔴 CONTRADICE A PROPÓSITO el «armá la propuesta de nuevo» de MENSAJE_ENVIO_NO_VISTO (que no cambia:
     * lo fijan los tests de B1). Ese consejo fue lo que armó el círculo del 19/10: el modelo copiaba el
     * bloque sin volver a proponer y el sí siguiente rebotaba igual. Con el bloque pegado por el sistema y
     * registrado en `mostrado_en`, el sí siguiente ya vale: lo único que tiene que hacer el modelo es
     * pedirlo en una línea.
     *
     * 🔴 REDACTADA CONDICIONAL (revisión independiente, 19/10): el pegado no se hace si el texto del modelo
     * le hace una pregunta al dueño (ver AsistenteIaService::con_el_envio_que_espera_el_si()), así que la
     * nota no puede prometerlo sin condición: le dice que se pega SI no pregunta nada, y le pide la línea
     * sin preguntas.
     */
    const NOTA_SE_VUELVE_A_MOSTRAR = 'Esta vez NO armes la propuesta de nuevo: si tu mensaje no le hace ninguna '
        . 'pregunta al dueño, el sistema le vuelve a mostrar este mismo envío al final, completo y con su pregunta. '
        . 'Vos decile en UNA línea, sin preguntas, que para mandarlo necesitás que te conteste «sí». No copies la '
        . 'lista ni el texto del envío, no lo vuelvas a proponer y no lo intentes confirmar otra vez en este turno.';

    /**
     * La nota que viaja pegada al último mensaje del dueño en el payload, ANTES del loop, cuando el sistema
     * va a volver a mostrar al final de la respuesta el envío que quedó esperando el sí
     * (AsistenteIaService::con_la_nota_del_envio_que_espera_el_si(); el molde es la nota de la
     * confirmación determinista).
     *
     * 🔴 POR QUÉ, Y ES POR LA REVISIÓN INDEPENDIENTE DEL 19/10: promo → «¿cuánto vendí hoy?» → «Vendiste
     * $ 120.000. ¿Querés el detalle?» → «sí» (era para el detalle) → el modelo daba el detalle y cerraba con
     * «¿Querés que te lo mande también por mail?», el sistema pegaba el bloque debajo y lo registraba, y el
     * «dale» a lo del mail confirmaba la promo. Con esta nota el modelo sabe ANTES de escribir que no tiene
     * que hacer ninguna pregunta; y si igual pregunta, el pegado no se hace (decide B1, como en develop).
     *
     * Entre corchetes, como las otras notas del sistema que viajan con el mensaje de la persona.
     */
    const NOTA_PARA_EL_TURNO = '[El sistema le va a volver a mostrar al dueño, al final de tu respuesta, el envío a '
        . 'clientes que quedó esperando su «sí» (completo y con su pregunta), siempre que tu respuesta no le haga '
        . 'ninguna pregunta. Contestale lo que te pidió en una línea y SIN hacerle ninguna pregunta (si su «sí» '
        . 'era para ese envío, decile que para mandarlo necesitás que te conteste «sí» después de verlo). No '
        . 'copies el envío ni lo vuelvas a proponer.]';

    /**
     * El último mensaje del asistente ANTERIOR al sí si ese mensaje le mostró al dueño el bloque de esta
     * tarjeta, o null. Son las reglas 1 y 3 de ConfirmacionPorTextoIaHelper::envio_no_visto(), juntas, y
     * las usa también el «casi sí» para saber si el bloque está a la vista.
     *
     * Vale como «lo muestra»:
     *   - el mensaje que PROPUSO la tarjeta, o
     *   - uno POSTERIOR a la propuesta que esté en `mostrado_en` (el sistema le pegó el bloque),
     * y en los dos casos el mensaje tiene que estar 'listo' (si sigue 'pendiente' todavía no salió por
     * WhatsApp: el dueño no leyó nada) y tener el bloque ADENTRO de su contenido (si salió sin el bloque
     * —un texto de respaldo, un error—, el dueño no vio a quiénes ni el texto).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessageAction  $accion  El envío a confirmar.
     * @param  \App\Models\AiMessage  $pedido  El mensaje del dueño (el «sí» o el «casi sí»).
     * @return \App\Models\AiMessage|null
     */
    public static function mensaje_que_lo_muestra(AiConversation $conversation, AiMessageAction $accion, AiMessage $pedido)
    {
        $ultimo = AiMessage::where('ai_conversation_id', $conversation->id)
                           ->where('rol', 'assistant')
                           ->where('id', '<', $pedido->id)
                           ->orderBy('id', 'DESC')
                           ->first();

        if (is_null($ultimo)) {

            return null;
        }

        if ((int) $ultimo->id !== (int) $accion->ai_message_id) {

            /*
             * No es el que propuso: solo vale si es POSTERIOR a la propuesta y el sistema lo registró al
             * pegarle el bloque. Un mensaje del modelo con el bloque copiado no está en la lista y no
             * cuenta: el registro lo escribe el código, nunca la redacción.
             */
            if ((int) $ultimo->id < (int) $accion->ai_message_id
                || !in_array((int) $ultimo->id, self::mostrado_en($accion), true)) {

                return null;
            }
        }

        if ($ultimo->estado !== 'listo') {

            return null;
        }

        $bloque = self::bloque($accion);

        if ($bloque === '' || mb_strpos((string) $ultimo->contenido, $bloque) === false) {

            return null;
        }

        return $ultimo;
    }

    /**
     * EL «CASI SÍ» (pieza 2 del docblock de la clase): si el dueño contestó una afirmación corta que no
     * alcanza para mandar («ok», «listo», «perfecto»…) al bloque que acaba de ver, el texto con el que el
     * sistema le pide el sí —con el bloque otra vez— ya registrado en `mostrado_en`. Null si no
     * corresponde, y el turno sigue por el modelo como siempre.
     *
     * 🔴 SIN LLAMAR AL MODELO, por el caso real del 19/10 (ver la clase): pedirle el sí es lo único que
     * hay que hacer, y dejárselo al modelo fue lo que sacó el bloque de la vista. La respuesta la devuelve
     * AsistenteIaService::responder() tal cual y el job la guarda como el contenido del mensaje.
     *
     * Corresponde solo si se cumple TODO:
     *   - el mensaje es de WhatsApp (no MCP —ahí un envío ni se confirma—, no el panel —ahí está la
     *     tarjeta con su botón—) y tiene las herramientas de carga (`acciones_habilitadas`);
     *   - el dueño mandó un casi sí (ConfirmacionDeterministaIaHelper::es_casi_si_para_envio(): sin «?»,
     *     sin palabras de más) y sin foto (con foto puede ser el pedido de otra cosa);
     *   - en la conversación hay UNA sola tarjeta esperando, y es un envío a clientes (con otra tarjeta
     *     pendiente no se sabe a cuál contesta el «ok»: decide el modelo). Cuentan solo las de menos de
     *     MINUTOS_MAXIMOS (pendientes_de_antes()): una olvidada de la mañana no apaga esto;
     *   - el envío es de menos de ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS (un «ok» de dentro de
     *     una hora puede estar contestando otra cosa: decide el modelo, y la regla 2 del sí tampoco lo
     *     dejaría mandar);
     *   - el bloque está a la vista en el último mensaje del asistente (mensaje_que_lo_muestra()): el
     *     «ok» contesta ESE bloque. Si no está a la vista, esto no corre y el turno va por el modelo (con
     *     el pegado de la pieza 3).
     *
     * Si el registro en `mostrado_en` falla, devuelve null: un bloque mostrado sin registro haría rebotar
     * el sí siguiente, y entonces es mejor que conteste el modelo como antes.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando ('pendiente').
     * @return string|null
     */
    public static function respuesta_al_casi_si(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {

            if (!$assistant_message->acciones_habilitadas || !$assistant_message->es_de_whatsapp()) {

                return null;
            }

            $pedido = self::pedido_de($conversation, $assistant_message);

            if (is_null($pedido)
                || !ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($pedido->contenido)
                || AiMessageImagen::where('ai_message_id', $pedido->id)->exists()) {

                return null;
            }

            $pendientes = self::pendientes_de_antes($conversation, $assistant_message);

            // Una sola tarjeta esperando en toda la conversación, y que sea un envío.
            if (count($pendientes) !== 1 || !self::es_envio($pendientes[0])) {

                return null;
            }

            $accion = $pendientes[0];

            if (!self::es_reciente($accion) || is_null(self::mensaje_que_lo_muestra($conversation, $accion, $pedido))) {

                return null;
            }

            /*
             * La misma guarda que el re-mostrado (ver nada_cambio_desde_que_lo_vio()). Con el bloque en
             * el último mensaje del asistente, lo único posterior es el «ok», así que acá casi nunca corta:
             * está para que las dos piezas sigan la misma regla si algún día eso deja de ser cierto.
             */
            if (!self::nada_cambio_desde_que_lo_vio($conversation, $accion, $assistant_message)) {

                return null;
            }

            $bloque = self::bloque($accion);

            if ($bloque === '' || !self::registrar_mostrado_en($accion->id, $assistant_message)) {

                return null;
            }

            Log::info('PedidoDelSiParaEnvioIaHelper: el dueño contestó un casi sí a un envío; el sistema le pide el sí sin llamar al modelo.', [
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant_message->id,
                'tarjeta_id'         => (int) $accion->id,
            ]);

            return self::RESPUESTA_AL_CASI_SI . "\n\n" . $bloque;

        } catch (\Throwable $e) {

            Log::warning('PedidoDelSiParaEnvioIaHelper: no se pudo resolver el casi sí; sigue el modelo.', [
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant_message->id,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 🔴 LA FUNCIÓN QUE DECIDE «HAY QUE VOLVER A MOSTRARLO» (pieza 3 del docblock de la clase): el envío
     * cuyo bloque el sistema tiene que volver a pegar al final del mensaje que se está generando, o null.
     *
     * La usan los DOS lados, y por eso es una sola:
     *   - ConfirmacionPorTextoIaHelper::rechazo(), para sumarle al rechazo del sí la nota
     *     NOTA_SE_VUELVE_A_MOSTRAR (solo si el envío que el modelo quiso confirmar es éste);
     *   - AsistenteIaService::con_los_bloques_de_envio(), para pegar el bloque y registrar `mostrado_en`.
     * Lo decide el estado y el mensaje del dueño, no si el modelo llamó a confirmar: el 19/10 el modelo a
     * veces copiaba el bloque sin llamar a nada, y el sí siguiente rebotaba igual.
     *
     * Corresponde solo si se cumple TODO:
     *   - el mensaje es de WhatsApp y tiene las herramientas de carga (mismo criterio que el casi sí);
     *   - el dueño dijo un sí (es_si_para_envio()) o un casi sí (es_casi_si_para_envio()), sin foto: con
     *     cualquier otra cosa no hay nada que pedirle;
     *   - en la conversación hay UN solo envío esperando, de un mensaje ANTERIOR (el de un envío que este
     *     mismo turno propone ya lo pega el camino de siempre), propuesto por WhatsApp y con su mensaje
     *     'listo' (si sigue 'pendiente', su bloque todavía está por salir), de menos de MINUTOS_MAXIMOS, y
     *     ninguna otra tarjeta esperando de menos de MINUTOS_MAXIMOS (con otra pendiente reciente, el sí
     *     puede ser para ésa; una olvidada de hace horas no cuenta: ver pendientes_de_antes());
     *   - 🔴 TODO lo que el dueño escribió desde la última vez que vio el bloque (incluido el sí de ahora)
     *     es una pregunta, un sí o un casi sí (nada_cambio_desde_que_lo_vio()). Una corrección («sacale a
     *     Juan Perez»), un «no, dejalo», un «esperá» o un pedido cualquiera apagan el re-mostrado: el
     *     modelo pudo decir «listo, lo saqué» sin volver a proponer, y re-pegar el bloque de la tarjeta
     *     vieja haría salir lo que el dueño corrigió (revisión independiente de la misión, 19/10). Ahí
     *     vuelve el camino de B1 tal cual, con su «armá la propuesta de nuevo»;
     *   - el mensaje que se está generando no dejó ninguna tarjeta esperando: si este turno propuso otra
     *     carga (un gasto, una tarea), pegarle además el envío viejo dejaría al dueño con dos cosas a las
     *     que decirles que sí en el mismo mensaje, y su sí siguiente no diría a cuál contesta;
     *   - el último mensaje del asistente antes del sí no dejó NINGUNA otra tarjeta (en cualquier estado
     *     salvo 'reemplazada' y 'descartada', que el dueño nunca pudo contestar): si le estaba diciendo
     *     que sí a otra carga —que este turno pudo haber confirmado ya—, el sí no es para el envío y
     *     volver a pegarlo sería ruido;
     *   - el bloque NO está a la vista (mensaje_que_lo_muestra() da null). Si está, el sí manda solo por
     *     confirmar_carga_pendiente y no hay nada que volver a mostrar.
     *
     * Nunca lanza: ante cualquier falla, null (no se pega nada y el rechazo es el de siempre).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return \App\Models\AiMessageAction|null
     */
    public static function envio_a_volver_a_mostrar(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {

            if (!$assistant_message->acciones_habilitadas || !$assistant_message->es_de_whatsapp()) {

                return null;
            }

            $pedido = self::pedido_de($conversation, $assistant_message);

            if (is_null($pedido)) {

                return null;
            }

            $contenido = $pedido->contenido;

            if (!ConfirmacionDeterministaIaHelper::es_si_para_envio($contenido)
                && !ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($contenido)) {

                return null;
            }

            if (AiMessageImagen::where('ai_message_id', $pedido->id)->exists()) {

                return null;
            }

            $pendientes = self::pendientes_de_antes($conversation, $assistant_message);

            if (count($pendientes) !== 1 || !self::es_envio($pendientes[0])) {

                return null;
            }

            $accion = $pendientes[0];

            if (!self::es_reciente($accion)) {

                return null;
            }

            $propuso = AiMessage::find($accion->ai_message_id);

            if (is_null($propuso) || !$propuso->es_de_whatsapp() || $propuso->estado !== 'listo') {

                return null;
            }

            /*
             * 🔴 Una corrección, un «no» o un pedido después del último bloque visto apagan el re-mostrado
             * (revisión independiente de la misión, 19/10): ver nada_cambio_desde_que_lo_vio().
             */
            if (!self::nada_cambio_desde_que_lo_vio($conversation, $accion, $assistant_message)) {

                return null;
            }

            // Este turno ya dejó otra tarjeta esperando: el envío viejo no se le suma al lado.
            if (AiMessageAction::where('ai_message_id', $assistant_message->id)
                               ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                               ->exists()) {

                return null;
            }

            $ultimo = AiMessage::where('ai_conversation_id', $conversation->id)
                               ->where('rol', 'assistant')
                               ->where('id', '<', $pedido->id)
                               ->orderBy('id', 'DESC')
                               ->first();

            if (!is_null($ultimo)
                && AiMessageAction::where('ai_message_id', $ultimo->id)
                                  ->where('id', '!=', $accion->id)
                                  ->whereNotIn('estado', [AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::ESTADO_DESCARTADA])
                                  ->exists()) {

                return null;
            }

            if (!is_null(self::mensaje_que_lo_muestra($conversation, $accion, $pedido))) {

                return null;
            }

            return self::bloque($accion) === '' ? null : $accion;

        } catch (\Throwable $e) {

            Log::warning('PedidoDelSiParaEnvioIaHelper: no se pudo decidir si volver a mostrar un envío.', [
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant_message->id,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Anota el mensaje del asistente en `datos.mostrado_en` de la tarjeta: «acá el sistema le volvió a
     * mostrar el bloque al dueño». true si quedó anotado (o ya estaba).
     *
     * 🔴 CON LA FILA BLOQUEADA Y SOLO SI LA TARJETA SIGUE ESPERANDO. Se relee con lockForUpdate adentro
     * de una transacción y se escribe `datos` entero de vuelta: sin el candado, un sí que confirma la
     * promo en otro turno (y guarda `datos.client_ids_encolados`, las reservas de
     * PropuestaPromocionWhatsappIaHelper) podía quedar pisado por esta escritura con el `datos` de antes.
     * Una tarjeta que ya no está 'propuesta' (confirmada, cancelada, reemplazada) no se toca: no hay sí
     * que pedirle.
     *
     * El mensaje se anota cuando todavía está 'pendiente' (su texto lo guarda el job después): no
     * importa, porque mensaje_que_lo_muestra() exige además que esté 'listo' y con el bloque adentro.
     * Nunca lanza.
     *
     * @param  int  $accion_id
     * @param  \App\Models\AiMessage  $mensaje  El assistant que lleva el bloque.
     * @return bool
     */
    public static function registrar_mostrado_en($accion_id, AiMessage $mensaje)
    {
        try {

            return (bool) DB::transaction(function () use ($accion_id, $mensaje) {

                $accion = AiMessageAction::where('id', (int) $accion_id)->lockForUpdate()->first();

                if (is_null($accion) || $accion->estado_guardado() !== AiMessageAction::ESTADO_PROPUESTA) {

                    return false;
                }

                $datos = is_array($accion->datos) ? $accion->datos : [];

                $lista = self::mostrado_en($accion);

                if (!in_array((int) $mensaje->id, $lista, true)) {

                    $lista[] = (int) $mensaje->id;
                }

                $datos[self::CLAVE_MOSTRADO_EN] = array_values(array_slice($lista, -self::MAXIMO_DE_MOSTRADOS));

                $accion->datos = $datos;
                $accion->save();

                return true;
            });

        } catch (\Throwable $e) {

            Log::warning('PedidoDelSiParaEnvioIaHelper: no se pudo anotar dónde se volvió a mostrar un envío.', [
                'ai_message_action_id' => (int) $accion_id,
                'ai_message_id'        => (int) $mensaje->id,
                'error'                => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Los ids de `datos.mostrado_en` de la tarjeta, como enteros (vacío si no hay ninguno).
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return array<int, int>
     */
    public static function mostrado_en(AiMessageAction $accion)
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        if (!isset($datos[self::CLAVE_MOSTRADO_EN]) || !is_array($datos[self::CLAVE_MOSTRADO_EN])) {

            return [];
        }

        $ids = [];

        foreach ($datos[self::CLAVE_MOSTRADO_EN] as $id) {

            if (is_numeric($id) && (int) $id > 0) {

                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * El bloque que la propuesta guardó en `datos.bloque` (DestinatariosIaHelper::bloque()), recortado, o
     * '' si no tiene. Es lo que el dueño tiene que ver tal cual antes del sí.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return string
     */
    public static function bloque(AiMessageAction $accion)
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        return isset($datos['bloque']) ? trim((string) $datos['bloque']) : '';
    }

    /**
     * true si TODO lo que el dueño escribió desde la última vez que vio el bloque de este envío hasta el
     * mensaje que se está contestando (incluido) es una pregunta (con «?» o «¿»), un sí
     * (ConfirmacionDeterministaIaHelper::es_si_para_envio()) o un casi sí (es_casi_si_para_envio()), estos
     * dos últimos sin foto.
     *
     * 🔴 EL CASO DEL REVISOR (19/10, probado con el job real sobre esta rama): promo a Ana y a Juan →
     * «sacale a Juan Perez» → el modelo contesta «Listo, saqué a Juan Perez de la promo.» SIN volver a
     * proponer → «dale». Sin esta guarda, el «dale» rebotaba con la nota de «NO armes la propuesta de
     * nuevo», el sistema re-pegaba el bloque VIEJO (con Juan), lo registraba, y el «sí» siguiente lo
     * mandaba a los dos. En develop ese «dale» volvía a «armá la propuesta de nuevo» y el modelo aplicaba
     * la corrección. La variante: un «no, dejalo» que el modelo no canceló, seguido de un «sí» a otra
     * pregunta, hacía reaparecer el envío rechazado.
     *
     * Por eso solo se re-muestra cuando lo que pasó en el medio no pudo cambiar el envío: preguntas sobre
     * otra cosa («¿cuánto vendí hoy?») y afirmaciones. Cualquier otra cosa decide B1 como siempre.
     * ⚠️ Una corrección escrita como pregunta («¿le sacás a Juan?») pasa esta guarda; ahí lo que protege
     * es que el bloque re-mostrado es el de la tarjeta, entero, con a quiénes y el texto, y el sí
     * siguiente contesta ESO.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessageAction  $accion
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return bool
     */
    protected static function nada_cambio_desde_que_lo_vio(AiConversation $conversation, AiMessageAction $accion, AiMessage $assistant_message)
    {
        $desde = self::ultima_vez_que_lo_vio($accion, $assistant_message);

        $mensajes = AiMessage::where('ai_conversation_id', $conversation->id)
                             ->where('rol', 'user')
                             ->where('id', '>', $desde)
                             ->where('id', '<', $assistant_message->id)
                             ->orderBy('id')
                             ->get();

        foreach ($mensajes as $mensaje) {

            $texto = (string) $mensaje->contenido;

            // Una pregunta sobre otra cosa no cambia lo que se va a mandar.
            if (preg_match('/[?¿]/u', $texto)) {
                continue;
            }

            $es_afirmacion = ConfirmacionDeterministaIaHelper::es_si_para_envio($texto)
                || ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($texto);

            // Un sí con foto puede ser el pedido de otra cosa, igual que en la regla del sí.
            if ($es_afirmacion && !AiMessageImagen::where('ai_message_id', $mensaje->id)->exists()) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * El id del último mensaje del asistente en el que el dueño vio el bloque de este envío: el más nuevo
     * de `mostrado_en` que sea anterior al mensaje que se está generando, esté 'listo' y tenga el bloque
     * adentro; si no hay ninguno, el que propuso la tarjeta.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  \App\Models\AiMessage  $assistant_message
     * @return int
     */
    protected static function ultima_vez_que_lo_vio(AiMessageAction $accion, AiMessage $assistant_message)
    {
        $ids = self::mostrado_en($accion);

        rsort($ids);

        $bloque = self::bloque($accion);

        foreach ($ids as $id) {

            if ($id >= (int) $assistant_message->id || $id <= (int) $accion->ai_message_id) {
                continue;
            }

            $mensaje = AiMessage::find($id);

            if (!is_null($mensaje) && $mensaje->estado === 'listo' && $bloque !== ''
                && mb_strpos((string) $mensaje->contenido, $bloque) !== false) {

                return (int) $id;
            }
        }

        return (int) $accion->ai_message_id;
    }

    /**
     * El mensaje del dueño que este assistant está contestando: el último 'user' anterior a él (el mismo
     * criterio que ConfirmacionPorTextoIaHelper::mensaje_que_dispara()).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @return \App\Models\AiMessage|null
     */
    protected static function pedido_de(AiConversation $conversation, AiMessage $assistant_message)
    {
        return AiMessage::where('ai_conversation_id', $conversation->id)
                        ->where('rol', 'user')
                        ->where('id', '<', $assistant_message->id)
                        ->orderBy('id', 'DESC')
                        ->first();
    }

    /**
     * Las tarjetas que siguen esperando en la conversación, de mensajes ANTERIORES al que se está
     * generando (las que este turno propone no cuentan: las muestra el camino de siempre), propuestas
     * hace menos de ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS.
     *
     * 🔴 SOLO LAS RECIENTES (revisión independiente, 19/10). Hasta esa revisión contaba toda tarjeta
     * `propuesta` sin vencer (24 h), y una tarjeta olvidada de la mañana en el mismo chat —probable en
     * WhatsApp, donde nadie toca un botón para cerrarla— alcanzaba para que el casi sí y el re-mostrado
     * se apagaran y volviera el círculo de A8. Una tarjeta de más de MINUTOS_MAXIMOS ya no la confirma
     * un «sí» suelto (ConfirmacionDeterministaIaHelper::tarjeta_a_confirmar() y la regla 2 del sí usan el
     * mismo tope), así que no es «otra tarjeta esperando» a la que el «ok» pueda estar contestando. El
     * envío mismo también tiene que ser reciente: un envío viejo directamente no aparece acá.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @return array<int, \App\Models\AiMessageAction>
     */
    protected static function pendientes_de_antes(AiConversation $conversation, AiMessage $assistant_message)
    {
        return AiMessageAction::where('ai_conversation_id', $conversation->id)
                              ->where('ai_message_id', '<', $assistant_message->id)
                              ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                              ->orderBy('id')
                              ->get()
                              ->filter(function ($accion) {
                                  return !$accion->vencio() && self::es_reciente($accion);
                              })
                              ->values()
                              ->all();
    }

    /**
     * true si la tarjeta es uno de los tres envíos a clientes (HerramientasDeCarga::TIPOS_DE_ENVIO).
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return bool
     */
    protected static function es_envio(AiMessageAction $accion)
    {
        return in_array((string) $accion->tipo, HerramientasDeCarga::TIPOS_DE_ENVIO, true);
    }

    /**
     * true si la tarjeta se propuso hace menos de ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS: la
     * misma ventana de la regla 2 del sí, para no pedir (ni volver a mostrar) algo que ya no se podría
     * mandar con ese sí.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return bool
     */
    protected static function es_reciente(AiMessageAction $accion)
    {
        return !is_null($accion->created_at)
            && !$accion->created_at->lt(Carbon::now()->subMinutes(ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS));
    }
}
