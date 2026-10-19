<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Confirmar y cancelar una carga cuando no hay tarjeta que tocar (misión asistente-por-whatsapp,
 * §3.5 del plan, 16/9/2026).
 *
 * En la pantalla la decisión la toma la persona con el dedo: el botón Confirmar pega un endpoint
 * en SU request, autenticado como ella. Por WhatsApp no hay botón: el asistente dice los datos
 * exactos, pregunta, y cuando el dueño contesta que sí, la IA llama a confirmar_carga_pendiente.
 * Este helper es lo que corre detrás de esas dos herramientas.
 *
 * 🔴 LA GUARDA QUE NO SE NEGOCIA: NO SE PUEDE CONFIRMAR UNA CARGA PROPUESTA EN EL MISMO TURNO.
 * Se compara `ai_message_actions.ai_message_id` contra el mensaje que se está respondiendo; si son
 * el mismo, se rechaza. Sin eso, la IA puede proponer y confirmar en una sola pasada del loop y el
 * dueño se entera del gasto cuando ya está registrado — que es exactamente lo contrario de lo que
 * el flujo de tarjetas vino a garantizar. Con la guarda, entre la propuesta y la confirmación
 * SIEMPRE hay un mensaje de la persona.
 *
 * 🔴 EL ESCOLLO DEL WORKER, Y POR QUÉ HAY UN Auth::setUser ACÁ. La confirmación corre adentro de
 * ResponderMensajeChatIaJob, sin request y sin sesión. Los helpers de plata que ejecutan la carga
 * leen la sesión: `NewProviderOrderHelper::__construct()` hace `UserHelper::user()`, que en un job
 * devuelve null, y revienta más adelante sin guarda; lo mismo vale para el employee_id del pago y
 * el empleado del movimiento de caja. La solución es autenticar a la PERSONA en el punto único
 * donde este canal ejecuta una carga, acotado y restaurado en un `finally`. NO se toca
 * NewProviderOrderHelper: es código de plata y stock, lo usan la pantalla de compras y el escaneo
 * de facturas, y cambiarlo para este caso sería tocar el camino de 40 clientes por un canal nuevo.
 */
class ConfirmacionPorTextoIaHelper
{
    /** Lo que se le contesta a la IA cuando el id de tarjeta no corresponde a esta conversación. */
    const MENSAJE_NO_ENCONTRADA = 'No encuentro esa carga en esta conversación. Volvé a proponerla.';

    /** Lo que se le contesta a la IA cuando intenta confirmar algo que la persona todavía no vio. */
    const MENSAJE_MISMO_TURNO = 'No podés confirmar una carga que la persona todavía no vio. '
        . 'Decile los datos, preguntale si lo registrás, y confirmá recién cuando te conteste que sí.';

    /** Lo que se le contesta a la IA cuando la tarjeta se propuso en la pantalla, no por WhatsApp. */
    const MENSAJE_OTRO_CANAL = 'Esa carga la propusiste en el sistema, así que se confirma desde ahí con el botón.';

    /**
     * Lo que se le contesta al modelo cuando quiere confirmar un ENVÍO A CLIENTES sin un sí explícito
     * del dueño (ver ConfirmacionDeterministaIaHelper::es_si_para_envio()).
     */
    const MENSAJE_ENVIO_SIN_SI = 'El dueño no dijo que sí a este envío: no lo mandé. Preguntale si lo mandás '
        . 'y esperá a que te conteste que sí en un mensaje suyo. Si te pidió un cambio, armá la propuesta de nuevo.';

    /**
     * Lo que se le contesta al modelo cuando no se puede asegurar que el "sí" contesta a ESTE envío tal
     * como el dueño lo vio (B1 del segundo chequeo): ver envio_no_visto().
     */
    const MENSAJE_ENVIO_NO_VISTO = 'No lo mandé: no puedo asegurar que el dueño vio exactamente esto justo antes de '
        . 'decir que sí. Volvé a mostrarle lo que se va a mandar (armá la propuesta de nuevo) y pedile el sí.';

    /** Lo que se le contesta al modelo cuando el último mensaje tiene más de un envío esperando el sí. */
    const MENSAJE_VARIOS_ENVIOS = 'No mandé nada: había más de un envío esperando el sí en el mismo mensaje, y un solo '
        . '"sí" no alcanza para saber a cuál contesta. Proponé un envío por vez y pedile el sí a cada uno.';

