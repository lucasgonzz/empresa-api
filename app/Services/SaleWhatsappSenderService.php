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
 * como condición esperada sin relanzarla).
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
     * Envía el comprobante de la venta al cliente vinculado.
     *
     * @param  Sale      $sale             Venta a enviar (debe tener `client_id` con teléfono).
     * @param  int|null  $sent_by_user_id  Empleado autenticado que dispara el envío manual;
     *                                     null cuando el envío es automático (job).
     * @param  bool      $forzar           Reservado para un futuro reenvío forzado; sin uso
     *                                     todavía (el envío hoy siempre se intenta una vez).
     * @return WhatsappChatMessage  El mensaje `out` ya persistido.
     *
     * @throws SaleWhatsappSendException  Ante cualquier condición esperable que impida el envío.
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
