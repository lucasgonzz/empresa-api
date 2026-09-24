<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use Carbon\Carbon;

/**
 * DÓNDE BUSCAN SU FOTO LAS HERRAMIENTAS QUE USAN LAS FOTOS DEL DUEÑO (misión
 * asistente-fotos-barras-y-compras, 24/9/2026). Un solo lugar para las cuatro que las usan: la foto
 * de un artículo, la de una sucursal, la compra con factura y el alta de un artículo con su foto.
 *
 * 🔴 POR QUÉ SE SACÓ LA VENTANA DE 6 MENSAJES. Hasta hoy cada herramienta miraba sólo los últimos
 * seis mensajes de la conversación, y eso rompió en demo3 (conv 10, 24/9/2026): el dueño mandó la
 * foto, hubo un ida y vuelta, dijo "Dale", y cuando el asistente volvió a armar la asignación la
 * foto ya estaba siete mensajes atrás — seguía sin gestionar (`gestionada_at` NULL) y el asistente
 * le pidió que la reenviara. Una foto que la persona YA MANDÓ no se le vuelve a pedir: el corte ahora
 * es por TIEMPO (las últimas HORAS horas, el mismo vencimiento que una tarjeta en AiMessageAction)
 * y no por cantidad de mensajes, que depende de cuánto se charló en el medio.
 *
 * 🔴 SÓLO FOTOS DE MENSAJES `rol = user`. Desde esta misma misión, la búsqueda por código de barras
 * guarda la foto que encuentra en internet como una fila más de `ai_message_imagenes`, colgada del
 * mensaje del ASISTENTE en curso. Sin este filtro, esa foto de internet se tomaría como "la que me
 * mandaste" y terminaría asignada a otro artículo, o como página de una factura. La foto de
 * internet se usa sólo cuando el modelo la pide explícita por su `imagen_id` (ver
 * AltaDeArticuloConFotoIaHelper). No sacar el filtro "para simplificar".
 *
 * 🔴 Y LA COMPRA NO TOMA "TODAS LAS DE 24 HORAS": toma la ÚLTIMA TANDA (ver ultima_tanda()). Es la
 * guarda del hallazgo B del chequeo de la misión asistente-por-whatsapp —la góndola que el dueño
 * mandó a la mañana preguntando un precio no puede entrar como página de la factura que manda a la
 * tarde—, que antes sostenía la ventana de mensajes y ahora sostiene la tanda.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class FotosDeLaConversacionIaHelper
{
    /**
     * Hasta cuántas horas hacia atrás se busca una foto del dueño. Es el mismo vencimiento que una
     * tarjeta (AiMessageAction::HORAS_VENCIMIENTO): lo que el dueño mandó ayer a la mañana ya no es
     * "la foto que te acabo de mandar", y una tarjeta de esa hora tampoco se podría confirmar.
     */
    const HORAS = AiMessageAction::HORAS_VENCIMIENTO;

    /**
     * La foto más nueva que el dueño mandó en esta conversación y todavía no se usó, o null.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que está proponiendo.
     * @return \App\Models\AiMessageImagen|null
     */
    public static function la_mas_nueva(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        return self::sin_gestionar($contexto, $mensaje)
                    ->orderBy('ai_message_id', 'DESC')
                    ->orderBy('orden', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->first();
    }

    /**
     * Las fotos de la ÚLTIMA TANDA que mandó el dueño, en el orden en que las mandó.
     *
     * Una tanda son los mensajes del dueño CON FOTOS seguidos, contando hacia atrás desde el más
     * nuevo que tenga una foto sin usar: se corta en el primer mensaje del dueño SIN fotos. Las
     * respuestas del asistente en el medio no cortan, porque el admin despacha un turno por cada
     * mensaje que entra y una factura de tres páginas mandada en tres fotos llega intercalada con
     * las respuestas de esos turnos.
     *
     * 🔴 Es lo que reemplaza a la ventana de mensajes para la compra con factura: la distancia ya no
     * importa (la factura puede estar diez mensajes atrás, tras una charla sobre el proveedor), pero
     * una foto de otro momento de la charla —separada de la factura por un mensaje de texto del
     * dueño, como la góndola del hallazgo B— no se suma como página.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que está proponiendo.
     * @return array<int, \App\Models\AiMessageImagen>
     */
    public static function ultima_tanda(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        $mas_nueva = self::la_mas_nueva($contexto, $mensaje);

        if (is_null($mas_nueva)) {

            return [];
        }

        /*
         * Los mensajes del dueño desde el de la foto más nueva hacia atrás, dentro de las mismas
         * HORAS: se recorren hasta el primero sin ninguna foto.
         */
        $mensajes_del_dueno = AiMessage::where('ai_conversation_id', $contexto->conversation->id)
                                        ->where('rol', 'user')
                                        ->where('id', '<=', (int) $mas_nueva->ai_message_id)
                                        ->where('created_at', '>=', self::desde())
                                        ->orderBy('id', 'DESC')
                                        ->withCount('imagenes')
                                        ->get(['id']);

        $de_la_tanda = [];

        foreach ($mensajes_del_dueno as $del_dueno) {

            if ((int) $del_dueno->imagenes_count === 0) {

                break;
            }

            $de_la_tanda[] = (int) $del_dueno->id;
        }

        if (!count($de_la_tanda)) {

            return [];
        }

        return self::sin_gestionar($contexto, $mensaje)
                    ->whereIn('ai_message_id', $de_la_tanda)
                    ->orderBy('ai_message_id')
                    ->orderBy('orden')
                    ->orderBy('id')
                    ->get()
                    ->all();
    }

    /**
     * Una foto puntual por su id, si es de ESTA conversación, del dueño de la cuenta y todavía no se
     * usó. Sirve para la foto que devolvió otra herramienta (la búsqueda por código de barras la
     * cuelga del mensaje del asistente), así que acá NO se filtra por rol: lo que se exige es que
     * sea de esta conversación. El id lo manda el modelo, y un id suelto de otra conversación u
     * otro negocio no puede terminar publicado en este catálogo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $imagen_id
     * @return \App\Models\AiMessageImagen|null
     */
    public static function por_id(ContextoDeCargaIa $contexto, $imagen_id)
    {
        $imagen_id = is_numeric($imagen_id) ? (int) $imagen_id : 0;

        if ($imagen_id <= 0) {

            return null;
        }

        $conversation_id = (int) $contexto->conversation->id;

        return AiMessageImagen::where('id', $imagen_id)
                                ->where('user_id', $contexto->owner_id)
                                ->sinGestionar()
                                ->whereHas('message', function ($q) use ($conversation_id) {
                                    $q->where('ai_conversation_id', $conversation_id);
                                })
                                ->first();
    }

    /**
     * true si la foto la mandó el dueño (un mensaje `user`); false si la dejó el asistente (la
     * encontrada en internet por la búsqueda por código de barras). Es lo que la tarjeta dice para
     * que el dueño sepa qué foto va a quedar publicada.
     *
     * @param  \App\Models\AiMessageImagen  $imagen
     * @return bool
     */
    public static function la_mando_el_dueno(AiMessageImagen $imagen)
    {
        $rol = AiMessage::where('id', (int) $imagen->ai_message_id)->value('rol');

        return (string) $rol === 'user';
    }

    /**
     * "hoy a las 10:32" / "el 23/09 a las 18:05": cuándo llegó una foto, para la tarjeta. Con la
     * ventana de 24 horas la foto puede ser de hace un rato largo, y la persona tiene que poder
     * decir "esa no es" antes de confirmar.
     *
     * @param  \App\Models\AiMessageImagen  $imagen
     * @return string
     */
    public static function cuando_llego(AiMessageImagen $imagen)
    {
        $creada = $imagen->created_at;

        if (is_null($creada)) {

            return '';
        }

        $creada = Carbon::parse($creada);

        $dia = $creada->isToday() ? 'hoy' : 'el ' . $creada->format('d/m');

        return $dia . ' a las ' . $creada->format('H:i');
    }

    /**
     * Las fotos sin usar que el DUEÑO mandó en esta conversación en las últimas HORAS, hasta el
     * mensaje que está proponiendo. Ver los dos 🔴 del docblock de la clase.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function sin_gestionar(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        $conversation_id = (int) $contexto->conversation->id;
        $hasta = (int) $mensaje->id;

        return AiMessageImagen::where('user_id', $contexto->owner_id)
                                ->sinGestionar()
                                ->where('created_at', '>=', self::desde())
                                ->whereHas('message', function ($q) use ($conversation_id, $hasta) {
                                    $q->where('ai_conversation_id', $conversation_id)
                                      ->where('rol', 'user')
                                      ->where('id', '<=', $hasta);
                                });
    }

    /**
     * @return \Carbon\Carbon
     */
    protected static function desde()
    {
        return Carbon::now()->subHours(self::HORAS);
    }
}