    /** Lo que se le contesta a un envío a clientes propuesto por MCP (no se confirma por ahí). */
    const MENSAJE_ENVIO_POR_MCP = 'Un envío a clientes no se confirma por MCP: lo tiene que mandar una persona desde '
        . 'el sistema o por WhatsApp, después de ver a quiénes y el texto.';

    /**
     * La nota que acompaña a toda carga ya ejecutada.
     *
     * 🔴 LE DICE AL MODELO DE DÓNDE SACA EL NÚMERO, Y ES POR UN CASO REAL. El 22/9/2026 en demo3 el
     * agente escribió "Quedó registrada la venta número 4882": esa venta existe, pero es del
     * 28/10/2025 — ese día no se creó ninguna. El número salió de su redacción, no de ninguna
     * herramienta. Lo único que tiene que repetir es `resultado`, que es lo que devolvió la
     * ejecución de verdad (para una venta, "Venta N° 1234 registrada", armado con el `num` que
     * contestó SaleController::store()). Si `resultado` viene vacío, no hay número que decir.
     */
    const NOTA_EJECUTADA = 'Ahora sí quedó registrado. Contale a la persona lo que dice `resultado`, '
        . 'en una línea, y si viene `detalle`, sumá lo que importa de ahí: el total de una venta o un '
        . 'presupuesto, el precio de venta de un artículo, qué campo quedó con qué valor (con su nombre '
        . 'tal cual: una razón social no es un domicilio) y lo que no quedó. 🔴 El número, el nombre y el '
        . 'total salen DE AHÍ (`resultado` y `detalle`), palabra por palabra: no los saques de tu memoria '
        . 'ni de la conversación, y si no vienen, no digas ninguno.';

    /**
     * Confirma una carga propuesta en un turno anterior y devuelve el resultado para el tool_result.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @param  mixed  $accion_id  tarjeta_id que devolvió la propuesta.
     * @return array
     */
    public static function confirmar(AiConversation $conversation, AiMessage $assistant_message, $accion_id)
    {
        $rechazo = self::rechazo($conversation, $assistant_message, $accion_id);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $persona = $contexto->persona;

        if (is_null($persona)) {

            return RespuestaDeCargaIa::error('No pude identificar tu usuario para registrar la carga.');
        }

        $resultado = self::autenticado_como($persona, function () use ($conversation, $accion_id, $persona, $contexto) {

            /*
             * El correlativo va como closure para que Controller::num() corra ADENTRO de la
             * transacción del ejecutor y su lockForUpdate se sostenga hasta el commit — mismo
             * criterio que AiConversationController::confirmar_accion(). El owner_id va explícito
             * y no por sesión: acá no hay request del que sacarlo.
             */
            return EjecutorAccionesIaHelper::confirmar($conversation, $accion_id, $persona, function () use ($contexto) {

                $controller = new Controller();

                return $controller->num('expenses', $contexto->owner_id);
            });
        });

        return self::traducir($resultado, (int) $accion_id);
    }

    /**
     * true si una tarjeta pasa las guardas de la confirmación por texto (las de rechazo(): de esta
     * conversación, de otro turno, propuesta por un canal sin botones, ya leída por la persona y
     * anterior a su respuesta). Lo usa ConfirmacionDeterministaIaHelper para NO confirmar lo que
     * confirmar() rechazaría: si rechaza, decide el modelo (correcciones del 24/9/2026 — antes la
     * nota le hacía contarle al dueño "No podés confirmar una carga que la persona todavía no vio").
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @param  mixed  $accion_id
     * @return bool
     */
    public static function se_puede_confirmar_por_texto(AiConversation $conversation, AiMessage $assistant_message, $accion_id)
    {
        return is_null(self::rechazo($conversation, $assistant_message, $accion_id));
    }

