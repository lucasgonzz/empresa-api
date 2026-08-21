<?php

namespace App\Services;

use App\Exceptions\RecordatorioCobroException;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Http\Controllers\Helpers\WhatsappTemplateStandardHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;
use Carbon\Carbon;

/**
 * Recordatorio de cobro por WhatsApp que sale del módulo de alertas, solapa Cobros (misión
 * recordatorio-cobro-whatsapp). Mismo esqueleto que `SaleWhatsappSenderService`: resuelve el
 * `WhatsappChat` del cliente, arma URLs públicas de PDF y elige entre texto libre (ventana de
 * 24 h abierta) y la plantilla `cc_cli_recordatorio_cobro` con header DOCUMENT (ventana
 * cerrada).
 *
 * Cuatro cosas que definen este archivo y conviene tener presentes antes de tocarlo:
 *
 * 1. 🔴 `previsualizar()` Y `enviar()` COMPARTEN `armar_contenido()`. Ese es todo el punto de
 *    que el armado sea un método privado y no código repetido: el modal de previsualización
 *    muestra el mensaje EXACTO que va a salir. Si cada uno armara el suyo, alcanzaría con que
 *    alguien tocara una coma en un lado para que la pantalla mienta, y el operador aprieta
 *    "Enviar" confiando en lo que leyó.
 *
 * 2. 🔴 `fallar_si_no_hubo_envio()` VA EN LOS DOS CAMINOS. Los tres métodos de
 *    `WhatsappBotSendService` se tragan sus errores y devuelven null: ese null significa que
 *    el recordatorio NO salió (clave de Kapso vencida, servicio caído, rate limit, número
 *    rechazado por Meta), y se lanza ANTES de persistir. Es el bug que se cerró el 15/8/2026
 *    en `SaleWhatsappSenderService` y no se reabre: una fila persistida como enviada sobre un
 *    envío que nunca ocurrió deja al cliente sin aviso, a nadie enterado, y encima activa el
 *    freno de 24 h, así que el próximo intento tampoco sale.
 *
 * 3. Los códigos de error son de dos clases y el job NO los loguea igual. Sólo
 *    `CODE_ENVIO_NO_CONFIRMADO` es falla de infraestructura (`Log::error`); el resto son
 *    condiciones esperables del negocio (`Log::info`). Misma partición que documenta
 *    `SendSaleWhatsappJob`, y por el mismo motivo: si un cliente sin teléfono cargado
 *    generara un error, el canal se llena de ruido y el fallo de verdad deja de verse.
 *
 * 4. 🔴 `motivo_de_salteo()` Y `buscar_chat()` SON DE ACÁ Y LOS USA TAMBIÉN EL CONTROLLER. El
 *    masivo tiene que poder decir por qué NO le va a mandar a un cliente antes de encolar nada, y
 *    tiene que decir lo mismo que dijo la previsualización. Cuando cada uno resolvía lo suyo, la
 *    pantalla mostraba "se encolaron 40" y los 40 morían adentro de un `Log::info` del job.
 *
 * ⚠️ El texto libre pasa por `WhatsappBotSendService::send_text()`, que tiene el freno de la
 * simulación. Corresponde que lo lleve: el texto libre se apoya en la ventana de 24 h, que es
 * justamente lo que la simulación falsea. Con un chat en simulación el envío devuelve null y
 * acá se lanza `CODE_ENVIO_NO_CONFIRMADO`. Es ruidoso, y es lo que corresponde.
 */
class RecordatorioCobroSenderService
{
    /**
     * Nombre técnico de la plantilla estándar del recordatorio
     * (`WhatsappTemplateStandardHelper`, sexta entrada).
     *
     * @var string
     */
    const TEMPLATE_META_NAME = 'cc_cli_recordatorio_cobro';

    /**
     * Valor de `whatsapp_chat_messages.source` con el que quedan marcados los mensajes de esta
     * misión. 🔴 Es el freno anti-duplicado: `ya_recibio_recordatorio()` cuenta por acá. La
     * columna es `string(20)` y esto son 18 caracteres, por eso no hizo falta migración.
     *
     * @var string
     */
    const SOURCE = 'recordatorio_cobro';

    /**
     * Cuántas ventas se listan una por renglón en el texto libre. El resto se resuelve por el
     * PDF de cuenta corriente adjunto y una línea "y N ventas más" (decisión de Lucas): un
     * mensaje con 40 renglones no lo lee nadie y encima roza el límite de largo de WhatsApp.
     *
     * @var int
     */
    const MAX_VENTAS_LISTADAS = 5;

    /**
     * Cuántos movimientos entran en el PDF de cuenta corriente que se linkea/adjunta. Acota el
     * PDF de un cliente con años de historia sin dejarlo inútil.
     *
     * @var int
     */
    const CANTIDAD_MOVIMIENTOS_PDF = 60;

    /**
     * Kapso no confirmó el envío. 🔴 ÚNICO código de esta clase que es falla de
     * infraestructura: el recordatorio tenía que salir y no salió.
     *
     * @var string
     */
    const CODE_ENVIO_NO_CONFIRMADO = 'envio_no_confirmado';

    /** La empresa no tiene una configuración de WhatsApp activa. @var string */
    const CODE_SIN_CONFIGURACION = 'sin_configuracion';

    /** El cliente no tiene teléfono cargado. @var string */
    const CODE_SIN_TELEFONO = 'sin_telefono';

    /** Ventana cerrada y la plantilla todavía no está aprobada en Meta. @var string */
    const CODE_PLANTILLA_NO_APROBADA = 'plantilla_no_aprobada';

    /** Ya recibió el recordatorio en las últimas 24 h. @var string */
    const CODE_YA_ENVIADO = 'ya_recibio_hoy';

    /** No hay ventas sin cobrar del cliente en el filtro actual. @var string */
    const CODE_SIN_VENTAS = 'sin_ventas';

