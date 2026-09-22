<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\MostradorReporte;
use App\Models\User;
use Carbon\Carbon;

/**
 * Quién habla y en qué conversación, cuando el mensaje entra por WhatsApp (misión
 * asistente-por-whatsapp, §3.3 del plan, 16/9/2026).
 *
 * El canal de WhatsApp no tiene sesión: el admin recibe el mensaje en el número de ComercioCity y
 * lo empuja por admin-sync con la clave del cliente. De este lado no hay `Auth` de donde sacar la
 * persona ni panel donde elegir la conversación, así que las dos cosas se resuelven acá.
 *
 * 🔴 EL DUEÑO SE RESUELVE CON config('app.USER_ID') PRIMERO. Es el patrón que ya usa
 * AdminSync\MostradorController::duenos() :76. En una base compartida por varios comercios (que
 * son un accidente histórico, pero existen: `u767360347_empresa` tiene 51 adentro) cada instancia
 * atiende al suyo, y "el primer owner del sistema" es el comercio equivocado —el error que
 * SistemaQueryController::resolve_owner() :131 arrastra y que este canal reemplaza—. Sin USER_ID,
 * el único owner del sistema; con más de uno y sin USER_ID, ninguno: es preferible un 404 visible
 * a mandarle los números de un negocio al dueño de otro.
 *
 * La política de conversaciones está repartida a propósito (§2 del plan): el admin resuelve la
 * CITA (es el único que conoce los wamid y manda `ai_conversation_id` solo si la dedujo de una) y
 * este API resuelve el CORTE POR TIEMPO (es el único que tiene `last_message_at` de verdad, y la
 * conversación es un objeto suyo).
 */
class AsistenteCanalHelper
{
    /**
     * Horas sin hablar después de las cuales un mensaje de WhatsApp abre una conversación NUEVA en
     * vez de seguir la anterior.
     *
     * Es la decisión de Lucas en la Fase 2: en el sistema el dueño abre una conversación nueva con
     * un botón y cambia entre ellas; en WhatsApp no hay dónde hacer eso, así que el corte es por
     * tiempo. Seis horas separan "seguimos hablando de lo mismo" de "esto es otro tema": el que
     * escribe a la mañana y vuelve a la tarde arranca limpio, y el que pregunta tres cosas
     * seguidas no pierde el contexto.
     *
     * La otra mitad de la política es la cita: responder citando un mensaje del asistente reabre
     * esa conversación aunque sea vieja, y eso pasa por encima de este corte (llega resuelto desde
     * el admin como `ai_conversation_id`).
     */
    const HORAS_CORTE = 6;

    /** Slug de la extensión que gatea el módulo IA, igual que el resto del asistente. */
    const EXTENSION = 'asistente_ia';

    /**
     * Orígenes de conversación que este canal puede CONTINUAR cuando el admin manda un
     * `ai_conversation_id` explícito.
     *
     * 🔴 `mostrador_reporte` está acá por un pedido textual de Lucas: "que pueda abrirlos desde el
     * celular y también les pueda hacer preguntas acerca de esos informes". El informe de la mañana
     * se manda con el id de SU conversación, y cuando el dueño contesta "¿por qué bajó la caja?",
     * el admin cita ese mensaje y el mensaje entra en la conversación del informe — que es la única
     * que tiene el informe entero como `contexto` de fondo. Sin esto, el asistente contestaba sin la
     * menor idea de qué informe le estaban hablando.
     *
     * Lo que NO se toca: el corte de 6 h y la creación automática siguen siendo solo de
     * 'whatsapp'. Este canal continúa una conversación de mostrador cuando se la nombran, pero
     * nunca la elige por su cuenta ni abre una.
     */
    const ORIGENES_QUE_CONTINUA = [AiConversation::ORIGEN_WHATSAPP, MostradorReporte::ORIGEN_CONVERSACION];

    /**
     * El dueño de esta instancia: users con `owner_id` null, acotado por `config('app.USER_ID')`
     * cuando la base está compartida. Null si no se puede decidir sin ambigüedad.
     *
     * 🔴 NO filtra por extensión: el gate de la extensión es otra cosa (403, no 404) y se chequea
     * aparte, para que "este cliente no tiene el módulo contratado" y "este cliente no existe" no
     * lleguen al admin como la misma respuesta. Ver `tiene_extension()`.
     *
     * @return \App\Models\User|null
     */
    public static function dueno()
    {
        $user_id_instancia = config('app.USER_ID');

        if (!empty($user_id_instancia)) {

            return User::whereNull('owner_id')
                        ->where('id', (int) $user_id_instancia)
                        ->first();
        }

        $duenos = User::whereNull('owner_id')
                        ->orderBy('id')
                        ->limit(2)
                        ->get();

        /*
         * Con más de un dueño y sin USER_ID no hay forma de saber a cuál de los comercios de esta
         * base le está hablando el que escribió: se devuelve null y el endpoint responde 404. Es
         * exactamente el caso que el login maestro resuelve mal (entra siempre como el primer
         * dueño), y acá el costo de equivocarse es mandarle las ventas de un negocio a otro.
         */
        if (count($duenos) !== 1) {

            return null;
        }

        return $duenos[0];
    }