    /**
     * Confirma una tarjeta por el MISMO camino que el botón Confirmar de la pantalla
     * (AiConversationController::confirmar_accion → EjecutorAccionesIaHelper::confirmar, que exige el
     * mensaje que la propuso 'listo'), pero desde el job y con la persona autenticada.
     *
     * Existe para el "Dale" TIPEADO en el panel del chat (correcciones del 24/9/2026): ahí el modelo
     * no tiene confirmar_carga_pendiente (la confirmación es el botón), así que un "dale" escrito
     * quedaba en manos de un modelo que podía contestar "quedó hecho" sin haber hecho nada — el bug
     * original de demo3, en el otro canal. Las guardas de cuándo un "dale" confirma viven en
     * ConfirmacionDeterministaIaHelper; acá sólo la ejecución.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  mixed  $accion_id
     * @return array
     */
    public static function confirmar_como_el_boton(AiConversation $conversation, $accion_id)
    {
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $persona = $contexto->persona;

        if (is_null($persona)) {

            return RespuestaDeCargaIa::error('No pude identificar tu usuario para registrar la carga.');
        }

        $resultado = self::autenticado_como($persona, function () use ($conversation, $accion_id, $persona, $contexto) {

            return EjecutorAccionesIaHelper::confirmar($conversation, $accion_id, $persona, function () use ($contexto) {

                $controller = new Controller();

                return $controller->num('expenses', $contexto->owner_id);
            });
        });

        return self::traducir($resultado, (int) $accion_id);
    }

    /**
     * Auto-ejecuta en el acto una tarjeta que el agente acaba de proponer, cuando el MODO DE
     * CONFIANZA del dueño lo permite (misión foto-sucursal-y-asistente-configurable, 17/9/2026;
     * corrida a tres modos por asistente-capacidades-y-hilos, 22/9/2026).
     *
     * 🔴 A DIFERENCIA DE confirmar(), NO PASA POR rechazo(): la guarda del "mismo turno" y la del
     * "otro canal" existen para la confirmación que pide la PERSONA, y acá la decisión ya la tomó el
     * dueño al elegir su modo en la configuración. Esta confirmación es DELIBERADAMENTE en el mismo
     * turno, con el assistant todavía 'pendiente', así que usa
     * EjecutorAccionesIaHelper::confirmar_en_el_turno() (que salta la guarda de mensaje 'listo'). Lo
     * que sí se mantiene es autenticar a la persona: los helpers de plata leen la sesión, y esto
     * corre adentro del job sin request.
     *
     * ⚠️ Eso vale IGUAL para el modo "directo", que es el que más cargas auto-ejecuta: sigue sin
     * pasar por rechazo(), así que MENSAJE_MISMO_TURNO no lo frena, y la guarda sigue intacta para
     * la confirmación por texto de los otros dos modos (el "dale" del dueño por WhatsApp). El test 51
     * lo fija con las dos puntas: el modo directo ejecuta en el mismo turno y un
     * confirmar_carga_pendiente del mismo turno sigue rebotando con MENSAJE_MISMO_TURNO.
     *
     * Solo la llama HerramientasDeCarga tras crear una tarjeta cuyo tipo está en la lista del modo
     * del dueño (AUTO_CONFIRMABLES o AUTO_CONFIRMABLES_DIRECTO). Devuelve el resultado ejecutado
     * para que la IA le diga a la persona "ya la asigné" en vez de "te dejé la tarjeta".
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  mixed  $accion_id  tarjeta_id que devolvió la propuesta.
     * @return array
     */
    public static function confirmar_del_agente(AiConversation $conversation, $accion_id)
    {
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $persona = $contexto->persona;

        if (is_null($persona)) {

            return RespuestaDeCargaIa::error('No pude identificar tu usuario para registrar la carga.');
        }

        $resultado = self::autenticado_como($persona, function () use ($conversation, $accion_id, $persona, $contexto) {

            return EjecutorAccionesIaHelper::confirmar_en_el_turno($conversation, $accion_id, $persona, function () use ($contexto) {

                $controller = new Controller();

                return $controller->num('expenses', $contexto->owner_id);
            });
        });

        return self::traducir($resultado, (int) $accion_id);
    }

    /**
     * Cancela una carga propuesta en un turno anterior: el equivalente del botón Cancelar.
     *
     * No necesita autenticar a nadie (no escribe nada más que el estado de la tarjeta), pero sí la
     * misma guarda del mismo turno: cancelar lo que se acaba de proponer, sin que la persona haya
     * dicho nada, es igual de raro que confirmarlo.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @param  mixed  $accion_id
     * @return array
     */
    public static function cancelar(AiConversation $conversation, AiMessage $assistant_message, $accion_id)
    {
        /*
         * Cancelar es siempre seguro: no le exige a un envío a clientes el sí explícito ni que sea lo
         * recién visto (revisión de merge, 16/10/2026). Con esas guardas, un "no, dejalo" no podía
         * cancelar la promo que justamente estaba rechazando.
         */
        $rechazo = self::rechazo($conversation, $assistant_message, $accion_id, false);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return self::traducir(EjecutorAccionesIaHelper::cancelar($conversation, $accion_id), (int) $accion_id);
    }