    /**
     * Ventana cerrada y el cliente no tiene ningún resumen de cuenta corriente que adjuntar.
     *
     * 🔴 ES UN SALTEO Y NO UN INTENTO, a propósito. Con la ventana cerrada el único camino es la
     * plantilla, y la plantilla está registrada en Meta con header DOCUMENT: si se manda sin el
     * componente `header`, Meta la rechaza, `send_template()` devuelve null y termina como un
     * "fallido" mudo que nadie puede accionar. Un salteo explicado ("este cliente no tiene
     * movimientos en su cuenta corriente") le dice al operador exactamente qué pasó.
     *
     * @var string
     */
    const CODE_SIN_RESUMEN_DE_CUENTA = 'sin_resumen_de_cuenta';

    /**
     * Arma la previsualización del recordatorio sin mandar nada ni crear ninguna fila.
     *
     * 🔴 NO LANZA por las condiciones del negocio, a diferencia de `enviar()`: devuelve
     * `puede_enviar = false` con `motivo` y `mensaje_motivo` cargados. El modal tiene que poder
     * MOSTRAR por qué no se puede mandar, y un 422 genérico ahí se lee como "error de
     * servidor". El único que sí corta antes de llegar acá es `sin_ventas`, y lo corta el
     * controller.
     *
     * ⚠️ Usa `buscar_chat()` y no `resolve_chat()`: previsualizar no puede crear un chat vacío
     * en la conversación de un cliente al que a lo mejor nunca se le manda nada.
     *
     * @param  int      $owner_id          Dueño de la empresa (`sales.user_id`).
     * @param  Client   $client            Cliente deudor.
     * @param  array|\Illuminate\Support\Collection  $ventas  Las ventas sin cobrar YA recortadas por el controller.
     * @param  int|null $sent_by_user_id   Empleado que está mirando (no se usa para armar, va por simetría con `enviar()`).
     * @return array  El payload de `preview` tal cual lo consume el modal.
     */
    public function previsualizar($owner_id, Client $client, $ventas, $sent_by_user_id = null)
    {
        $config = WhatsappBotConfig::getForUser((int) $owner_id);

        $chat = self::buscar_chat((int) $owner_id, $client);

        $ventana_abierta = ! is_null($chat) && $chat->is_within_service_window();

        $contenido = $this->armar_contenido((int) $owner_id, $client, $ventas, $ventana_abierta, $config);

        // 🔴 EL MISMO `motivo_de_salteo()` QUE USA EL MASIVO. Antes la pantalla resolvía el motivo
        // por su cuenta y el controller por la suya, así que las dos podían decir cosas distintas
        // del mismo cliente. Una regla, un lugar.
        $motivo = $this->motivo_de_salteo((int) $owner_id, $client, $config, $chat);

        return [
            'client_id'          => (int) $client->id,
            'client_name'        => $this->nombre_cliente($client),
            // 🔴 EL TELÉFONO QUE SE MUESTRA ES EL QUE VA A RECIBIR EL MENSAJE, no el de la ficha
            // del cliente. `enviar()` manda a `$chat->phone`: si alguien corrigió el teléfono en
            // la ficha y el chat quedó con el viejo, mostrar el de la ficha sería afirmar en
            // pantalla un número al que el mensaje NO va a llegar (y hoy puede ser de otra
            // persona). Sin chat todavía, el que se va a usar es el normalizado de la ficha,
            // porque es con ese que `resolve_chat()` va a crearlo.
            'phone'              => $this->phone_de_destino($client, $chat),
            'canal'              => $ventana_abierta ? 'texto_libre' : 'plantilla',
            'ventana_abierta'    => $ventana_abierta,
            'body'               => $contenido['body'],
            'template_meta_name' => $contenido['template_meta_name'],
            'template_variables' => $contenido['template_variables'],
            'documento_url'      => $contenido['documento_url'],
            'documento_filename' => $contenido['documento_filename'],
            'ventas_listadas'    => $contenido['ventas_listadas'],
            'ventas_restantes'   => $contenido['ventas_restantes'],
            'ventas_total'       => $contenido['ventas_total'],
            'totales_por_moneda' => $contenido['totales_por_moneda'],
            'puede_enviar'       => is_null($motivo),
            'motivo'             => is_null($motivo) ? null : $motivo['motivo'],
            'mensaje_motivo'     => is_null($motivo) ? null : $motivo['mensaje'],
        ];
    }

    /**
     * Por qué NO se le puede mandar el recordatorio a este cliente, o null si sí se le puede.
     *
     * 🔴 UNA SOLA IMPLEMENTACIÓN PARA LA PANTALLA Y PARA EL MASIVO. La previsualización y
     * `RecordatorioCobroController@encolar` la consultan igual, así que el motivo que lee el
     * operador en el modal es el mismo que va a salir en la lista de `salteados` del 202. Cuando
     * cada uno resolvía lo suyo, un chat existente sin `client_id` vinculado no aparecía como
     * salteado, se encolaba, y el job lo tiraba como `ya_recibio_hoy` sin que nadie lo viera.
     *
     * El orden es el mismo en que `enviar()` corta, para que nunca discrepen.
     *
     * ⚠️ Es INFORMATIVO: la autoridad la tiene `enviar()`, que revalida todo justo antes de
     * mandar. Entre el POST y el job puede pasar un rato y la respuesta puede cambiar.
     *
     * @param  int     $owner_id
     * @param  Client  $client
     * @param  WhatsappBotConfig|null  $config  Config del bot de la empresa; null si no hay.
     * @param  WhatsappChat|null       $chat    El chat ya resuelto por el caller (`buscar_chat()`).
     * @return array|null  ['motivo' => string, 'mensaje' => string] o null si se le puede mandar.
     */
    public function motivo_de_salteo($owner_id, Client $client, $config, $chat)
    {
        if (is_null($config)) {
            return [
                'motivo'  => self::CODE_SIN_CONFIGURACION,
                'mensaje' => 'No hay una configuración de WhatsApp activa para esta empresa.',
            ];
        }

        if (WhatsappPhoneHelper::normalize($client->phone) === '') {
            return [
                'motivo'  => self::CODE_SIN_TELEFONO,
                'mensaje' => 'No tiene teléfono cargado.',
            ];
        }

        if (! is_null($chat) && self::ya_recibio_recordatorio($chat)) {
            return [
                'motivo'  => self::CODE_YA_ENVIADO,
                'mensaje' => 'Ya recibió el recordatorio en las últimas 24 horas.',
            ];
        }

        // Con la ventana de 24 h abierta sale por texto libre: no necesita ni la plantilla ni el
        // adjunto, así que los dos motivos que siguen no le corresponden.
        if (! is_null($chat) && $chat->is_within_service_window()) {
            return null;
        }

        // 🔴 ESTE ES EL CASO DEL DÍA 1, no un borde raro: `cc_cli_recordatorio_cobro` nace
        // `pendiente_meta` y se queda así hasta que ComercioCity la apruebe a mano en Meta. Sin
        // esta rama, todos los clientes que no escribieron en las últimas 24 h se encolaban, el
        // toast decía "se encolaron 40 recordatorios" y los 40 morían adentro de un `Log::info`.
        if (is_null($this->plantilla_aprobada($owner_id))) {
            return [
                'motivo'  => self::CODE_PLANTILLA_NO_APROBADA,
                'mensaje' => 'La ventana de 24 horas está cerrada y la plantilla de recordatorio todavía no está aprobada en Meta.',
            ];
        }

        if (! $this->hay_resumen_de_cuenta($owner_id, $client, $config)) {
            return [
                'motivo'  => self::CODE_SIN_RESUMEN_DE_CUENTA,
                'mensaje' => 'La ventana de 24 horas está cerrada y el cliente no tiene movimientos en su cuenta corriente para adjuntar.',
            ];
        }

        return null;
    }

