<?php

namespace App\Services;

use App\Exceptions\SaleWhatsappSendException;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Models\Sale;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;

/**
 * Envío del comprobante de una venta al cliente por el agente de WhatsApp (grupo 137,
 * Prompt 05). Método único público `send_sale()`: resuelve (o crea) el `WhatsappChat` del
 * cliente de la venta, arma la URL pública del PDF (la misma ruta `sale/pdf/{id}` que ya
 * usa hoy el botón `wa.me` histórico de empresa-spa) y decide entre `send_document`
 * (ventana de 24 h abierta) o la plantilla estándar `cc_cli_comprobante` con header
 * DOCUMENT (ventana cerrada, Prompt 04).
 *
 * Ante cualquier condición esperable que impida el envío, lanza
 * `SaleWhatsappSendException` con un código estable en vez de devolver null en silencio:
 * la consumen tanto el endpoint manual (`SaleController@send_whatsapp_agent`, que la
 * traduce en un 422) como el job automático opt-in (`SendSaleWhatsappJob`, que la loguea
 * sin relanzarla).
 *
 * Los códigos son de dos clases y el job NO los loguea igual: los de condición esperable del
 * negocio (`sin_configuracion`, `sin_telefono`, `plantilla_no_aprobada`) van como info, y
 * `CODE_ENVIO_NO_CONFIRMADO` va como error, porque ahí el comprobante tenía que salir y no
 * salió. Ese último cierra un bug preexistente: antes del 15/8/2026 el null que devuelven
 * `send_document()`/`send_template()` no se miraba, así que un fallo de Kapso se persistía y
 * se logueaba como envío exitoso.
 */
class SaleWhatsappSenderService
{
    /**
     * Nombre técnico de la plantilla estándar de comprobante creada en el Prompt 04
     * (`WhatsappTemplateStandardHelper`).
     *
     * @var string
     */
    const TEMPLATE_META_NAME = 'cc_cli_comprobante';

    /**
     * Código de `SaleWhatsappSendException` para "Kapso no confirmó el envío": el service de
     * envío devolvió null, o sea que el comprobante NO salió (clave vencida, Kapso caído,
     * rate limit, número rechazado por Meta) o salió pero Kapso no informó el `wa_message_id`.
     *
     * Se distingue de los otros códigos porque NO es una condición esperable del negocio
     * (`sin_telefono`, `plantilla_no_aprobada`): es una falla de infraestructura, y
     * `SendSaleWhatsappJob` la loguea como error y no como condición controlada.
     *
     * @var string
     */
    const CODE_ENVIO_NO_CONFIRMADO = 'envio_no_confirmado';

