<?php

namespace App\Services;

use App\Exceptions\SaleWhatsappSendException;
use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Http\Controllers\Helpers\WhatsappTemplateStandardHelper;
use App\Models\Client;
use App\Models\Sale;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;

/**
 * Envío del comprobante de una venta al cliente por el agente de WhatsApp (grupo 137,
 * Prompt 05). Método único público `send_sale()`: resuelve (o crea) el `WhatsappChat` del
 * cliente de la venta, arma la URL del PDF con su token (la misma ruta `sale/pdf/{id}` que
 * usa el botón `wa.me` de empresa-spa, más `?t=`) y decide entre `send_document`
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
     * La plantilla estándar de MENSAJE LIBRE (utility: Nombre, Negocio, Mensaje), con la que salen el
     * presupuesto y el recibo cuando la ventana de 24 h está cerrada (misión asistente-envios-a-clientes,
     * corrección del chequeo del 16/10/2026).
     *
     * 🔴 POR QUÉ NO `cc_cli_comprobante`: su cuerpo aprobado dice "te enviamos el comprobante X de tu
     * compra… ¡Gracias por tu compra!", que es FALSO para un presupuesto (todavía no compró nada) y raro
     * para un recibo. Con mensaje libre el texto dice lo que es y lleva el link del PDF con su token.
     *
     * @var string
     */
    const TEMPLATE_MENSAJE_LIBRE = 'cc_cli_mensaje_libre';

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

        // Misma ruta que WhatsappBtn.vue / ComercioCityMailHelper::new_sale (sale/pdf/{id}), con su token.
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
     * Envía al cliente un comprobante que NO es una venta —un presupuesto o un recibo— por el MISMO
     * molde que `send_sale()` (misión asistente-envios-a-clientes, 16/10/2026): ventana de 24 h
     * abierta → `send_document` con el epígrafe que manda el que llama; cerrada → la plantilla
     * `cc_cli_comprobante` [Nombre, Nº de comprobante, Negocio] con el PDF como header DOCUMENT.
     *
     * 🔴 ES UN MÉTODO HERMANO Y NO UNA GENERALIZACIÓN DE `send_sale()`, A PROPÓSITO. `send_sale()` lo
     * usan el botón del modal de Ventas (`SaleController@send_whatsapp_agent`) y el job automático
     * (`SendSaleWhatsappJob`), y su comportamiento —el epígrafe, el nombre del archivo, el `source`
     * de la fila— no puede cambiar por esta misión. Lo que sí se comparte es todo lo que no es de la
     * venta: la resolución del chat (`resolve_chat_del_cliente()`), el corte si Kapso no confirmó
     * (`fallar_si_no_hubo_envio()`) y la persistencia (`store_outbound_document_message()`), que son
     * los mismos tres pasos, con las mismas guardas y en el mismo orden.
     *
     * El que llama arma la URL del PDF CON su token (`PdfLinkHelper::con_token()`): cada comprobante
     * tiene su ruta y su tipo de token, y este service no tiene por qué saber cuál es cuál.
     *
     * @param  Client       $client           Cliente al que va (el del comprobante).
     * @param  int          $owner_id         Dueño del negocio (`user_id` del comprobante).
     * @param  string       $pdf_url          URL pública del PDF, ya con su token.
     * @param  string       $filename         Nombre del archivo que ve el cliente ('presupuesto-12.pdf').
     * @param  string       $caption          Epígrafe del documento con la ventana abierta.
     * @param  string       $mensaje          El {{3}} de `cc_cli_mensaje_libre` con la ventana cerrada:
     *                                        qué es y el link del PDF ("te mandamos el presupuesto N° 12.
     *                                        Lo ves acá: https://…").
     * @param  int|null     $sent_by_user_id  Persona que lo disparó (la del asistente).
     * @param  string       $sin_telefono     Mensaje del corte 'sin_telefono', con el nombre del comprobante.
     * @return WhatsappChatMessage  El mensaje `out` ya persistido.
     *
     * @throws SaleWhatsappSendException  Los mismos códigos que `send_sale()`.
     */
    public function send_comprobante(Client $client, $owner_id, $pdf_url, $filename, $caption, $mensaje, $sent_by_user_id = null, $sin_telefono = 'El comprobante no tiene un cliente con teléfono cargado.')
    {
        $owner_id = (int) $owner_id;

        // Configuración activa del bot del owner: sin ella no hay `phone_number_id`/`kapso_api_key` para enviar nada.
        $config = WhatsappBotConfig::getForUser($owner_id);
        if (is_null($config)) {
            throw new SaleWhatsappSendException('No hay una configuración de WhatsApp activa para esta empresa.', 'sin_configuracion');
        }
        $config->loadMissing('user');

        $chat = $this->resolve_chat_del_cliente($client, $owner_id, $config, $sin_telefono);

        $client_name = ! empty($client->name) ? $client->name : 'Cliente';
        $business_name = trim((string) ($config->user->name ?: '')) ?: 'nuestro negocio';

        $send_service = new WhatsappBotSendService();

        if ($chat->is_within_service_window()) {
            // Ventana abierta: el documento directo, con el epígrafe que armó el que llama.
            $wa_message_id = $send_service->send_document($chat->phone, $pdf_url, $filename, $caption, $config);

            // 🔴 Antes de persistir, igual que en send_sale(): un null es "no salió".
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

        // Ventana cerrada: la plantilla de MENSAJE LIBRE, con el link del PDF en el texto (ver TEMPLATE_MENSAJE_LIBRE).
        $template = $this->plantilla_aprobada($owner_id, self::TEMPLATE_MENSAJE_LIBRE);

        if (is_null($template)) {
            throw new SaleWhatsappSendException('La plantilla de mensaje libre todavía no está aprobada en Meta.', 'plantilla_no_aprobada');
        }

        $variables = $this->variables_del_mensaje_libre($client_name, $business_name, $mensaje);

        $wa_message_id = $send_service->send_template($chat->phone, $template, $variables, $config);

        $this->fallar_si_no_hubo_envio($wa_message_id, 'plantilla');

        return WhatsappChatHelper::store_outbound_template_message(
            $chat,
            $template->render_body($variables),
            $wa_message_id,
            $sent_by_user_id,
            $template->meta_template_name
        );
    }

    /**
     * Cómo saldría HOY un comprobante por WhatsApp a este cliente, SIN mandar nada ni crear ninguna
     * fila (misión asistente-envios-a-clientes): para que la tarjeta del asistente diga, antes del sí,
     * a qué número va, si sale como mensaje o como plantilla, y con qué texto — o por qué no se puede.
     *
     * Responde las MISMAS preguntas que `send_sale()` / `send_comprobante()` en el MISMO orden
     * (configuración → teléfono → ventana → plantilla aprobada), pero de solo lectura: busca el chat
     * igual que `resolve_chat_del_cliente()` (por cliente, después por teléfono exacto) y no lo crea.
     * Es informativo: la autoridad es el envío, que vuelve a mirar todo al confirmar.
     *
     * @param  int          $owner_id
     * @param  Client|null  $client
     * @param  string       $plantilla  La que se usaría con la ventana cerrada: `cc_cli_comprobante`
     *                                  para la venta, TEMPLATE_MENSAJE_LIBRE para presupuesto y recibo.
     * @return array  {puede: bool, camino: 'mensaje'|'plantilla'|null, telefono: string|null,
     *                 motivo: string|null, mensaje: string|null}
     */
    public function como_saldria($owner_id, $client, $plantilla = self::TEMPLATE_META_NAME)
    {
        $owner_id = (int) $owner_id;

        $config = WhatsappBotConfig::getForUser($owner_id);

        if (is_null($config)) {
            return $this->no_saldria('sin_configuracion', 'El negocio no tiene WhatsApp conectado (no hay una configuración activa).');
        }

        $phone = WhatsappPhoneHelper::normalize(! is_null($client) ? $client->phone : null);

        if ($phone === '') {
            return $this->no_saldria('sin_telefono', 'El cliente no tiene teléfono cargado.');
        }

        $chat = $this->buscar_chat_del_cliente($client, $owner_id, $phone);

        $telefono = is_null($chat) ? $phone : (string) $chat->phone;

        if (! is_null($chat) && $chat->is_within_service_window()) {
            return ['puede' => true, 'camino' => 'mensaje', 'telefono' => $telefono, 'motivo' => null, 'mensaje' => null];
        }

        if (is_null($this->plantilla_aprobada($owner_id, $plantilla))) {
            return $this->no_saldria(
                'plantilla_no_aprobada',
                'El cliente no le escribió al negocio en las últimas 24 horas y la plantilla de '
                . ($plantilla === self::TEMPLATE_MENSAJE_LIBRE ? 'mensaje libre' : 'comprobante')
                . ' todavía no está aprobada en Meta.'
            );
        }

        return ['puede' => true, 'camino' => 'plantilla', 'telefono' => $telefono, 'motivo' => null, 'mensaje' => null];
    }

    /**
     * El texto EXACTO que lleva la plantilla `cc_cli_comprobante` para este cliente y este número,
     * ya renderizado: el de la plantilla del negocio si existe y, si no, el cuerpo estándar del
     * catálogo (solo para mostrar: sin plantilla aprobada no se manda). Lo usa la tarjeta del
     * asistente para mostrar lo que sale antes del sí.
     *
     * @param  int     $owner_id
     * @param  string  $client_name
     * @param  string  $numero
     * @param  string  $business_name
     * @return string
     */
    public function texto_de_la_plantilla($owner_id, $client_name, $numero, $business_name)
    {
        $variables = $this->variables_de_la_plantilla($client_name, $numero, $business_name);

        $template = WhatsappTemplate::where('user_id', (int) $owner_id)
            ->where('meta_template_name', self::TEMPLATE_META_NAME)
            ->first();

        if (! is_null($template)) {
            return $template->render_body($variables);
        }

        $body = WhatsappTemplateStandardHelper::body_preview_de(self::TEMPLATE_META_NAME);

        foreach ($variables as $index => $valor) {
            $body = str_replace('{{'.($index + 1).'}}', (string) $valor, $body);
        }

        return $body;
    }

    /**
     * El texto EXACTO de `cc_cli_mensaje_libre` para un presupuesto o un recibo, ya renderizado: el de
     * la plantilla del negocio si existe y, si no, el cuerpo estándar del catálogo. Lo usa la tarjeta.
     *
     * @param  int     $owner_id
     * @param  string  $client_name
     * @param  string  $business_name
     * @param  string  $mensaje
     * @return string
     */
    public function texto_del_mensaje_libre($owner_id, $client_name, $business_name, $mensaje)
    {
        return $this->renderizar(
            $owner_id,
            self::TEMPLATE_MENSAJE_LIBRE,
            $this->variables_del_mensaje_libre($client_name, $business_name, $mensaje)
        );
    }

    /**
     * El nombre del negocio como lo firman los mensajes de comprobante: el `name` del dueño, como
     * send_sale(). Público para que la tarjeta muestre el mismo.
     *
     * @param  \App\Models\User|null  $owner
     * @return string
     */
    public function nombre_del_negocio($owner)
    {
        $nombre = is_null($owner) ? '' : trim((string) $owner->name);

        return $nombre === '' ? 'nuestro negocio' : $nombre;
    }

    /**
     * Las variables de `cc_cli_mensaje_libre` [Nombre, Negocio, Mensaje], limpias para Meta: un
     * parámetro con saltos de línea, tabulaciones o cuatro espacios seguidos lo rechaza y el envío
     * volvería null.
     *
     * @param  string  $client_name
     * @param  string  $business_name
     * @param  string  $mensaje
     * @return array<int, string>
     */
    private function variables_del_mensaje_libre($client_name, $business_name, $mensaje)
    {
        return [self::para_meta($client_name), self::para_meta($business_name), self::para_meta($mensaje)];
    }

    /**
     * Un texto apto para una variable de plantilla de Meta: sin saltos de línea ni tabulaciones, sin
     * espacios repetidos y sin el punto final (el cuerpo de la plantilla ya pone uno después).
     *
     * @param  string  $texto
     * @return string
     */
    public static function para_meta($texto)
    {
        $texto = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $texto);

        return rtrim(trim(preg_replace('/ {2,}/', ' ', $texto)), '.');
    }

    /**
     * El cuerpo de una plantilla con sus variables: el de la plantilla del negocio si existe, o el
     * estándar del catálogo si todavía no la tiene (solo para mostrar).
     *
     * @param  int     $owner_id
     * @param  string  $meta_template_name
     * @param  array   $variables
     * @return string
     */
    private function renderizar($owner_id, $meta_template_name, array $variables)
    {
        $template = WhatsappTemplate::where('user_id', (int) $owner_id)
            ->where('meta_template_name', $meta_template_name)
            ->first();

        if (! is_null($template)) {
            return $template->render_body($variables);
        }

        $body = WhatsappTemplateStandardHelper::body_preview_de($meta_template_name);

        foreach ($variables as $index => $valor) {
            $body = str_replace('{{'.($index + 1).'}}', (string) $valor, $body);
        }

        return $body;
    }

    /**
     * Las variables de `cc_cli_comprobante`, en el orden que declara WhatsappTemplateStandardHelper:
     * [Nombre, Nº de comprobante, Negocio].
     *
     * @param  string  $client_name
     * @param  string  $numero
     * @param  string  $business_name
     * @return array<int, string>
     */
    private function variables_de_la_plantilla($client_name, $numero, $business_name)
    {
        return [(string) $client_name, (string) $numero, (string) $business_name];
    }

    /**
     * Una plantilla del owner (por defecto `cc_cli_comprobante`), solo si ya está aprobada en Meta.
     *
     * @param  int     $owner_id
     * @param  string  $meta_template_name
     * @return WhatsappTemplate|null
     */
    private function plantilla_aprobada($owner_id, $meta_template_name = self::TEMPLATE_META_NAME)
    {
        $template = WhatsappTemplate::where('user_id', (int) $owner_id)
            ->where('meta_template_name', $meta_template_name)
            ->first();

        if (is_null($template) || $template->status !== 'aprobada') {
            return null;
        }

        return $template;
    }

    /**
     * La respuesta de como_saldria() cuando no sale.
     *
     * @param  string  $motivo
     * @param  string  $mensaje
     * @return array
     */
    private function no_saldria($motivo, $mensaje)
    {
        return ['puede' => false, 'camino' => null, 'telefono' => null, 'motivo' => $motivo, 'mensaje' => $mensaje];
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
        return $this->resolve_chat_del_cliente($sale->client, $owner_id, $config, 'La venta no tiene un cliente con teléfono cargado.');
    }

    /**
     * Lo que hacía `resolve_chat()` adentro, sacado tal cual para que `send_comprobante()` lo use
     * con el cliente de un presupuesto o de un recibo (misión asistente-envios-a-clientes). Para la
     * venta no cambia nada: mismo orden de búsqueda, mismo alta y mismo mensaje del corte.
     *
     * @param  Client|null        $client
     * @param  int                $owner_id
     * @param  WhatsappBotConfig  $config
     * @param  string             $sin_telefono  Mensaje del corte 'sin_telefono'.
     * @return WhatsappChat
     *
     * @throws SaleWhatsappSendException  Código 'sin_telefono' si no hay cliente con teléfono cargado.
     */
    private function resolve_chat_del_cliente($client, $owner_id, WhatsappBotConfig $config, $sin_telefono)
    {
        $phone = WhatsappPhoneHelper::normalize(! is_null($client) ? $client->phone : null);

        if ($phone === '') {
            throw new SaleWhatsappSendException($sin_telefono, 'sin_telefono');
        }

        $chat = $this->buscar_chat_del_cliente($client, $owner_id, $phone);

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
     * El chat existente del cliente, sin crear nada: primero por `client_id`, después por el
     * teléfono exacto. Es la búsqueda que hacía `resolve_chat()` desde siempre, compartida con
     * `como_saldria()` para que la vista previa y el envío miren el MISMO chat.
     *
     * @param  Client|null  $client
     * @param  int          $owner_id
     * @param  string       $phone  Ya normalizado.
     * @return WhatsappChat|null
     */
    private function buscar_chat_del_cliente($client, $owner_id, $phone)
    {
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

        return $chat;
    }

    /**
     * La URL del PDF que send_sale() ADJUNTA por WhatsApp (misión asistente-envios-a-clientes, segundo
     * chequeo): es otra que la del mail (esa lleva `pdf_column_profile_id`), y la tarjeta del asistente
     * muestra cada una en su canal. Es build_pdf_url() tal cual, expuesta.
     *
     * @param  Sale  $sale
     * @return string
     */
    public function url_del_pdf_que_adjunta(Sale $sale)
    {
        $sale->loadMissing('user');

        return $this->build_pdf_url($sale);
    }

    /**
     * URL del PDF de la venta: la ruta `sale/pdf/{id}` de `routes/web.php` (la misma del botón
     * `wa.me` de la SPA) con el token del link (`?t=`, PdfLinkHelper).
     *
     * 🔴 EL TOKEN NO ES OPCIONAL. Desde la misión pdf-de-venta-publico (10/10/2026) esa ruta ya no
     * es pública: se sirve con la sesión del comercio o con un token válido para ESA venta, y acá
     * no hay sesión — el archivo lo baja Meta/Kapso desde su servidor (header DOCUMENT de la
     * plantilla `cc_cli_comprobante` o `send_document`), y después lo abre el cliente final. Sin
     * token, pasada la ventana de transición del comercio, Meta recibiría un 404 y el comprobante
     * no saldría. No se usa una URL firmada de Laravel (`URL::temporarySignedRoute`): no sobrevive
     * a un cambio de `APP_KEY` ni a la rotación de frentes, y no se puede revocar.
     *
     * @param  Sale  $sale
     * @return string
     */
    private function build_pdf_url(Sale $sale)
    {
        $base_url = rtrim((string) (! is_null($sale->user) ? $sale->user->api_url : ''), '/');

        return PdfLinkHelper::con_token($base_url.'/sale/pdf/'.$sale->id, 'sale', $sale->id, $sale->user_id);
    }
}