    /**
     * ¿Hay algún PDF de cuenta corriente que se le pueda adjuntar a la plantilla?
     *
     * Es el mismo dato que resuelve `documento_principal()` pero sin necesitar las ventas, para
     * que el masivo pueda evaluarlo cliente por cliente antes de encolar nada. Los dos se apoyan
     * en `build_current_acount_pdf_url()`, así que no pueden contestar distinto.
     *
     * @param  int     $owner_id
     * @param  Client  $client
     * @param  WhatsappBotConfig|null  $config
     * @return bool
     */
    public function hay_resumen_de_cuenta($owner_id, Client $client, $config)
    {
        $owner = $this->owner_de((int) $owner_id, $config);

        $api_url = rtrim((string) (is_null($owner) ? '' : $owner->api_url), '/');

        if ($api_url === '') {
            return false;
        }

        foreach ($this->credit_accounts_ordenadas($client) as $credit_account) {
            if (! is_null($this->build_current_acount_pdf_url($credit_account, $api_url))) {
                return true;
            }
        }

        return false;
    }

    /**
     * El teléfono al que efectivamente va a salir el mensaje.
     *
     * @param  Client  $client
     * @param  WhatsappChat|null  $chat
     * @return string|null
     */
    private function phone_de_destino(Client $client, $chat)
    {
        if (! is_null($chat)) {
            return $chat->phone;
        }

        $phone = WhatsappPhoneHelper::normalize($client->phone);

        return ($phone === '') ? null : $phone;
    }

    /**
     * Manda el recordatorio y devuelve el mensaje `out` ya persistido.
     *
     * @param  int      $owner_id         Dueño de la empresa.
     * @param  Client   $client           Cliente deudor.
     * @param  array|\Illuminate\Support\Collection  $ventas  Ventas sin cobrar YA recortadas por el caller.
     * @param  int|null $sent_by_user_id  Empleado que lo disparó; null si no hay uno puntual.
     * @return WhatsappChatMessage  El mensaje `out` persistido, con `source = 'recordatorio_cobro'`.
     *
     * @throws RecordatorioCobroException  Ante cualquier condición que impida el envío, y también
     *                                     si Kapso no confirmó el envío: el mensaje NO se persiste
     *                                     si no hubo envío.
     */
    public function enviar($owner_id, Client $client, $ventas, $sent_by_user_id = null)
    {
        $owner_id = (int) $owner_id;

        $ventas_array = $this->como_array($ventas);

        if (count($ventas_array) === 0) {
            throw new RecordatorioCobroException('El cliente no tiene ventas sin cobrar en el filtro actual.', self::CODE_SIN_VENTAS);
        }

        $config = WhatsappBotConfig::getForUser($owner_id);

        if (is_null($config)) {
            throw new RecordatorioCobroException('No hay una configuración de WhatsApp activa para esta empresa.', self::CODE_SIN_CONFIGURACION);
        }

        $config->loadMissing('user');

        $chat = $this->resolve_chat($owner_id, $client, $config);

        // 🔴 El freno de 24 h también acá, y no sólo en el controller: entre el POST y el job
        // puede pasar un rato largo y otro operador pudo haber mandado lo mismo. La regla se
        // escribe una sola vez (el estático de abajo) y se consulta en los dos lugares.
        if (self::ya_recibio_recordatorio($chat)) {
            throw new RecordatorioCobroException('El cliente ya recibió el recordatorio en las últimas 24 horas.', self::CODE_YA_ENVIADO);
        }

        $ventana_abierta = $chat->is_within_service_window();

        $contenido = $this->armar_contenido($owner_id, $client, $ventas_array, $ventana_abierta, $config);

        $send_service = new WhatsappBotSendService();

        if ($ventana_abierta) {

            $wa_message_id = $send_service->send_text($chat->phone, $contenido['body'], $config);

            // 🔴 Antes de persistir. Ver el docblock de la clase.
            $this->fallar_si_no_hubo_envio($wa_message_id, 'texto libre');

            return WhatsappChatHelper::store_outbound_recordatorio_message(
                $chat,
                $contenido['body'],
                $wa_message_id,
                $sent_by_user_id
            );
        }

        // Ventana cerrada: el único camino que deja Meta es la plantilla aprobada.
        $template = $this->plantilla_aprobada($owner_id);

        if (is_null($template)) {
            throw new RecordatorioCobroException('La plantilla de recordatorio de cobro todavía no está aprobada en Meta.', self::CODE_PLANTILLA_NO_APROBADA);
        }

        // 🔴 NO SE MANDA UNA PLANTILLA CONDENADA. La plantilla está registrada en Meta con header
        // DOCUMENT: sin `documento_url`, `send_template()` arma el payload sin el componente
        // `header` y Meta la rechaza -> null -> `envio_no_confirmado`, un "fallido" mudo que el
        // operador no puede accionar. Frenar acá con un motivo propio le dice qué pasó.
        if (is_null($contenido['documento_url'])) {
            throw new RecordatorioCobroException(
                'La ventana de 24 horas está cerrada y el cliente no tiene movimientos en su cuenta corriente para adjuntar a la plantilla.',
                self::CODE_SIN_RESUMEN_DE_CUENTA
            );
        }

        $wa_message_id = $send_service->send_template(
            $chat->phone,
            $template,
            $contenido['template_variables'],
            $config,
            $contenido['documento_url'],
            $contenido['documento_filename']
        );

        // 🔴 Mismo chequeo que en la rama de texto libre, y por el mismo motivo: `send_template()`
        // también devuelve null cuando Kapso falla, y el camino que se toma depende de la ventana
        // de 24 h. Tapar uno solo dejaría el agujero abierto la mitad de las veces.
        $this->fallar_si_no_hubo_envio($wa_message_id, 'plantilla');

        return WhatsappChatHelper::store_outbound_recordatorio_message(
            $chat,
            $contenido['body'],
            $wa_message_id,
            $sent_by_user_id,
            $template->meta_template_name,
            $contenido['documento_url']
        );
    }