    /**
     * La respuesta de rechazo si la tarjeta no se puede resolver acá, o null si se puede seguir.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @param  mixed  $accion_id
     * @param  bool  $para_confirmar  false al CANCELAR: las guardas de los envíos (el sí explícito y lo
     *                                recién visto) solo valen para mandar.
     * @return array|null
     */
    protected static function rechazo(AiConversation $conversation, AiMessage $assistant_message, $accion_id, $para_confirmar = true)
    {
        $accion = AiMessageAction::where('id', (int) $accion_id)
                                    ->where('ai_conversation_id', $conversation->id)
                                    ->first();

        if (is_null($accion)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_NO_ENCONTRADA);
        }

        // Ver el 🔴 del docblock de la clase: entre proponer y confirmar va la persona.
        if ((int) $accion->ai_message_id === (int) $assistant_message->id) {

            return RespuestaDeCargaIa::error(self::MENSAJE_MISMO_TURNO);
        }

        $propuso = AiMessage::find($accion->ai_message_id);

        if (is_null($propuso)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_NO_ENCONTRADA);
        }

        /*
         * 🔴 POR TEXTO SOLO SE CONFIRMA LO QUE SE PROPUSO POR TEXTO.
         *
         * Una conversación de WhatsApp se sigue desde el panel del chat (§4 del plan), así que en
         * la misma conversación conviven tarjetas de los dos canales. Sin este corte, una tarjeta
         * propuesta en la pantalla y DELIBERADAMENTE no confirmada —la persona la miró y no la
         * tocó— se podía confirmar después desde WhatsApp, que es justo lo contrario de lo que esa
         * persona decidió.
         *
         * Misión asistente-mcp (22/9/2026): el canal MCP también confirma por texto (no tiene
         * botones), así que la pregunta es confirma_por_texto() y no es_de_whatsapp(). Lo que
         * sigue rebotando es lo propuesto en la PANTALLA.
         */
        if (!$propuso->confirma_por_texto()) {

            return RespuestaDeCargaIa::error(self::MENSAJE_OTRO_CANAL);
        }

        /*
         * 🔴 LA TARJETA TIENE QUE HABER LLEGADO A LA PERSONA, y eso son dos cosas medibles.
         *
         * La guarda del mismo `ai_message_id` no alcanza, porque en este canal NO hay 409
         * `respuesta_en_curso` (a propósito: rebotar un mensaje de WhatsApp lo pierde) y el admin
         * despacha un job por cada mensaje entrante sin serializar por cliente. O sea que dos
         * mensajes seguidos del dueño —lo más normal del mundo: "anotá la nafta, 5000" y un segundo
         * después "gracias!"— generan DOS turnos en paralelo. El segundo ve la tarjeta en el
         * historial, tiene otro `ai_message_id`, y hasta acá podía confirmarla antes de que la
         * pregunta le hubiera llegado al dueño.
         *
         * 1. El mensaje que la propuso tiene que estar 'listo': mientras sigue 'pendiente' su texto
         *    todavía se está escribiendo y no salió por WhatsApp, así que la persona no pudo leer
         *    nada.
         * 2. El mensaje del dueño que dispara esta confirmación tiene que ser POSTERIOR a esa
         *    propuesta. Si es anterior, este turno arrancó antes de que la tarjeta existiera y su
         *    "sí" no puede estar contestándola.
         */
        if ($propuso->estado !== 'listo') {

            return RespuestaDeCargaIa::error(self::MENSAJE_MISMO_TURNO);
        }

        $pedido = self::mensaje_que_dispara($conversation, $assistant_message);

        if (is_null($pedido) || (int) $pedido->id < (int) $propuso->id) {

            return RespuestaDeCargaIa::error(self::MENSAJE_MISMO_TURNO);
        }

        /*
         * 🔴 LOS ENVÍOS A CLIENTES (misión asistente-envios-a-clientes, correcciones 2 y 3 del chequeo):
         * el sí lo verifica el CÓDIGO, no la lectura del modelo, y por MCP no se confirman nunca (un
         * agente externo podría proponer y confirmar 250 WhatsApps sin que una persona lea la tarjeta;
         * las herramientas ni se exponen por ahí, esto es el cinturón).
         */
        if ($para_confirmar && in_array((string) $accion->tipo, HerramientasDeCarga::TIPOS_DE_ENVIO, true)) {

            if ((string) $propuso->canal === AiMessage::CANAL_MCP) {

                return RespuestaDeCargaIa::error(self::MENSAJE_ENVIO_POR_MCP);
            }

            /*
             * Un mensaje con foto no es un sí a un envío (B4 de la revisión de merge), igual que en
             * la confirmación determinista: la foto puede ser el pedido de otra cosa.
             */
            if (AiMessageImagen::where('ai_message_id', $pedido->id)->exists()
                || !ConfirmacionDeterministaIaHelper::es_si_para_envio($pedido->contenido)) {

                return RespuestaDeCargaIa::error(self::MENSAJE_ENVIO_SIN_SI);
            }

            $no_visto = self::envio_no_visto($conversation, $accion, $propuso, $pedido);

            if (!is_null($no_visto)) {

                return RespuestaDeCargaIa::error($no_visto);
            }
        }

        return null;
    }

    /**
     * 🔴 EL SÍ ATADO A LO QUE EL DUEÑO VIO (B1 del segundo chequeo, 16/10/2026). Para un envío a
     * clientes no alcanza con que la tarjeta sea de otro turno: el "sí" tiene que estar contestando
     * ESE bloque, recién leído. El motivo del rechazo, o null si se puede confirmar:
     *
     *   1. la tarjeta es del ÚLTIMO mensaje del asistente anterior al "sí" (un "sí" a otra pregunta no
     *      confirma una tarjeta de hace tres mensajes, ni una vieja que una corrección no llegó a pisar);
     *      o ese último mensaje es uno en el que EL SISTEMA le volvió a mostrar el bloque al dueño
     *      (`datos.mostrado_en`, misión asistente-arreglos-segunda-prueba, N2, 19/10/2026: solo lo escribe
     *      la respuesta fija que el sistema le da a un «ok», con el bloque), y en ese caso el mensaje tiene
     *      que estar 'listo';
     *   2. se propuso hace menos de ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS (una tarjeta de
     *      ayer no se confirma con un "sí" de hoy) — contado desde la PROPUESTA, aunque se haya vuelto a
     *      mostrar después;
     *   3. su bloque está ADENTRO del mensaje que leyó el dueño —el de la regla 1, el que propuso o el de
     *      `mostrado_en`— (si ese mensaje salió sin el bloque —por ejemplo por un texto de respaldo—, el
     *      dueño no vio a quiénes ni qué texto);
     *   4. no hay ningún mensaje del dueño DESPUÉS del "sí" (un "sí" seguido de "esperá");
     *   5. ese mensaje no tiene MÁS de un envío esperando (un "sí" no dice a cuál contesta). Al proponer
     *      ya se reemplazan los otros (DestinatariosIaHelper::reemplazar_otros_envios()): esto es el
     *      cinturón. Si el sí contesta un mensaje de `mostrado_en` (que no propuso nada), se cuentan los
     *      envíos esperando de TODA la conversación: tiene que haber uno solo.
     *
     * Las reglas 1 y 3 viven juntas en PedidoDelSiParaEnvioIaHelper::mensaje_que_lo_muestra(), que es la
     * misma que usa el «casi sí» para saber si el bloque está a la vista: una sola definición de «el dueño
     * lo tiene delante».
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessageAction  $accion
     * @param  \App\Models\AiMessage  $propuso  El mensaje del asistente que la propuso.
     * @param  \App\Models\AiMessage  $pedido  El "sí" del dueño.
     * @return string|null
     */
    protected static function envio_no_visto(AiConversation $conversation, AiMessageAction $accion, AiMessage $propuso, AiMessage $pedido)
    {
        /*
         * 1 y 3. El último mensaje del asistente antes del "sí" es el que propuso o uno de `mostrado_en`,
         * está 'listo' y tiene el bloque adentro. Un mensaje del modelo que copió el bloque por su cuenta
         * NO vale: `mostrado_en` lo escribe solo el sistema, en la respuesta fija al «casi sí».
         */
        $a_la_vista = PedidoDelSiParaEnvioIaHelper::mensaje_que_lo_muestra($conversation, $accion, $pedido);

        if (is_null($a_la_vista)) {

            return self::MENSAJE_ENVIO_NO_VISTO;
        }

        // 2. Reciente.
        if (is_null($accion->created_at)
            || $accion->created_at->lt(now()->subMinutes(ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS))) {

            return self::MENSAJE_ENVIO_NO_VISTO;
        }

        // 4. Nada del dueño después del "sí".
        $despues = AiMessage::where('ai_conversation_id', $conversation->id)
                            ->where('rol', 'user')
                            ->where('id', '>', $pedido->id)
                            ->exists();

        if ($despues) {

            return self::MENSAJE_ENVIO_NO_VISTO;
        }

        /*
         * 5. Un solo envío esperando: en el mensaje que lo propuso, como siempre; y si el sí contesta un
         * mensaje de `mostrado_en` (que no propuso nada), en toda la conversación.
         */
        $envios = AiMessageAction::whereIn('tipo', HerramientasDeCarga::TIPOS_DE_ENVIO)
                                 ->where('estado', AiMessageAction::ESTADO_PROPUESTA);

        if ((int) $a_la_vista->id === (int) $propuso->id) {

            $envios->where('ai_message_id', $propuso->id);

        } else {

            $envios->where('ai_conversation_id', $conversation->id);
        }

        if ($envios->count() > 1) {

            return self::MENSAJE_VARIOS_ENVIOS;
        }

        return null;
    }

    /**
     * El mensaje del dueño que este assistant está contestando: el último 'user' anterior a él.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @return \App\Models\AiMessage|null
     */
    protected static function mensaje_que_dispara(AiConversation $conversation, AiMessage $assistant_message)
    {
        return AiMessage::where('ai_conversation_id', $conversation->id)
                        ->where('rol', 'user')
                        ->where('id', '<', $assistant_message->id)
                        ->orderBy('id', 'DESC')
                        ->first();
    }

    /**
     * Pasa el `['status', 'body']` del ejecutor a la forma que lee la IA.
     *
     * Todo lo que no sea un 200 vuelve como respuesta de NEGOCIO (RespuestaDeCargaIa), sin
     * is_error: que la tarjeta ya estuviera confirmada, o que la caja nunca se haya abierto, no es
     * una falla técnica — es un motivo concreto que la IA tiene que contarle al dueño tal cual.
     *
     * @param  array  $resultado  ['status' => int, 'body' => array]
     * @param  int  $accion_id
     * @return array
     */
    protected static function traducir(array $resultado, $accion_id)
    {
        $status = (int) $resultado['status'];
        $body   = $resultado['body'];

        if ($status === 200) {

            $model = isset($body['model']) ? $body['model'] : null;

            $texto = '';

            if (!is_null($model) && is_object($model->resultado) && isset($model->resultado->texto)) {

                $texto = (string) $model->resultado->texto;
            }

            $respuesta = [
                'ok'         => true,
                'tarjeta_id' => (int) $accion_id,
                'estado'     => is_null($model) ? null : (string) $model->estado,
                'resultado'  => $texto,
                'nota'       => self::NOTA_EJECUTADA,
            ];

            $detalle = is_null($model) ? [] : self::detalle_del_resultado($model->resultado, (string) $model->tipo);

            if (count($detalle)) {

                $respuesta['detalle'] = $detalle;
            }

            return $respuesta;
        }

        $mensaje = isset($body['message']) ? (string) $body['message'] : EjecutorAccionesIaHelper::MENSAJE_ERROR_GENERICO;

        return RespuestaDeCargaIa::error($mensaje);
    }

    /**
     * Las claves del resultado que el modelo puede repetir además del texto, ya listas para leer
     * (misión asistente-altas-y-conversacion, 16/10/2026).
     *
     * 🔴 POR QUÉ. Hasta acá traducir() le pasaba al modelo SOLO `resultado.texto` ("Presupuesto N° 900004
     * creado") y NOTA_EJECUTADA le prohíbe dar un número que no venga ahí: en demo3 el asistente creó
     * el presupuesto y la venta sin decir el total (el `total` estaba en el resultado y no llegaba), y
     * del alta de un cliente contestó "con domicilio en Ferreteria El Clavo" —que era la razón social—
     * porque no tenía de dónde saber qué campo quedó con qué valor y lo redactó leyendo el pedido.
     *
     * Es una LISTA CERRADA a propósito: el resultado de cada carga trae también rutas, ids y
     * parámetros para la pantalla, y nada de eso tiene que llegarle al modelo ni a la persona. El
     * `texto` no cambia (hay tests que lo comparan tal cual).
     *
     * @param  mixed  $resultado  El `resultado` de la tarjeta (objeto, como lo castea AiMessageAction).
     * @return array<string, mixed>
     */
    public static function detalle_del_resultado($resultado, $tipo = '')
    {
        if (!is_object($resultado) && !is_array($resultado)) {

            return [];
        }

        $resultado = json_decode(json_encode($resultado), true);

        if (!is_array($resultado)) {

            return [];
        }

        $detalle = [];

        if (isset($resultado['numero']) && is_numeric($resultado['numero']) && (int) $resultado['numero'] > 0) {

            $detalle['numero'] = (int) $resultado['numero'];
        }

        if (isset($resultado['total']) && is_numeric($resultado['total'])) {

            $detalle['total'] = FormatoIaHelper::monto((float) $resultado['total']);
        }

        /*
         * El registro que se acaba de CREAR o EDITAR, para usarlo en la misma vuelta (crear la
         * subcategoría de gasto y cargar el gasto con ella, o cargarle stock al artículo recién creado)
         * sin volver a buscarlo. Nunca el de una baja: ver AccionesIaHelper::linea_de_historial().
         */
        if (in_array((string) $tipo, [AiMessageAction::TIPO_ALTA, AiMessageAction::TIPO_EDICION], true)
            && isset($resultado['entidad'], $resultado['id']) && is_string($resultado['entidad'])
            && is_numeric($resultado['id']) && (int) $resultado['id'] > 0) {

            $detalle['registro'] = ['entidad' => $resultado['entidad'], 'id' => (int) $resultado['id']];
        }

        foreach (self::CLAVES_DEL_DETALLE as $clave) {

            if (!array_key_exists($clave, $resultado)) {

                continue;
            }

            $valor = $resultado[$clave];

            if (is_null($valor) || $valor === '' || (is_array($valor) && !count($valor))) {

                continue;
            }

            $detalle[$clave] = $valor;
        }

        return $detalle;
    }

    /**
     * Las claves del resultado, además de `numero` y `total`, que viajan en `detalle` tal cual.
     * Las agrega quien arma cada resultado (EjecutorGenericoIaHelper para las altas y ediciones,
     * PropuestaActualizacionMasivaIaHelper para la masiva).
     */
    const CLAVES_DEL_DETALLE = [
        'precio_de_venta_texto',
        'campos_guardados',
        'campos_que_no_quedaron',
        'cambios_aplicados',
        'ejemplos_de_precio',
        'aviso',
        /*
         * Los envíos a clientes (misión asistente-envios-a-clientes, 16/10/2026): qué salió por cada
         * canal, cuántos quedaron encolados y a quiénes NO les llegó y por qué. Sin esto el modelo
         * solo tendría el `texto` y no podría contarle al dueño que por mail no salió.
         */
        'enviados',
        'encolados',
        'no_salieron',
    ];

    /**
     * Corre la carga con la persona autenticada y deja la autenticación como estaba.
     *
     * El `finally` es la parte que importa: si la carga lanza, el worker sigue vivo y atiende el
     * próximo job — con el usuario de este dueño todavía puesto, si no se lo saca. En un worker
     * compartido eso es el peor error posible: una carga del próximo cliente firmada por la
     * persona equivocada.
     *
     * @param  \App\Models\User  $persona
     * @param  callable  $accion
     * @return mixed
     */
    protected static function autenticado_como($persona, $accion)
    {
        $previo = null;

        try {

            $previo = Auth::user();

        } catch (\Throwable $e) {

            /* Sin sesión de la que leer (el caso normal en un worker): no había nadie. */
            Log::info('ConfirmacionPorTextoIaHelper: no se pudo leer el usuario previo -- ' . $e->getMessage());
        }

        Auth::setUser($persona);

        try {

            return call_user_func($accion);

        } finally {

            if (is_null($previo)) {

                Auth::forgetGuards();

            } else {

                Auth::setUser($previo);
            }
        }
    }
}