    /**
     * Envía el comprobante de la venta al cliente vinculado.
     *
     * @param  Sale      $sale             Venta a enviar (debe tener `client_id` con teléfono).
     * @param  int|null  $sent_by_user_id  Empleado autenticado que dispara el envío manual;
     *                                     null cuando el envío es automático (job).
     * @param  bool      $forzar           Reservado para un futuro reenvío forzado; sin uso
     *                                     todavía (el envío hoy siempre se intenta una vez).
     * @return WhatsappChatMessage  El mensaje `out` ya persistido.
     *
     * @throws SaleWhatsappSendException  Ante cualquier condición esperable que impida el envío,
     *                                    y también si Kapso no confirmó el envío
     *                                    (`CODE_ENVIO_NO_CONFIRMADO`): el mensaje NO se persiste
     *                                    si no hubo envío.
     */
    public function send_sale(Sale $sale, $sent_by_user_id = null, $forzar = false)
    {
        $sale->loadMissing('client', 'user');

        $owner_id = (int) $sale->user_id;

        // Configuración activa del bot del owner: sin ella no hay `phone_number_id`/`kapso_api_key` para enviar nada.
        $config = WhatsappBotConfig::getForUser($owner_id);
        if (is_null($config)) {
            throw new SaleWhatsappSendException('No hay una configuración de WhatsApp activa para esta empresa.', 'sin_configuracion');
        }
        $config->loadMissing('user');

        $chat = $this->resolve_chat($sale, $owner_id, $config);

        // Misma URL pública que arma hoy WhatsappBtn.vue / ComercioCityMailHelper::new_sale (sale/pdf/{id}, sin auth).
        $pdf_url = $this->build_pdf_url($sale);
        $filename = 'venta-'.($sale->num ?: $sale->id).'.pdf';
        $client_name = (! is_null($sale->client) && ! empty($sale->client->name)) ? $sale->client->name : 'Cliente';
        $business_name = trim((string) ($config->user->name ?: '')) ?: 'nuestro negocio';

        $send_service = new WhatsappBotSendService();

        if ($chat->is_within_service_window()) {
            // Ventana abierta: se puede mandar el documento directo, sin pasar por una plantilla.
            $caption = 'Comprobante de tu compra en '.$business_name;
            $wa_message_id = $send_service->send_document($chat->phone, $pdf_url, $filename, $caption, $config);

            // 🔴 El null NO se puede ignorar: significa que el comprobante no salió. Hasta el
            // 15/8/2026 este camino persistía el mensaje igual y devolvía como si hubiera
            // salido, así que `SendSaleWhatsappJob` logueaba 'comprobante enviado
            // automáticamente' sobre un envío que nunca ocurrió.
            //
            // Esto es más viejo y más grande que la simulación que lo destapó: cualquier fallo
            // de Kapso (clave vencida, caída, rate limit, número rechazado por Meta) ya se daba
            // por enviado igual, porque `send_document()` se traga sus errores y devuelve null.
            // La simulación solo lo volvió alcanzable todos los días.
            //
            // Se lanza ANTES de persistir, igual que el resto de los fallos de este service: si
            // no hubo envío no queda una fila en la conversación diciendo que sí.
            $this->fallar_si_no_hubo_envio($wa_message_id, 'documento');

            return WhatsappChatHelper::store_outbound_document_message(
                $chat,
                $caption,
                $wa_message_id,
                $sent_by_user_id,
                is_null($sent_by_user_id) ? 'sistema' : 'manual',
                $pdf_url
            );
        }

        // Ventana cerrada: único camino permitido por Meta es la plantilla aprobada cc_cli_comprobante (Prompt 04).
        $template = WhatsappTemplate::where('user_id', $owner_id)
            ->where('meta_template_name', self::TEMPLATE_META_NAME)
            ->first();

        if (is_null($template) || $template->status !== 'aprobada') {
            throw new SaleWhatsappSendException('La plantilla de comprobante todavía no está aprobada en Meta.', 'plantilla_no_aprobada');
        }

        // Mismo orden que declara WhatsappTemplateStandardHelper para cc_cli_comprobante: [Nombre, Nº de comprobante, Negocio].
        $variables = [$client_name, (string) ($sale->num ?: $sale->id), $business_name];

        $wa_message_id = $send_service->send_template($chat->phone, $template, $variables, $config, $pdf_url, $filename);

        // Mismo chequeo que en la rama de ventana abierta, y por el mismo motivo: `send_template()`
        // también devuelve null cuando Kapso falla. El revisor marcó `send_document()`, pero el
        // job elige una rama u otra según la ventana de 24 h: tapar una sola dejaba el mismo log
        // mentiroso disponible la mitad de las veces.
        $this->fallar_si_no_hubo_envio($wa_message_id, 'plantilla');

        $rendered_body = $template->render_body($variables);

        return WhatsappChatHelper::store_outbound_document_message(
            $chat,
            $rendered_body,
            $wa_message_id,
            $sent_by_user_id,
            'plantilla',
            $pdf_url,
            $template->meta_template_name
        );
    }