    /**
     * ¿Este chat ya recibió un recordatorio de cobro en las últimas 24 horas?
     *
     * 🔴 UNA SOLA IMPLEMENTACIÓN, DOS CALLERS, Y NO ES DUPLICACIÓN: es un cálculo con una sola
     * autoridad. El controller lo consulta para armar la lista de `salteados` del 202 (uso
     * INFORMATIVO, para que el operador vea a quién no se le va a mandar) y
     * `SendRecordatorioCobroJob` lo vuelve a consultar justo antes de enviar (uso de
     * AUTORIDAD, porque entre el POST y el job la respuesta pudo cambiar).
     *
     * Se cuenta por `source` y no por `template_meta_name` a propósito: el
     * `template_meta_name` sólo existe en el camino de plantilla, así que un recordatorio
     * mandado por texto libre no frenaría al siguiente. Y marcar el texto libre como plantilla
     * para que sí frenara le mentiría al operador en la burbuja del chat, que muestra ese
     * campo en pantalla.
     *
     * @param  WhatsappChat  $chat
     * @return bool
     */
    public static function ya_recibio_recordatorio(WhatsappChat $chat)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->where('source', self::SOURCE)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    /**
     * El armado del mensaje, en un solo lugar. Lo comparten `previsualizar()` y `enviar()`.
     *
     * Devuelve SIEMPRE las dos formas resueltas hasta donde se puede (`body` según el camino,
     * `template_variables` sólo si el camino es plantilla), más los conteos y los totales que
     * la pantalla necesita para explicar qué se está por mandar.
     *
     * 🔴 La lista de ventas una-por-renglón existe SÓLO en el texto libre. No puede viajar como
     * variable de plantilla: Meta rechaza los parámetros con saltos de línea, tabulaciones o 4
     * espacios seguidos. Por eso los dos caminos dicen cosas distintas y por eso la
     * previsualización tiene que decir cuál de los dos va a salir.
     *
     * @param  int      $owner_id
     * @param  Client   $client
     * @param  array|\Illuminate\Support\Collection  $ventas
     * @param  bool     $ventana_abierta
     * @param  WhatsappBotConfig|null  $config  Null si la empresa no tiene el bot activo (preview).
     * @return array
     */
    private function armar_contenido($owner_id, Client $client, $ventas, $ventana_abierta, $config)
    {
        $ventas_array = $this->como_array($ventas);

        // Las MÁS VIEJAS primero: son las que se listan y las que más urge cobrar.
        usort($ventas_array, function ($a, $b) {
            return strcmp((string) $a->created_at, (string) $b->created_at);
        });

        $total_ventas = count($ventas_array);
        $listadas = array_slice($ventas_array, 0, self::MAX_VENTAS_LISTADAS);
        $restantes = $total_ventas - count($listadas);

        $totales = $this->montos_por_moneda($ventas_array);
        $etiquetas = [];
        foreach ($totales as $total) {
            $etiquetas[] = $total['etiqueta'];
        }
        $etiqueta_totales = ($etiquetas === []) ? '-' : implode(' y ', $etiquetas);

        $owner = $this->owner_de($owner_id, $config);
        $api_url = rtrim((string) (is_null($owner) ? '' : $owner->api_url), '/');
        $nombre_cliente = $this->nombre_cliente($client);
        $nombre_negocio = $this->nombre_negocio($owner);

        // Un link de cuenta corriente por cada moneda con movimientos. Los arma
        // `build_current_acount_pdf_url()`, que devuelve null si la cuenta está vacía.
        $links_cuenta = $this->links_de_cuenta_corriente($client, $api_url);

        // Para la plantilla, el header DOCUMENT admite UN solo archivo: el de la moneda con
        // mayor monto pendiente (desempate: moneda_id 1).
        $documento = $this->documento_principal($client, $totales, $api_url);

        if ($ventana_abierta) {

            $body = $this->body_texto_libre(
                $nombre_cliente,
                $nombre_negocio,
                $total_ventas,
                $etiqueta_totales,
                $listadas,
                $restantes,
                $links_cuenta,
                $api_url
            );

            return [
                'body'               => $body,
                'template_meta_name' => null,
                'template_variables' => null,
                // En texto libre los links van escritos en el cuerpo, no como adjunto: mandar
                // el documento aparte sería un segundo mensaje y un segundo cargo de Meta.
                'documento_url'      => null,
                'documento_filename' => null,
                'ventas_listadas'    => count($listadas),
                'ventas_restantes'   => $restantes,
                'ventas_total'       => $total_ventas,
                'totales_por_moneda' => $this->totales_para_la_pantalla($totales),
            ];
        }

        // Mismo orden que declara `WhatsappTemplateStandardHelper` para `cc_cli_recordatorio_cobro`:
        // [Nombre, Negocio, Cantidad de ventas, Montos pendientes].
        //
        // 🔴 LA VARIABLE {{3}} LLEVA EL SUSTANTIVO ADENTRO ("1 venta" / "7 ventas") y el body dice
        // "Tenés {{3}} pendientes de pago". El singular no se puede resolver desde el body de una
        // plantilla de Meta —es texto fijo—, así que si {{3}} fuera sólo el número, un cliente con
        // una sola venta leería "Tenés 1 ventas pendientes de pago". El camino de texto libre ya
        // conjuga bien (`body_texto_libre()`); esto lo empareja.
        $variables = [$nombre_cliente, $nombre_negocio, $this->cantidad_de_ventas_en_palabras($total_ventas), $etiqueta_totales];

        $template = $this->plantilla_aprobada($owner_id);

        // Sin plantilla aprobada todavía se puede PREVISUALIZAR: se muestra el texto estándar
        // con las variables reemplazadas y `puede_enviar` en false con el motivo. Mostrar el
        // cuerpo con los `{{1}}` crudos no le sirve a nadie.
        $body = is_null($template)
            ? $this->render_plantilla_estandar($variables)
            : $template->render_body($variables);

        return [
            'body'               => $body,
            'template_meta_name' => self::TEMPLATE_META_NAME,
            'template_variables' => $variables,
            'documento_url'      => $documento['url'],
            'documento_filename' => $documento['filename'],
            'ventas_listadas'    => 0,
            'ventas_restantes'   => $total_ventas,
            'ventas_total'       => $total_ventas,
            'totales_por_moneda' => $this->totales_para_la_pantalla($totales),
        ];
    }

