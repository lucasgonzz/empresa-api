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
 * El arreglo es determinista y vive acá, en dos piezas:
 *
 *   1. EL REGISTRO DE DÓNDE SE MOSTRÓ: `ai_message_actions.datos['mostrado_en']`, la lista de ids de
 *      `ai_messages` del asistente, POSTERIORES a la propuesta, en los que EL SISTEMA le volvió a mostrar
 *      el bloque de esa tarjeta. Solo lo escribe registrar_mostrado_en(), y hoy solo lo llama la respuesta
 *      fija al casi sí (nunca el modelo: un bloque que el modelo copió por su cuenta no cuenta). Sin
 *      migración: es una clave más del JSON de `datos`, que los ejecutores de envío leen por clave
 *      (ninguno lo itera entero ni lo compara con un hash).
 *   2. EL «CASI SÍ» (respuesta_al_casi_si()): si el dueño contesta «ok» al bloque que acaba de ver, el
 *      sistema le pide el sí SIN LLAMAR AL MODELO, con el bloque otra vez, y registra ese mensaje. Es el
 *      caso de A8, y alcanza para cortar el círculo.
 *
 * 🔴 LO QUE NO HACE, A PROPÓSITO: VOLVER A PEGAR EL BLOQUE CUANDO EL SÍ LLEGA CON EL BLOQUE YA FUERA DE LA
 * VISTA. Esa pieza existió en esta misma misión y las dos revisiones independientes del 19/10 la tumbaron:
 * una corrección que el modelo no aplicó («sacale a Juan» → «listo, saqué a Juan» sin volver a proponer,
 * también escrita como pregunta: «¿le sacás a Juan?», «¿la mandás a las 18?») hacía volver el bloque VIEJO
 * y el sí siguiente lo mandaba; un ofrecimiento sin signo de pregunta («Si querés, también te lo mando por
 * mail.») seguido de un «dale» confirmaba el envío. Decisión de la raíz: ese caso vuelve al camino de
 * develop —el sí rebota con MENSAJE_ENVIO_NO_VISTO y el modelo vuelve a PROPONER en el mismo turno, con
 * los datos frescos y lo que el dueño corrigió—, que es el seguro. Si alguien quiere volver a pegar el
 * bloque viejo «para ahorrar un paso», está reabriendo esos agujeros.
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
     * Respondé sí para mandarlo.»). Texto del plan de la misión, con el «solo» que sumó la revisión
     * independiente del 19/10: sin él, a un «sí, gracias» (que es un casi sí: el «gracias» no entra en el
     * sí para mandar) el sistema le contestaba «necesito que me contestes «sí»», que suena raro.
     */
    const RESPUESTA_AL_CASI_SI = 'Para mandarlo necesito que me contestes solo «sí». Te lo dejo de nuevo:';

    /**
     * El último mensaje del asistente ANTERIOR al sí si ese mensaje le mostró al dueño el bloque de esta
     * tarjeta, o null. Son las reglas 1 y 3 de ConfirmacionPorTextoIaHelper::envio_no_visto(), juntas, y
     * las usa también el «casi sí» para saber si el bloque está a la vista.
     *
     * Vale como «lo muestra»:
     *   - el mensaje que PROPUSO la tarjeta, o
     *   - uno POSTERIOR a la propuesta que esté en `mostrado_en` (la respuesta fija al casi sí, que lleva
     *     el bloque),
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
             * mostrarle el bloque (la respuesta fija al casi sí). Un mensaje del modelo con el bloque copiado no está en la lista y no
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
     *     «ok» contesta ESE bloque. Si no está a la vista, esto no corre y el turno va por el modelo como
     *     en develop (y un sí, si llega, rebota y el modelo vuelve a proponer).
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
     * WhatsApp, donde nadie toca un botón para cerrarla— alcanzaba para que el casi sí
     * se apagara y volviera el círculo de A8. Una tarjeta de más de MINUTOS_MAXIMOS ya no la confirma
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
