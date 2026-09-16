<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
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

    /** Lo que se le contesta a la IA cuando intenta confirmar algo que propuso en este mismo turno. */
    const MENSAJE_MISMO_TURNO = 'No podés confirmar una carga que acabás de proponer en este mismo mensaje. '
        . 'Decile los datos a la persona, preguntale si lo registrás, y confirmá recién cuando te conteste.';

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
        $rechazo = self::rechazo($conversation, $assistant_message, $accion_id);

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
     * @return array|null
     */
    protected static function rechazo(AiConversation $conversation, AiMessage $assistant_message, $accion_id)
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

        return null;
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

            return [
                'ok'         => true,
                'tarjeta_id' => (int) $accion_id,
                'estado'     => is_null($model) ? null : (string) $model->estado,
                'resultado'  => $texto,
                'nota'       => 'Ahora sí quedó registrado. Contale a la persona el resultado, en una línea.',
            ];
        }

        $mensaje = isset($body['message']) ? (string) $body['message'] : EjecutorAccionesIaHelper::MENSAJE_ERROR_GENERICO;

        return RespuestaDeCargaIa::error($mensaje);
    }

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