    /**
     * El cuerpo del mensaje de texto libre, con las ventas una por renglón.
     *
     * @param  string  $nombre_cliente
     * @param  string  $nombre_negocio
     * @param  int     $total_ventas
     * @param  string  $etiqueta_totales
     * @param  array   $listadas          Las hasta 5 ventas más viejas.
     * @param  int     $restantes
     * @param  array   $links_cuenta      [['etiqueta' => 'Pesos', 'url' => '...'], ...]
     * @param  string  $api_url
     * @return string
     */
    private function body_texto_libre($nombre_cliente, $nombre_negocio, $total_ventas, $etiqueta_totales, array $listadas, $restantes, array $links_cuenta, $api_url)
    {
        $renglones = [];

        $renglones[] = 'Hola '.$nombre_cliente.', te escribimos de '.$nombre_negocio.'.';
        $renglones[] = '';

        $sustantivo = ($total_ventas === 1) ? '1 venta pendiente' : $total_ventas.' ventas pendientes';
        $renglones[] = 'Tenés '.$sustantivo.' de pago por un total de '.$etiqueta_totales.':';
        $renglones[] = '';

        foreach ($listadas as $venta) {
            $renglones[] = '• '.$this->renglon_de_venta($venta, $api_url);
        }

        if ($restantes > 0) {
            $renglones[] = '';
            $renglones[] = ($restantes === 1) ? 'y 1 venta más.' : 'y '.$restantes.' ventas más.';
        }

        if ($links_cuenta !== []) {
            $renglones[] = '';
            $renglones[] = 'El detalle completo de tu cuenta corriente:';
            foreach ($links_cuenta as $link) {
                $renglones[] = $link['etiqueta'].': '.$link['url'];
            }
        }

        $renglones[] = '';
        $renglones[] = 'Cualquier consulta, respondé este mensaje. ¡Gracias!';

        return implode("\n", $renglones);
    }

    /**
     * Un renglón de venta: `Venta N° 1043 — $4.000 — hace 45 días — https://.../sale/pdf/1043`.
     *
     * @param  \App\Models\Sale  $venta
     * @param  string  $api_url
     * @return string
     */
    private function renglon_de_venta($venta, $api_url)
    {
        $numero = ! empty($venta->num) ? $venta->num : $venta->id;

        $monto = $this->formatear_monto($this->falta_pagarse($venta), $this->moneda_de_la_venta($venta));

        $dias = $this->dias_de_antiguedad($venta);
        $antiguedad = ($dias === 1) ? 'hace 1 día' : 'hace '.$dias.' días';

        $renglon = 'Venta N° '.$numero.' — '.$monto.' — '.$antiguedad;

        $url = $this->build_sale_pdf_url($venta, $api_url);

        if (! is_null($url)) {
            $renglon .= ' — '.$url;
        }

        return $renglon;
    }

    /**
     * Los totales pendientes agrupados por moneda. 🔴 NO se suman entre monedas (decisión de
     * Lucas): "$12.500 y USD 300" son dos deudas distintas y sumarlas da un número que no
     * existe. El orden es por `moneda_id` para que el mensaje salga siempre igual.
     *
     * @param  array  $ventas
     * @return array<int, array>  [['moneda_id' => 1, 'monto' => 12500.0, 'cantidad' => 3, 'etiqueta' => '$12.500'], ...]
     */
    private function montos_por_moneda(array $ventas)
    {
        $acumulado = [];
        $cantidades = [];

        foreach ($ventas as $venta) {

            $moneda_id = $this->moneda_de_la_venta($venta);

            if (! isset($acumulado[$moneda_id])) {
                $acumulado[$moneda_id] = 0;
                $cantidades[$moneda_id] = 0;
            }

            $acumulado[$moneda_id] += $this->falta_pagarse($venta);
            $cantidades[$moneda_id]++;
        }

        ksort($acumulado);

        $totales = [];

        foreach ($acumulado as $moneda_id => $monto) {
            $totales[] = [
                'moneda_id' => (int) $moneda_id,
                'monto'     => (float) $monto,
                // La cantidad de ventas de esa moneda. La usa `documento_principal()` para elegir
                // qué resumen adjuntar sin comparar montos de monedas distintas.
                'cantidad'  => (int) $cantidades[$moneda_id],
                'etiqueta'  => $this->formatear_monto($monto, (int) $moneda_id),
            ];
        }

        return $totales;
    }