    /**
     * Corta el envío si `WhatsappBotSendService` no devolvió un `wa_message_id`.
     *
     * Los tres métodos de ese service (`send_text`, `send_document`, `send_template`) se tragan
     * todos sus errores y devuelven null: loguean el detalle y no propagan nada. Ese contrato
     * está bien para el módulo de WhatsApp, donde cada caller decide qué hacer con el null,
     * pero acá el caller es una venta y el único final aceptable de "no salió" es un error
     * visible, no una fila persistida como enviada.
     *
     * Ojo con el borde: null también puede significar "salió pero Kapso no informó el id". Se
     * elige tratarlo como fallo igual. Un falso "no se envió" hace que alguien lo reintente y a
     * lo sumo el cliente reciba el comprobante dos veces; un falso "se envió" deja al cliente
     * sin comprobante y a nadie enterado, que es exactamente el bug que se está cerrando.
     *
     * @param  string|null  $wa_message_id  Lo que devolvió el service de envío.
     * @param  string       $camino         'documento' | 'plantilla', solo para el mensaje.
     * @return void
     *
     * @throws SaleWhatsappSendException  Código `envio_no_confirmado` si no hubo envío.
     */
    private function fallar_si_no_hubo_envio($wa_message_id, $camino)
    {
        if (! is_null($wa_message_id)) {
            return;
        }

        throw new SaleWhatsappSendException(
            'WhatsApp no confirmó el envío del comprobante ('.$camino.'). Revisá la clave de Kapso y el número de la empresa, y volvé a intentarlo.',
            self::CODE_ENVIO_NO_CONFIRMADO
        );
    }

    /**
     * Resuelve (o crea) el `WhatsappChat` del cliente de la venta, igual que
     * `WhatsappChatHelper::store_inbound_message` para el webhook. Si el chat ya existía
     * sin cliente vinculado y la venta sí tiene uno, lo vincula de paso.
     *
     * @param  Sale               $sale
     * @param  int                $owner_id
     * @param  WhatsappBotConfig  $config
     * @return WhatsappChat
     *
     * @throws SaleWhatsappSendException  Código 'sin_telefono' si la venta no tiene cliente con teléfono cargado.
     */
    private function resolve_chat(Sale $sale, $owner_id, WhatsappBotConfig $config)
    {
        $client = $sale->client;
        $phone = WhatsappPhoneHelper::normalize(! is_null($client) ? $client->phone : null);

        if ($phone === '') {
            throw new SaleWhatsappSendException('La venta no tiene un cliente con teléfono cargado.', 'sin_telefono');
        }

        $chat = null;
        if (! is_null($client)) {
            $chat = WhatsappChat::where('user_id', $owner_id)
                ->where('client_id', $client->id)
                ->first();
        }

        if (is_null($chat)) {
            $chat = WhatsappChat::where('user_id', $owner_id)
                ->where('phone', $phone)
                ->first();
        }

        if (is_null($chat)) {
            $chat = WhatsappChat::create([
                'user_id' => $owner_id,
                'phone' => $phone,
                'client_id' => ! is_null($client) ? $client->id : null,
                'ai_enabled' => (bool) $config->ai_enabled_default,
                'unread_count' => 0,
            ]);
        } elseif (is_null($chat->client_id) && ! is_null($client)) {
            // El chat existía (ej: el cliente ya había escrito antes) pero todavía no estaba vinculado.
            $chat->client_id = $client->id;
            $chat->save();
        }

        return $chat;
    }

    /**
     * URL pública del PDF de la venta: misma ruta que ya usa el botón `wa.me` histórico
     * (`sale/pdf/{id}` en `routes/web.php`, sin middleware de auth). No requiere firma
     * temporal (`URL::temporarySignedRoute`) porque la ruta ya es pública hoy.
     *
     * @param  Sale  $sale
     * @return string
     */
    private function build_pdf_url(Sale $sale)
    {
        $base_url = rtrim((string) (! is_null($sale->user) ? $sale->user->api_url : ''), '/');

        return $base_url.'/sale/pdf/'.$sale->id;
    }
}
