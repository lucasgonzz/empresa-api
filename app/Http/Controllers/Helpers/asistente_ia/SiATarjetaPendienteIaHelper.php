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
 * EL «SÍ» QUE CONTESTA UNA TARJETA QUE YA ESTABA ARMADA, AUNQUE EN EL MEDIO HAYA HABIDO UNA ACLARACIÓN (misión
 * asistente-diario-20261119, rama B, A5).
 *
 * 🔴 EL CASO REAL (Ferre Total, conversación 72, 18/11/2026, mensajes 669-675). El asistente dejó DOS cargas en
 * el mensaje 669: una actualización masiva (pasar 30 tornillos a la subcategoría «Trompeta Madera Grueso») y la
 * foto de esos 30. Pablo contestó «dejale la foto que tienen y cambiale solamente la subcategoría» (670); el
 * asistente canceló la foto y preguntó «Queda solo la subcategoría… ¿La registro?» (671), SIN tarjeta nueva:
 * la masiva del 669 seguía esperando. Pablo dijo «Si» (672) y la respuesta fue «Dejé lista la actualización…
 * Confirmame el sí una vez más y la registro» (673) con OTRA masiva propuesta; recién el segundo «Si» (674) la
 * registró. La del 669 quedó colgada en `propuesta` para siempre.
 *
 * LA CAUSA, MEDIDA EN EL CÓDIGO (no en el modelo). Una masiva está en HerramientasDeCarga::
 * NUNCA_AUTO_CONFIRMABLES, así que ConfirmacionDeterministaIaHelper no la confirma por un «sí» (bien: se
 * deshace mal). Ese turno quedaba en manos del modelo, pero ANTES le llegaba la nota de
 * SiQueRearmaLaCargaIaHelper: miraba solo el mensaje 671 —que no tenía tarjeta— y le decía al modelo «en ese
 * mensaje no habías armado ninguna propuesta: llamá AHORA a proponer_ con los mismos datos». El modelo hizo
 * exactamente eso: armó una masiva nueva y, como una masiva siempre espera el sí, volvió a preguntar. La nota
 * estaba mal para este caso: la propuesta existía, dos mensajes antes.
 *
 * Lo que hace este helper:
 *   1. tarjetas(): si el mensaje que se contesta es un «sí» corto e inequívoco (es_afirmacion, sin foto) por un
 *      canal que confirma por texto, busca hacia atrás —el último mensaje del asistente y, como mucho,
 *      MAXIMO_DE_ACLARACIONES más— el mensaje más nuevo que dejó tarjetas esperando (de menos de
 *      MINUTOS_MAXIMOS). Los mensajes del asistente que hay entre esa propuesta y el «sí» tienen que ser
 *      aclaraciones: `listo` y SIN ninguna acción propia (si propuso otra cosa, el sí puede ser de la otra). Y
 *      el último mensaje antes del «sí» tiene que terminar preguntando: es la pregunta que el «sí» contesta.
 *   2. nota(): le dice al modelo cuál es la tarjeta (o cuáles) y que llame a confirmar_carga_pendiente con esa,
 *      sin volver a proponerla ni a pedir el sí.
 *   3. SiQueRearmaLaCargaIaHelper::aplica() devuelve false cuando hay tarjetas acá: ya no le dice «armala de
 *      nuevo» a un sí que tiene una tarjeta a la que contestar.
 *
 * 🔴 ESTO NO CONFIRMA NADA. Es una nota: la confirmación sigue siendo del modelo con confirmar_carga_pendiente,
 * con TODAS las guardas de ConfirmacionPorTextoIaHelper::rechazo() (mismo turno, otro canal, mensaje `listo`, sí
 * posterior a la propuesta, envíos con su sí explícito). No se ensancha lo que un «sí» puede confirmar solo: una
 * tarjeta de plata (venta, cobro, pago, gasto) sigue sin confirmarse en código por un sí que pudo ser a otra
 * pregunta (revisión independiente del 18/11, ConfirmacionDeterministaIaHelper::es_aclaracion_sobre_la_tarjeta).
 * Lo único que cambia es que el modelo ya no recibe la orden equivocada de rearmar lo que ya estaba armado.
 *
 * Los ENVÍOS A CLIENTES quedan afuera: su sí tiene que contestar el bloque recién mostrado
 * (ConfirmacionPorTextoIaHelper::envio_no_visto()), y para eso está su propio circuito.
 *
 * Nunca lanza: si algo falla al mirar, no hay nota y el turno sigue como antes.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class SiATarjetaPendienteIaHelper
{
    /**
     * Cuántos mensajes del asistente puede haber entre el que propuso y el «sí» (las aclaraciones). Dos, como
     * ConfirmacionDeterministaIaHelper::MAXIMO_DE_ACLARACIONES: más atrás ya es otra charla.
     */
    const MAXIMO_DE_ACLARACIONES = 2;

    /** Antigüedad máxima de la tarjeta, la misma ventana que la confirmación determinista. */
    const MINUTOS_MAXIMOS = ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS;

    /**
     * Las tarjetas que este «sí» está contestando, en orden, o [] si no hay ninguna clara.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return array<int, \App\Models\AiMessageAction>
     */
    public static function tarjetas(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {

            if (!$assistant_message->acciones_habilitadas || !$assistant_message->confirma_por_texto()) {

                return [];
            }

            $pedido = AiMessage::where('ai_conversation_id', $conversation->id)
                                ->where('rol', 'user')
                                ->where('id', '<', $assistant_message->id)
                                ->orderBy('id', 'DESC')
                                ->first();

            /* Un «sí» corto y nada más, sin foto: con foto puede ser el pedido de OTRA carga. */
            if (is_null($pedido) || !ConfirmacionDeterministaIaHelper::es_afirmacion($pedido->contenido)) {

                return [];
            }

            if (AiMessageImagen::where('ai_message_id', $pedido->id)->exists()) {

                return [];
            }

            /*
             * Los mensajes del asistente anteriores al «sí», del más nuevo al más viejo: el que propuso y las
             * aclaraciones que puede haber entre medio.
             */
            $anteriores = AiMessage::where('ai_conversation_id', $conversation->id)
                                    ->where('rol', 'assistant')
                                    ->where('id', '<', $pedido->id)
                                    ->orderBy('id', 'DESC')
                                    ->limit(self::MAXIMO_DE_ACLARACIONES + 1)
                                    ->get();

            if (!count($anteriores)) {

                return [];
            }

            /*
             * La pregunta que el «sí» contesta es la del ÚLTIMO mensaje del asistente: si ése no pregunta nada,
             * no se sabe a qué dijo que sí y decide el modelo como siempre.
             */
            $ultimo = $anteriores->first();

            if ($ultimo->estado !== 'listo' || !self::termina_preguntando((string) $ultimo->contenido)) {

                return [];
            }

            $desde = Carbon::parse($pedido->created_at)->subMinutes(self::MINUTOS_MAXIMOS);

            foreach ($anteriores as $mensaje) {

                if ($mensaje->estado !== 'listo') {

                    return [];
                }

                $acciones = AiMessageAction::where('ai_message_id', $mensaje->id)->orderBy('id')->get();

                $esperando = $acciones->filter(function ($accion) use ($desde) {
                    return $accion->estado_guardado() === AiMessageAction::ESTADO_PROPUESTA
                        && !$accion->vencio()
                        && !is_null($accion->created_at)
                        && $accion->created_at->gte($desde);
                })->values();

                if (count($esperando)) {

                    /* Los envíos a clientes tienen su propio circuito (ver el docblock de la clase). */
                    foreach ($esperando as $accion) {

                        if (in_array((string) $accion->tipo, HerramientasDeCarga::TIPOS_DE_ENVIO, true)) {

                            return [];
                        }
                    }

                    return $esperando->all();
                }

                /*
                 * Un mensaje del medio que tiene acciones propias (confirmadas, canceladas, reemplazadas…) no es
                 * una aclaración: pasó otra cosa y el sí puede ser de eso. Se corta y decide el modelo.
                 *
                 * 🔴 Ojo, que el caso real lo necesita: cuando el asistente CANCELA una tarjeta (la foto del 669),
                 * la cancelación queda en la tarjeta, que cuelga del mensaje que la PROPUSO (669), no del que la
                 * canceló (671). Por eso el 671 llega acá sin acciones y cuenta como aclaración.
                 */
                if (count($acciones)) {

                    return [];
                }
            }

            return [];

        } catch (\Throwable $e) {

            Log::warning('SiATarjetaPendienteIaHelper: no se pudo revisar si el sí contesta una tarjeta anterior; sigue el turno normal.', [
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant_message->id,
                'error'              => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * La nota que viaja pegada al «sí» de la persona.
     *
     * @param  array<int, \App\Models\AiMessageAction>  $tarjetas  Lo que devolvió tarjetas() (no vacío).
     * @return string
     */
    public static function nota(array $tarjetas)
    {
        $nombradas = [];
        $ids = [];

        foreach ($tarjetas as $accion) {

            $presentacion = is_array($accion->presentacion) ? $accion->presentacion : [];
            $titulo = isset($presentacion['titulo']) && trim((string) $presentacion['titulo']) !== ''
                ? trim((string) $presentacion['titulo'])
                : (string) $accion->tipo;

            $nombradas[] = '#' . (int) $accion->id . ' (' . $titulo . ')';
            $ids[] = (int) $accion->id;
        }

        $una = count($tarjetas) === 1;

        return '[El sistema: la persona te contestó que SÍ a tu última pregunta, y '
            . ($una
                ? 'la tarjeta ' . $nombradas[0] . ' que armaste antes sigue esperando ese sí: es la carga sobre la que preguntaste. '
                    . 'Llamá AHORA a confirmar_carga_pendiente con tarjeta_id ' . $ids[0] . ' y contá lo que devuelva.'
                : 'las tarjetas ' . implode(' y ', $nombradas) . ' que armaste antes siguen esperando ese sí: son las cargas sobre las que preguntaste. '
                    . 'Llamá AHORA a confirmar_carga_pendiente con cada una (tarjeta_id ' . implode(', ', $ids) . ') y contá lo que devuelvan.')
            . ' 🔴 No la vuelvas a proponer, no armes otra igual y no le pidas que confirme otra vez: ese sí ya es la confirmación. '
            . 'Solo si tu última pregunta era sobre OTRA cosa (no sobre esa carga), no la confirmes y seguí con lo que te contestó.]';
    }

    /**
     * true si alguna de las dos últimas oraciones del texto es una pregunta (o un «confirmame…»): es la pregunta
     * a la que contesta el «sí».
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function termina_preguntando($texto)
    {
        if (CargaSinPropuestaIaHelper::termina_pidiendo_confirmar($texto)) {

            return true;
        }

        foreach (array_slice(VeracidadDelTurnoIaHelper::oraciones($texto), -2) as $oracion) {

            if (VeracidadDelTurnoIaHelper::es_pregunta($oracion)) {

                return true;
            }
        }

        return false;
    }
}