    /**
     * Los totales sin el `monto` crudo, que es lo único que la pantalla necesita (muestra la
     * `etiqueta` ya formateada). Sacar el float evita que alguien lo re-formatee en el front y
     * termine mostrando un número distinto al que dice el mensaje.
     *
     * @param  array  $totales
     * @return array<int, array>
     */
    private function totales_para_la_pantalla(array $totales)
    {
        $salida = [];

        foreach ($totales as $total) {
            $salida[] = [
                'moneda_id' => $total['moneda_id'],
                'etiqueta'  => $total['etiqueta'],
            ];
        }

        return $salida;
    }

    /**
     * Formatea un monto con el mismo criterio que el `price()` de la SPA: separador de miles
     * `.`, decimales `,`, y los decimales se van si son `00`.
     *
     * ⚠️ La tabla `monedas` no tiene columna de símbolo: la única columna útil es `name`
     * ('Peso'/'Dolar'). Por eso el símbolo se resuelve por `moneda_id`, exactamente como hace
     * hoy `Cobros.vue`.
     *
     * @param  float|int|string  $monto
     * @param  int  $moneda_id
     * @return string
     */
    private function formatear_monto($monto, $moneda_id)
    {
        $numero = number_format((float) $monto, 2, ',', '.');

        if (substr($numero, -3) === ',00') {
            $numero = substr($numero, 0, strlen($numero) - 3);
        }

        if ((int) $moneda_id === 2) {
            return 'USD '.$numero;
        }

        return '$'.$numero;
    }

    /**
     * Lo que falta pagarse de una venta: `debe - pagandose` de su cuenta corriente, igual que
     * el `lo_que_falta_pagarse()` de `Cobros.vue`. Sin `current_acount` devuelve el total de la
     * venta, que es el mejor dato disponible.
     *
     * @param  \App\Models\Sale  $venta
     * @return float
     */
    private function falta_pagarse($venta)
    {
        $current_acount = $venta->current_acount;

        if (is_null($current_acount)) {
            return (float) $venta->total;
        }

        return (float) $current_acount->debe - (float) $current_acount->pagandose;
    }

    /**
     * Moneda de una venta. Cae a la de su cuenta corriente y, en última instancia, a pesos.
     *
     * @param  \App\Models\Sale  $venta
     * @return int
     */
    private function moneda_de_la_venta($venta)
    {
        if (! is_null($venta->moneda_id)) {
            return (int) $venta->moneda_id;
        }

        $current_acount = $venta->current_acount;

        if (! is_null($current_acount) && ! is_null($current_acount->moneda_id)) {
            return (int) $current_acount->moneda_id;
        }

        return 1;
    }

