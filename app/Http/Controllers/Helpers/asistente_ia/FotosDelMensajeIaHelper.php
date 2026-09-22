<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;

/**
 * Las fotos que el DUEÑO mandó, tal como las ve el chat del sistema (misión
 * asistente-capacidades-y-hilos, P5, 22/9/2026).
 *
 * El pedido de Lucas, textual: "Las fotos que le mando por whatsapp no las veo en el chat del
 * sistema". Y no es que no lleguen: en demo3 las cuatro están en disco con su fila en
 * `ai_message_imagenes`. El hueco estaba en que el API no las exponía.
 *
 * El contrato es `[{id, orden, url}]`, SIEMPRE lista, nunca null y nunca ausente, en cada mensaje
 * de `GET api/ai-conversations/{id}/messages` y de `GET .../messages/{msg_id}`. Lo garantiza
 * AiMessage::toArray(), así que no hay una punta que se pueda olvidar mañana.
 *
 * 🔴 LA `url` ES UN ENDPOINT AUTENTICADO, NUNCA UNA RUTA PÚBLICA NI UN LINK FIRMADO ETERNO. El
 * binario vive en el disco `local` (privado) y son fotos del negocio: facturas de proveedores con
 * CUIT, razón social y precios de compra. El endpoint es AiConversationController::imagen_de_mensaje(),
 * gateado igual que el resto del chat y con la misma tenencia doble.
 *
 * Y NO se le pasa `path` a nadie: la ruta del disco no le sirve a la SPA y decirla es contarle al
 * navegador cómo está organizado el storage del cliente.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class FotosDelMensajeIaHelper
{
    /**
     * Prefijo de la ruta que sirve el binario. Vive acá porque la arma este helper y la declara
     * `routes/api.php`: dos lugares, un solo literal.
     *
     * La ruta completa es `api/ai-mensajes/{ai_message_id}/imagen/{orden}`.
     */
    const RUTA = 'api/ai-mensajes';

    /**
     * Las fotos de un mensaje con la forma exacta del contrato, o `[]`.
     *
     * 🔴 Usa la relación YA CARGADA cuando está: el índice de mensajes pagina de a 30 y sin esto
     * serían 30 consultas por página. Los controllers del chat la traen con `with('imagenes')`;
     * el `user_message` / `assistant_message` recién creados del POST no, y ahí una consulta por
     * mensaje es el precio correcto (dos consultas indexadas contra una respuesta que además
     * despacha un job).
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<int, array<string, mixed>>
     */
    public static function del_mensaje(AiMessage $mensaje)
    {
        $id_mensaje = (int) $mensaje->id;

        /* Un modelo sin id todavía no puede tener fotos colgadas, y la consulta traería basura. */
        if ($id_mensaje <= 0) {

            return [];
        }

        $imagenes = $mensaje->relationLoaded('imagenes')
            ? $mensaje->getRelation('imagenes')
            : $mensaje->imagenes()->get();

        $fotos = [];

        foreach ($imagenes as $imagen) {

            $orden = (int) $imagen->orden;

            $fotos[] = [
                'id'    => (int) $imagen->id,
                'orden' => $orden,
                'url'   => self::url($id_mensaje, $orden),
            ];
        }

        return $fotos;
    }

    /**
     * La URL absoluta del binario de una foto.
     *
     * 🔴 Se arma con `url()` y no con `config('app.url')` ni con `users.api_url`: el helper de
     * Laravel la deriva del request que está corriendo, que es el mismo origen con el que la SPA
     * pegó el índice de mensajes. Así funciona igual en una instalación con `/public` en la URL,
     * en localhost con el puerto del slot y detrás del proxy de producción — sin depender de que
     * ningún `.env` de cliente tenga cargada una variable que hoy casi ninguno tiene (la clase
     * "la URL que un sistema le entrega a otro, armada con APP_URL" de APRENDER_NO_PARCHEAR.md).
     *
     * @param  int  $ai_message_id
     * @param  int  $orden
     * @return string
     */
    public static function url($ai_message_id, $orden)
    {
        return url(self::RUTA . '/' . (int) $ai_message_id . '/imagen/' . (int) $orden);
    }
}
