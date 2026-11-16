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
 * UN SOLO «SÍ»: el sí a una pregunta que no tenía propuesta detrás arma la carga y, si es de las que se
 * pueden, la deja HECHA con ese mismo sí (P4 de la misión asistente-mejoras-4-dias, 16/11/2026).
 *
 * 🔴 EL CASO REAL (Truvari, conversación 11 del 13/11/2026, por WhatsApp, ya en 4.3.59). El asistente
 * preguntó «Voy a prender también el aviso de artículos sin stock… ¿Lo hago?» (227) sin llamar a ninguna
 * herramienta. El dueño contestó «Si» (228) y le volvió a pedir «Confirmame para activarlo» (229); contestó
 * «Si te confirmo» (230) y le dijo «No tengo armada la propuesta… te la paso para que la confirmes» (231);
 * reclamó por audio («me estás preguntando tres veces una confirmación para un mismo objetivo», 232) y
 * recién ahí armó la tarjeta… y volvió a preguntar «¿Lo hago?» (233). Cuatro sí para un aviso.
 *
 * La confirmación determinista (ConfirmacionDeterministaIaHelper) cubre el sí a una TARJETA: acá no había
 * ninguna. Lo que hace este helper, solo por WhatsApp:
 *
 *   1. aplica(): el mensaje que se responde es un sí corto e inequívoco (es_afirmacion, sin foto), la
 *      respuesta anterior del asistente —de hace menos de MINUTOS_MAXIMOS— terminó pidiendo confirmar una
 *      carga (CargaSinPropuestaIaHelper::termina_pidiendo_confirmar) y NO dejó ninguna carga viva: ni una
 *      tarjeta esperando (de ésa se ocupa la determinista), ni una ya ejecutada (volver a armarla sería
 *      registrarla dos veces). Tampoco si era un envío a clientes: eso tiene su propio circuito.
 *   2. Con eso, AsistenteIaService le pega al sí la nota(): «ya te dijo que sí; armala ahora con los mismos
 *      datos y no vuelvas a preguntar».
 *   3. ejecuta(): cuando el modelo arma la propuesta, HerramientasDeCarga::quizas_auto_confirmar() la
 *      ejecuta con ese sí si el tipo está en TIPOS_QUE_EJECUTA_EL_SI y el modo de confianza no es el
 *      cauteloso. Si no, queda la tarjeta y el modelo pide el sí UNA vez, con los datos exactos.
 *
 * 🔴 QUÉ NO EJECUTA NUNCA ESTE SÍ, Y POR QUÉ. La persona dijo que sí a un TEXTO, no a una tarjeta: los datos
 * que arma el modelo al proponer pueden no ser exactamente los que leyó. Por eso solo entran tipos que no
 * mueven plata ni stock y se deshacen con otra carga igual —los avisos del dueño, la agenda, las imágenes y
 * el diseño del PDF, el reescaneo de una factura—. Todo lo que toca plata (venta, pago, gasto, compras,
 * presupuesto), stock, ARCA, las altas y ediciones genéricas, y lo que nunca se auto-ejecuta
 * (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES: borrados, masivas, permisos, envíos a clientes) sigue
 * esperando el sí sobre los datos exactos, como hoy. En "directo" eso no cambia nada: lo que ese modo
 * ejecuta solo ya se ejecuta en quizas_auto_confirmar(). En "cauteloso" (o sin modo legible) este sí no
 * ejecuta nada: ese dueño eligió confirmar cada carga sobre lo que se va a registrar.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class SiQueRearmaLaCargaIaHelper
{
    /** Cuánto puede tener la pregunta para que el sí la conteste (la misma ventana que la determinista). */
    const MINUTOS_MAXIMOS = ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS;

    /**
     * Los tipos que el sí ejecuta al rearmarse: no tocan plata ni stock y se deshacen con otra carga igual.
     * Agregar uno acá es decir «esto se registra con un sí a un texto»: ver el 🔴 del docblock.
     *
     * @var array<int, string>
     */
    const TIPOS_QUE_EJECUTA_EL_SI = [
        AiMessageAction::TIPO_AVISOS_DEL_DUENO,
        AiMessageAction::TIPO_TAREA_NUEVA,
        AiMessageAction::TIPO_TAREA_EDITAR,
        AiMessageAction::TIPO_TAREA_COMPLETAR,
        AiMessageAction::TIPO_FOTO_SUCURSAL,
        AiMessageAction::TIPO_IMAGENES_CATEGORIAS,
        AiMessageAction::TIPO_IMAGENES_ARTICULOS,
        AiMessageAction::TIPO_DISENO_PDF,
        AiMessageAction::TIPO_REINTENTO_ESCANEO,
    ];

    /** Los estados de una carga que sigue viva: esperando el sí, ejecutándose o ya hecha. */
    const ESTADOS_VIVOS = [
        AiMessageAction::ESTADO_PROPUESTA,
        AiMessageAction::ESTADO_EN_CURSO,
        AiMessageAction::ESTADO_CONFIRMADA,
    ];

    /**
     * true si el mensaje que se está respondiendo es un sí a una pregunta de carga que no tenía nada armado.
     * Nunca lanza: si algo falla al mirar, false y el turno sigue como siempre.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return bool
     */
    public static function aplica(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {
            if (! $assistant_message->acciones_habilitadas || ! $assistant_message->es_de_whatsapp()) {
                return false;
            }

            $pedido = AiMessage::where('ai_conversation_id', $conversation->id)
                               ->where('rol', 'user')
                               ->where('id', '<', $assistant_message->id)
                               ->orderBy('id', 'DESC')
                               ->first();

            if (is_null($pedido) || ! ConfirmacionDeterministaIaHelper::es_afirmacion($pedido->contenido)) {
                return false;
            }

            /* Con foto puede ser el pedido de OTRA carga (lo mismo que mira la determinista). */
            if (AiMessageImagen::where('ai_message_id', $pedido->id)->exists()) {
                return false;
            }

            $anterior = AiMessage::where('ai_conversation_id', $conversation->id)
                                 ->where('rol', 'assistant')
                                 ->where('id', '<', $pedido->id)
                                 ->orderBy('id', 'DESC')
                                 ->first();

            if (is_null($anterior) || $anterior->estado !== 'listo') {
                return false;
            }

            if (is_null($anterior->created_at) || is_null($pedido->created_at)
                || $anterior->created_at->lt(Carbon::parse($pedido->created_at)->subMinutes(self::MINUTOS_MAXIMOS))) {
                return false;
            }

            $texto = (string) $anterior->contenido;

            if (! CargaSinPropuestaIaHelper::termina_pidiendo_confirmar($texto)
                || EnvioSinPropuestaIaHelper::pide_confirmar_un_envio($texto)) {
                return false;
            }

            return ! self::dejo_una_carga_viva($anterior);

        } catch (\Throwable $e) {
            Log::warning('SiQueRearmaLaCargaIaHelper: no se pudo revisar el sí sin propuesta; sigue el turno normal.', [
                'ai_message_id' => (int) $assistant_message->id,
                'error'         => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * La nota que viaja pegada al sí de la persona.
     *
     * @return string
     */
    public static function nota()
    {
        return '[El sistema: la persona te contestó que SÍ a lo que le preguntaste en tu mensaje anterior, y en ese mensaje no habías armado '
            . 'ninguna propuesta. No le vuelvas a preguntar lo mismo ni le pidas que confirme otra vez: llamá AHORA a la herramienta proponer_ '
            . 'que corresponde, con los MISMOS datos que le dijiste. Si la herramienta te devuelve que quedó hecho, contalo con lo que dice '
            . '`resultado`, sin preguntar nada. Si te devuelve que quedó esperando el sí (lo que toca plata, stock o ARCA, o lo que borra), decile '
            . 'en una línea que lo dejaste listo con los datos exactos y pedile el sí una sola vez, aclarando que es el que lo registra. '
            . 'No le vuelvas a pedir datos que ya te dio.]';
    }

    /**
     * true si la propuesta que el modelo acaba de armar en este turno se ejecuta con el sí de la persona.
     * La llama HerramientasDeCarga::quizas_auto_confirmar() cuando el modo de confianza no la ejecuta solo.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  string  $tipo  El tipo de la tarjeta recién propuesta.
     * @param  string  $confianza  El modo GUARDADO del dueño (ConfianzaDelAgenteIaHelper::guardada()).
     * @return bool
     */
    public static function ejecuta(AiConversation $conversation, $assistant_message, $tipo, $confianza)
    {
        if (! $assistant_message instanceof AiMessage) {
            return false;
        }

        $tipo = (string) $tipo;

        if (! in_array($tipo, self::TIPOS_QUE_EJECUTA_EL_SI, true)
            || in_array($tipo, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES, true)) {
            return false;
        }

        if (! in_array((string) $confianza, [ConfianzaDelAgenteIaHelper::RESUELTO, ConfianzaDelAgenteIaHelper::DIRECTO], true)) {
            return false;
        }

        /* Un sí, una carga: solo la PRIMERA propuesta del turno (la que se acaba de crear es la única). */
        if (AiMessageAction::where('ai_message_id', $assistant_message->id)->count() !== 1) {
            return false;
        }

        return self::aplica($conversation, $assistant_message);
    }

    /**
     * true si el mensaje dejó una carga viva (esperando el sí sin vencer, ejecutándose o ya hecha).
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return bool
     */
    protected static function dejo_una_carga_viva(AiMessage $mensaje)
    {
        return AiMessageAction::where('ai_message_id', $mensaje->id)
                              ->whereIn('estado', self::ESTADOS_VIVOS)
                              ->get()
                              ->contains(function ($accion) {
                                  return $accion->estado_guardado() !== AiMessageAction::ESTADO_PROPUESTA || ! $accion->vencio();
                              });
    }
}