    /**
     * Días enteros desde que se creó la venta.
     *
     * @param  \App\Models\Sale  $venta
     * @return int
     */
    private function dias_de_antiguedad($venta)
    {
        if (is_null($venta->created_at)) {
            return 0;
        }

        return (int) Carbon::parse($venta->created_at)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * Un link de cuenta corriente por cada moneda del cliente que tenga movimientos.
     *
     * @param  Client  $client
     * @param  string  $api_url
     * @return array<int, array>  [['moneda_id' => 1, 'etiqueta' => 'Pesos', 'url' => '...'], ...]
     */
    private function links_de_cuenta_corriente(Client $client, $api_url)
    {
        $links = [];

        foreach ($this->credit_accounts_ordenadas($client) as $credit_account) {

            $url = $this->build_current_acount_pdf_url($credit_account, $api_url);

            if (is_null($url)) {
                continue;
            }

            $links[] = [
                'moneda_id' => (int) $credit_account->moneda_id,
                'etiqueta'  => $this->etiqueta_moneda((int) $credit_account->moneda_id),
                'url'       => $url,
            ];
        }

        return $links;
    }

    /**
     * El único documento que puede viajar en el header de la plantilla: el PDF de cuenta
     * corriente de la moneda con MÁS VENTAS VENCIDAS en el filtro. Desempate: el `moneda_id` más
     * chico (pesos), para que dos corridas iguales manden el mismo archivo.
     *
     * 🔴 EL CRITERIO ES LA CANTIDAD DE VENTAS Y NO EL MONTO, y no es un detalle: comparar
     * `12500 > 300` para elegir entre pesos y dólares es comparar dos unidades distintas como si
     * fueran la misma. Un cliente que debe USD 300 y $500 terminaba recibiendo el resumen en
     * pesos porque 500 > 300. La cantidad de ventas es un número sin unidad, así que se puede
     * comparar entre monedas sin inventar una cotización que este módulo no tiene por qué
     * conocer.
     *
     * ⚠️ Sólo se consideran las cuentas que tienen un PDF armable (con movimientos). Si la moneda
     * "ganadora" no tiene ninguno, se cae a la que sí, en vez de mandar la plantilla sin adjunto:
     * un resumen de la otra moneda es un dato real; una plantilla sin header la rechaza Meta.
     *
     * @param  Client  $client
     * @param  array   $totales
     * @param  string  $api_url
     * @return array  ['url' => string|null, 'filename' => string|null]
     */
    private function documento_principal(Client $client, array $totales, $api_url)
    {
        $cantidad_por_moneda = [];

        foreach ($totales as $total) {
            $cantidad_por_moneda[(int) $total['moneda_id']] = (int) $total['cantidad'];
        }

        $elegida = null;
        $elegida_url = null;
        $elegida_cantidad = -1;

        // `credit_accounts_ordenadas()` viene ordenada por `moneda_id` ascendente, así que con el
        // `>` estricto el desempate se lo lleva sola la moneda de id más chico.
        foreach ($this->credit_accounts_ordenadas($client) as $credit_account) {

            $url = $this->build_current_acount_pdf_url($credit_account, $api_url);

            if (is_null($url)) {
                continue;
            }

            $moneda_id = (int) $credit_account->moneda_id;

            $cantidad = isset($cantidad_por_moneda[$moneda_id]) ? $cantidad_por_moneda[$moneda_id] : 0;

            if ($cantidad > $elegida_cantidad) {
                $elegida = $credit_account;
                $elegida_url = $url;
                $elegida_cantidad = $cantidad;
            }
        }

        if (is_null($elegida)) {
            return ['url' => null, 'filename' => null];
        }

        return [
            'url'      => $elegida_url,
            'filename' => 'cuenta-corriente-'.$this->etiqueta_moneda((int) $elegida->moneda_id).'.pdf',
        ];
    }

    /**
     * Las cuentas corriente del cliente, ordenadas por `moneda_id` para que el mensaje salga
     * siempre en el mismo orden.
     *
     * @param  Client  $client
     * @return array
     */
    private function credit_accounts_ordenadas(Client $client)
    {
        $client->loadMissing('credit_accounts');

        $cuentas = $client->credit_accounts->all();

        usort($cuentas, function ($a, $b) {
            return (int) $a->moneda_id - (int) $b->moneda_id;
        });

        return $cuentas;
    }

    /**
     * Rótulo de una moneda. ⚠️ `monedas` sólo tiene `name` ('Peso'/'Dolar') y no un símbolo, así
     * que se resuelve por id, igual que en la SPA.
     *
     * @param  int  $moneda_id
     * @return string
     */
    private function etiqueta_moneda($moneda_id)
    {
        return ($moneda_id === 2) ? 'USD' : 'Pesos';
    }

    /**
     * URL pública del PDF de la venta (`sale/pdf/{id}` de `routes/web.php`, sin auth). Es la
     * misma que ya usa el botón `wa.me` histórico y `ComercioCityMailHelper`.
     *
     * @param  \App\Models\Sale  $venta
     * @param  string  $api_url
     * @return string|null  Null si la empresa no tiene `api_url` cargada.
     */
    private function build_sale_pdf_url($venta, $api_url)
    {
        if ($api_url === '') {
            return null;
        }

        return $api_url.'/sale/pdf/'.$venta->id;
    }

    /**
     * URL pública del PDF de cuenta corriente
     * (`/current-acount/pdf/{credit_account_id}/60/simple` de `routes/web.php`).
     *
     * 🔴 El primer parámetro se llama `$current_acount_id` en la firma de
     * `CurrentAcountController@pdfFromModel`, pero con `$cantidad_movimientos > 0` se usa como
     * `credit_account_id`. Es el mismo uso que ya hace `Nav.vue`, no una interpretación nueva.
     *
     * ⚠️ EL GUARD DE ABAJO NO ES DECORATIVO. Si el `credit_account` no tiene ningún
     * `CurrentAcount`, `pdfFromModel()` hace `$models[0]->credit_account_id` sobre una
     * colección vacía y la ruta pública tira un 500. Esa ruta es de otro módulo y tiene otros
     * callers, así que NO se arregla desde acá: lo que se hace es no mandar el link. Un
     * recordatorio con un link roto es peor que uno sin link.
     *
     * @param  \App\Models\CreditAccount  $credit_account
     * @param  string  $api_url
     * @return string|null
     */
    private function build_current_acount_pdf_url($credit_account, $api_url)
    {
        if ($api_url === '') {
            return null;
        }

        $tiene_movimientos = CurrentAcount::where('credit_account_id', $credit_account->id)->exists();

        if (! $tiene_movimientos) {
            return null;
        }

        return $api_url.'/current-acount/pdf/'.$credit_account->id.'/'.self::CANTIDAD_MOVIMIENTOS_PDF.'/simple';
    }

    /**
     * Corta el envío si `WhatsappBotSendService` no devolvió un `wa_message_id`.
     *
     * Ese null puede significar "no salió" o "salió pero Kapso no informó el id", y se trata
     * como fallo en los dos casos, igual que en `SaleWhatsappSenderService`. El borde está
     * elegido a conciencia: un falso "no se envió" hace que alguien reintente y a lo sumo el
     * cliente reciba el recordatorio dos veces; un falso "se envió" lo deja sin aviso, activa
     * el freno de 24 h y nadie se entera.
     *
     * @param  string|null  $wa_message_id
     * @param  string       $camino  'texto libre' | 'plantilla', sólo para el mensaje.
     * @return void
     *
     * @throws RecordatorioCobroException
     */
    private function fallar_si_no_hubo_envio($wa_message_id, $camino)
    {
        if (! is_null($wa_message_id)) {
            return;
        }

        throw new RecordatorioCobroException(
            'WhatsApp no confirmó el envío del recordatorio ('.$camino.'). Revisá la clave de Kapso y el número de la empresa, y volvé a intentarlo.',
            self::CODE_ENVIO_NO_CONFIRMADO
        );
    }

    /**
     * Busca el `WhatsappChat` del cliente SIN crearlo. Primero por `client_id` y, si no hay, por
     * teléfono (el cliente pudo haber escrito antes de que alguien vinculara la conversación).
     *
     * 🔴 ES ESTÁTICO Y PÚBLICO PORQUE `RecordatorioCobroController` USA EXACTAMENTE ESTA MISMA
     * BÚSQUEDA. El controller antes buscaba sólo por `client_id`: un chat existente sin vincular
     * no aparecía como salteado, se encolaba igual, y el job lo tiraba como `ya_recibio_hoy` sin
     * que la pantalla dijera nada. Una regla, un lugar.
     *
     * ⚠️ EL MATCH POR TELÉFONO NO ES POR IGUALDAD EXACTA, a diferencia de
     * `SaleWhatsappSenderService::resolve_chat()`. Ese patrón es preexistente, tiene otros
     * callers y no se toca desde acá, pero acá NO se copia: `clients.phone` lo carga una persona
     * ("0341 600-9988") y el chat lo crea el webhook de Kapso con lo que manda Meta
     * ("5493416009988"). Comparados con `=` no matchean, así que se crearía un chat duplicado, la
     * ventana daría cerrada, saldría por plantilla en vez de texto libre, y el número que se le
     * pasa a Kapso lo terminaría rechazando Meta. Por eso se compara con
     * `WhatsappPhoneHelper::matches()`, que es la función que existe justamente para esto: los
     * últimos 10 dígitos, absorbiendo `54`, `549`, el `0` y el `15`.
     *
     * ⚠️ El match por teléfono sólo mira chats SIN cliente vinculado. Un chat ya vinculado a otro
     * cliente que casualmente comparte el teléfono no se toma: escribirle el recordatorio de un
     * cliente adentro de la conversación de otro es peor que abrirle una conversación nueva.
     *
     * @param  int     $owner_id
     * @param  Client  $client
     * @return WhatsappChat|null
     */
    public static function buscar_chat($owner_id, Client $client)
    {
        $chat = WhatsappChat::where('user_id', $owner_id)
            ->where('client_id', $client->id)
            ->first();

        if (! is_null($chat)) {
            return $chat;
        }

        $phone = WhatsappPhoneHelper::normalize($client->phone);

        if ($phone === '') {
            return null;
        }

        // El `like` por los últimos 10 dígitos acota la búsqueda en la base (no se traen todos
        // los chats de la empresa a memoria) y `matches()` decide de verdad sobre esos pocos.
        // Con menos de 10 dígitos no hay "últimos 10" confiables y se busca exacto, igual que
        // hace el helper.
        $candidatos = WhatsappChat::where('user_id', $owner_id)
            ->whereNull('client_id')
            ->where('phone', (strlen($phone) < 10) ? '=' : 'like', (strlen($phone) < 10) ? $phone : '%'.substr($phone, -10))
            ->get();

        foreach ($candidatos as $candidato) {
            if (WhatsappPhoneHelper::matches($candidato->phone, $phone)) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Resuelve (o crea) el `WhatsappChat` del cliente, igual que `SaleWhatsappSenderService`.
     * Si el chat existía sin cliente vinculado, lo vincula de paso.
     *
     * @param  int  $owner_id
     * @param  Client  $client
     * @param  WhatsappBotConfig  $config
     * @return WhatsappChat
     *
     * @throws RecordatorioCobroException  Código 'sin_telefono' si el cliente no tiene teléfono.
     */
    private function resolve_chat($owner_id, Client $client, WhatsappBotConfig $config)
    {
        $phone = WhatsappPhoneHelper::normalize($client->phone);

        if ($phone === '') {
            throw new RecordatorioCobroException('El cliente no tiene teléfono cargado.', self::CODE_SIN_TELEFONO);
        }

        $chat = self::buscar_chat($owner_id, $client);

        if (is_null($chat)) {
            return WhatsappChat::create([
                'user_id'      => $owner_id,
                'phone'        => $phone,
                'client_id'    => $client->id,
                'ai_enabled'   => (bool) $config->ai_enabled_default,
                'unread_count' => 0,
            ]);
        }

        if (is_null($chat->client_id)) {
            // El chat existía (el cliente ya había escrito antes) pero sin vincular.
            $chat->client_id = $client->id;
            $chat->save();
        }

        return $chat;
    }

    /**
     * La plantilla `cc_cli_recordatorio_cobro` del owner, sólo si ya está aprobada en Meta.
     *
     * @param  int  $owner_id
     * @return WhatsappTemplate|null
     */
    private function plantilla_aprobada($owner_id)
    {
        $template = WhatsappTemplate::where('user_id', $owner_id)
            ->where('meta_template_name', self::TEMPLATE_META_NAME)
            ->first();

        if (is_null($template) || $template->status !== 'aprobada') {
            return null;
        }

        return $template;
    }

    /**
     * "1 venta" / "7 ventas", para que la variable `{{3}}` de la plantilla lleve el sustantivo
     * conjugado y el body de Meta pueda ser texto fijo.
     *
     * @param  int  $total_ventas
     * @return string
     */
    private function cantidad_de_ventas_en_palabras($total_ventas)
    {
        return ((int) $total_ventas === 1) ? '1 venta' : (int) $total_ventas.' ventas';
    }

    /**
     * Renderiza el cuerpo estándar de la plantilla cuando el owner todavía no la tiene dada de
     * alta. Sólo se usa para PREVISUALIZAR: el envío corta antes con
     * `CODE_PLANTILLA_NO_APROBADA`.
     *
     * 🔴 El texto se lee del catálogo (`WhatsappTemplateStandardHelper`) y NO se repite acá. Es
     * el mismo cuerpo que se le da de alta a Meta: tenerlo escrito en dos archivos garantizaba
     * que tarde o temprano la previsualización mostrara un texto y saliera otro.
     *
     * @param  array  $variables
     * @return string
     */
    private function render_plantilla_estandar(array $variables)
    {
        $body = WhatsappTemplateStandardHelper::body_preview_de(self::TEMPLATE_META_NAME);

        foreach ($variables as $index => $valor) {
            $body = str_replace('{{'.($index + 1).'}}', (string) $valor, $body);
        }

        return $body;
    }

    /**
     * El owner de la empresa. Se saca del `config` si ya está cargado (evita una query) y si no
     * se busca.
     *
     * @param  int  $owner_id
     * @param  WhatsappBotConfig|null  $config
     * @return User|null
     */
    private function owner_de($owner_id, $config)
    {
        if (! is_null($config)) {
            $config->loadMissing('user');

            if (! is_null($config->user)) {
                return $config->user;
            }
        }

        return User::find($owner_id);
    }

    /**
     * Nombre del cliente para el saludo, con fallback.
     *
     * @param  Client  $client
     * @return string
     */
    private function nombre_cliente(Client $client)
    {
        $nombre = trim((string) $client->name);

        return ($nombre === '') ? 'Cliente' : $nombre;
    }

    /**
     * Nombre del negocio que firma el mensaje, con fallback.
     *
     * @param  User|null  $owner
     * @return string
     */
    private function nombre_negocio($owner)
    {
        if (is_null($owner)) {
            return 'nuestro negocio';
        }

        $nombre = trim((string) ($owner->company_name ? $owner->company_name : $owner->name));

        return ($nombre === '') ? 'nuestro negocio' : $nombre;
    }

    /**
     * Normaliza la lista de ventas a un array PHP, venga como array o como Collection.
     *
     * @param  array|\Illuminate\Support\Collection  $ventas
     * @return array
     */
    private function como_array($ventas)
    {
        if (is_array($ventas)) {
            return array_values($ventas);
        }

        return array_values($ventas->all());
    }
}