    /**
     * true si el dueño tiene contratado el módulo IA. Misma lectura que CheckExtencionEmpresa,
     * aplicada a mano porque admin-sync no pasa por `auth:sanctum` y el middleware resuelve el
     * usuario desde la sesión.
     *
     * @param  \App\Models\User|null  $dueno
     * @return bool
     */
    public static function tiene_extension($dueno)
    {
        return !is_null($dueno) && UserHelper::hasExtencion(self::EXTENSION, $dueno);
    }

    /**
     * La conversación de WhatsApp en la que entra este mensaje.
     *
     * El orden del §3.3 del plan, que es el que sostiene la política repartida con el admin:
     *
     *   1. si vino `ai_conversation_id` y es una conversación de este dueño de un origen que este
     *      canal puede continuar → esa. (El admin solo lo manda cuando lo dedujo de una CITA o de
     *      un informe que él mismo mandó, así que esto es "el dueño respondió citando": reabre ese
     *      hilo aunque hayan pasado días.)
     *   2. si no, la última conversación de WhatsApp del dueño que habló hace menos de
     *      HORAS_CORTE → esa...
     *   2.b ...salvo que el mensaje nuevo arranque OTRA TAREA, que es lo que decide
     *      HiloPorTemaIaHelper (misión asistente-capacidades-y-hilos, P4, 22/9/2026). En WhatsApp
     *      el dueño escribe siempre desde la misma conversación, así que sin este paso se juntaban
     *      ocho tareas sin relación en un solo hilo (76 mensajes en demo3) con el título de lo
     *      primero que se pidió. Cualquier falla de ese llamado se comporta como "sigue".
     *   3. si no → una nueva.
     *
     * Un `ai_conversation_id` que no existe, que es de otro dueño o que es de un origen que este
     * canal no continúa NO es un error: se ignora y se sigue por el camino 2. El admin puede tener
     * una fila vieja apuntando a una conversación que el dueño borró desde el sistema, y un 422 por
     * eso dejaría al dueño sin respuesta a un mensaje perfectamente válido.
     *
     * 🔴 El corte se calcula con Carbon::now() de ESTE API, que corre en
     * America/Argentina/Buenos_Aires. No con la hora de la máquina que despachó el mensaje.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $ai_conversation_id  El que mandó el admin, o null.
     * @param  string  $texto  El texto del mensaje nuevo, con el que se decide si es otro tema.
     *                         Vacío (una foto sola, un audio sin transcribir) nunca corta.
     * @return \App\Models\AiConversation
     */
    public static function conversacion(User $dueno, $ai_conversation_id = null, $texto = '')
    {
        $pedida = self::conversacion_pedida($dueno, $ai_conversation_id);

        if (!is_null($pedida)) {

            return $pedida;
        }

        $reciente = AiConversation::where('user_id', $dueno->id)
                                    ->where('auth_user_id', $dueno->id)
                                    ->where('origen', AiConversation::ORIGEN_WHATSAPP)
                                    ->whereNotNull('last_message_at')
                                    ->where('last_message_at', '>', Carbon::now()->subHours(self::HORAS_CORTE))
                                    ->orderBy('last_message_at', 'DESC')
                                    ->orderBy('id', 'DESC')
                                    ->first();

        /*
         * 🔴 UN HILO POR TAREA. Con conversación vigente, la IA decide si este mensaje sigue el
         * tema o arranca otro; con `nueva` se cae al create de abajo y el título lo infiere
         * InferirTituloConversacionIaJob por el camino de siempre, porque la conversación nace sin
         * título y sin mensajes. Las guardas duras —tarjeta en `propuesta`, cita del admin,
         * mensaje corto o sin texto— y el "una falla no corta" viven en el helper.
         */
        if (!is_null($reciente) && !HiloPorTemaIaHelper::abre_otro_hilo($reciente, $texto, $ai_conversation_id)) {

            return $reciente;
        }

        /*
         * Nace igual que una del panel: `titulo` null significa "se está infiriendo" y lo completa
         * InferirTituloConversacionIaJob con el primer mensaje, exactamente como hoy. La persona
         * es el dueño en las dos puntas (user_id y auth_user_id) porque en este canal solo habla
         * él: el teléfono es el de la ficha del cliente y el admin ya descartó a los empleados.
         */
        return AiConversation::create([
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);
    }

    /**
     * La conversación que pidió el admin, si existe, es de este dueño y es de un origen que este
     * canal continúa (ORIGENES_QUE_CONTINUA). Null en cualquier otro caso (ver por qué no es un
     * error en el docblock de conversacion()).
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $ai_conversation_id
     * @return \App\Models\AiConversation|null
     */
    protected static function conversacion_pedida(User $dueno, $ai_conversation_id)
    {
        $id = (int) $ai_conversation_id;

        if ($id <= 0) {

            return null;
        }

        return AiConversation::where('id', $id)
                                ->where('user_id', $dueno->id)
                                ->where('auth_user_id', $dueno->id)
                                ->whereIn('origen', self::ORIGENES_QUE_CONTINUA)
                                ->first();
    }
}
