<?php

namespace App\Http\Controllers\Helpers;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Models\Budget;
use App\Models\ClientOffer;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Moneda;
use App\Models\PdfColumnOption;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ComercioCityMailHelper
{
    /**
     * Envía al cliente un correo por nueva venta registrada.
     *
     * No hace nada si la venta no tiene cliente o el cliente no tiene email.
     *
     * @param Sale $sale Venta con relaciones cargables (client, moneda, user para links).
     * @param bool $updated Si true, asunto y textos de "venta actualizada".
     * @param bool $force_send Si true, envía aunque send_mail sea false (reenvío / envío manual desde listado).
     * @return void
     */
    public static function new_sale(Sale $sale, $updated = false, $force_send = false)
    {
        if (!$force_send && !$sale->send_mail) {
            return;
        }

        $sale->loadMissing('client', 'moneda');

        $client = $sale->client;
        if (!$client || empty($client->email)) {
            return;
        }

        $email = trim($client->email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $payload = self::payload_de_venta($sale, $updated);

        /*
         * 🔴 DECIDIDO: este ->queue() NO mira los rechazos (RechazosDeCorreoHelper) y NO se vuelve sincrónico. Un mail encolado se manda en un worker: si el
         * servidor SMTP rechaza la casilla (un 550 en el RCPT TO, que SwiftMailer no convierte en excepción) pasa ahí, cuando quien lo pidió ya no está, y
         * acá no hay nada que leer. Lo que se hace: el listener AnotarMailRechazadoPorElServidor deja el rechazo en el log. Lo que NO se hace, y por qué:
         *   - volverlo sincrónico: el request de guardar la venta se quedaría esperando al SMTP;
         *   - tirar en el worker: con QUEUE_CONNECTION=sync la excepción VUELVE a este request (un 500 sobre una venta que ya hizo commit en SaleController).
         *     (Además, un job que tira pasa por Handler::report() y de ahí a GitHub; eso solo se evitaría sumando la excepción a Handler::$dontReport, pero no
         *     alcanza por el motivo de arriba.)
         * El log de abajo se escribe al ENCOLAR y no dice si el mail salió. La decisión está en TodoEnvioDeMailMiraLosRechazosTest::ENCOLADOS.
         *
         * Límite dicho, el mismo que el de `notificada_email_at` de las ofertas (ver abajo): `SaleController::send_client_mail()` y `send_client_mail_bulk()` (el botón
         * "Enviar correo al cliente" del listado de ventas) llegan acá con `$force_send`, contestan 200 y escriben `sales.send_mail = true` apenas ENCOLAN: un rechazo en
         * el worker no lo revierte. (`send_mail` es a la vez la casilla "enviar mail" del formulario de venta, o sea una preferencia: no es un defecto de este helper.)
         */
        Mail::to($email)->queue(new ComercioCityMail($payload));

        Log::info('Se mando mail a '.$email);
    }

    /**
     * El contenido del mail de una venta, tal cual lo encola new_sale(): asunto, textos, renglones y
     * links (el del comprobante con `pdf_column_profile_id` y su token).
     *
     * Sacado de new_sale() SIN cambiar una coma (misión asistente-envios-a-clientes, 16/10/2026) para
     * que el asistente le muestre al dueño, antes del sí, el asunto y el texto REALES del mail y el
     * mismo link que lleva. Lo que el dueño lee es lo que sale.
     *
     * @param Sale $sale Venta con cliente.
     * @param bool $updated Si true, asunto y textos de "venta actualizada".
     * @return ComercioCityMailPayload
     */
    public static function payload_de_venta(Sale $sale, $updated = false)
    {
        $sale->loadMissing('client', 'moneda');

        $client = $sale->client;

        $numVenta = $sale->num !== null && $sale->num !== '' ? (string) $sale->num : (string) $sale->id;
        $totalStr = self::format_total($sale);
        $fechaStr = self::format_created_at($sale);

        $detail_lines = [
            [
                'label' => 'Cliente', 
                'value' => $client->name ?: '—', 
                'bold_label' => true
            ],
            [
                'label' => 'Nº venta',
                'value' => $numVenta, 
                'bold_label' => true
            ],
            [
                'label' => 'Total', 
                'value' => $totalStr, 
                'bold_label' => true
            ],
            [
                'label' => 'Fecha', 
                'value' => $fechaStr, 
                'bold_label' => true
            ],
        ];

        $pdf_profile = PdfColumnOption::orderBy('id', 'ASC')
                                        ->first();

        $credit_account = CreditAccount::where('model_name', 'client')
                                    ->where('model_id', $client->id)
                                    ->where('moneda_id', $sale->moneda_id)
                                    ->first();


        $links = [];

        /*
         * 🔴 Los dos links llevan el token (`?t=`, PdfLinkHelper): desde la misión
         * pdf-de-venta-publico (10/10/2026) las rutas de PDF ya no son públicas y el cliente que
         * abre el mail no tiene sesión en el sistema. Sin token, el link deja de abrir cuando se
         * cierra la ventana de transición del comercio. El de la cuenta corriente es de tipo
         * `credit_account` porque la cantidad (30) es mayor a cero.
         */
        if ($pdf_profile) {
            $links[] = [
                'text' => 'Ver comprobante en ComercioCity',
                'url'  => PdfLinkHelper::con_token(
                    $sale->user->api_url.'/sale/pdf/'.$sale->id.'?pdf_column_profile_id='.$pdf_profile->id,
                    'sale',
                    $sale->id,
                    $sale->user_id
                ),
            ];
        }

        if ($credit_account) {
            $links[] = [
                'text' => 'Ver mi cuenta corriente en ComercioCity',
                'url'  => PdfLinkHelper::con_token(
                    $sale->user->api_url.'/current-acount/pdf/'.$credit_account->id.'/30/simple',
                    'credit_account',
                    $credit_account->id,
                    $credit_account->user_id
                ),
            ];
        }


        return new ComercioCityMailPayload([
            'subject' => $updated ? 'Venta actualizada' : 'Nueva venta registrada',
            'title' => $updated ? 'Se actualizo tu venta' : 'Se registró una venta',
            'paragraphs' => [
                'Te informamos que se registró una nueva venta asociada a tu cuenta en ComercioCity.',
                'Ingresando a la aplicación podés ver el detalle de la operación.',
            ],
            'detail_lines' => $detail_lines,
            'links' => $links,
            'closing' => 'Muchas gracias por elegirnos.',
            'preheader' => 'Venta #' . $numVenta . ' · ' . $totalStr,
        ]);
    }

    /**
     * Avisa por mail al cliente que tiene una oferta personalizada vigente
     * (misión motor-de-ofertas-por-cliente, §A.8 punto b).
     *
     * 🔴 NO ES UNA PRECONDICIÓN DE LA ACTIVACIÓN. Devuelve null y no lanza
     * cuando no hay a quién escribirle: la promoción ya quedó registrada en
     * client_offers antes de llegar acá y vale igual, porque el canal principal
     * es la tienda mostrándosela al comprador. Medido: el 84% de los clientes
     * no tiene ni mail ni teléfono cargado. El que llama deja
     * `notificada_email_at` en null y la vista lo muestra como "sin notificar".
     * **No convertir el null en una excepción ni en un 422.**
     *
     * @param ClientOffer $offer Oferta ya creada, con client/article cargables.
     * @return string|null El mail al que se encoló, o null si no se pudo avisar.
     */
    public static function nueva_oferta(ClientOffer $offer)
    {
        $offer->loadMissing('client.buyer', 'article');

        $email = OfertaComunicacionHelper::email_del_cliente($offer->client_id, $offer->user_id, $offer->client);

        if (is_null($email)) {
            return null;
        }

        $user = User::find($offer->user_id);

        /*
         * 🔴 ACÁ SÍ SE LLAMA A ClientMailConfigHelper::apply(), a diferencia de
         * new_sale() (arriba), que deja salir los mails de venta desde
         * contacto@comerciocity.com. Una oferta comercial tiene que llegar con
         * el remitente DEL COMERCIO: es el comercio el que le está ofreciendo un
         * descuento a su cliente, no ComercioCity. Un mail de "ComercioCity" con
         * un descuento de una ferretería parece spam y termina en la basura.
         * apply() ya se encarga del forgetMailers() (:80-86) sin el cual Laravel
         * seguiría usando el mailer cacheado del .env, y falla en silencio
         * cayendo al .env si el comercio no configuró su SMTP.
         */
        ClientMailConfigHelper::apply($offer->user_id);

        $nombre_articulo = $offer->article && !empty($offer->article->name) ? $offer->article->name : '—';
        // El nombre a mostrar es el del comprador de la tienda, cayendo al del cliente del
        // ERP (OfertaComunicacionHelper::nombre_para_mostrar). El mail sale preferentemente
        // al buyers.email, así que llamarlo por la razón social del ERP es justo lo que este
        // cambio viene a arreglar. '—' se conserva como el mismo relleno de siempre.
        $nombre_cliente_para_mostrar = OfertaComunicacionHelper::nombre_para_mostrar($offer->client, $offer->user_id);
        $nombre_cliente  = $nombre_cliente_para_mostrar !== '' ? $nombre_cliente_para_mostrar : '—';
        $descuento       = OfertaComunicacionHelper::descripcion_del_descuento($offer);
        $vigencia        = self::format_fecha($offer->desde) . ' al ' . self::format_fecha($offer->hasta);

        $detail_lines = [
            ['label' => 'Cliente',   'value' => $nombre_cliente,  'bold_label' => true],
            ['label' => 'Artículo',  'value' => $nombre_articulo, 'bold_label' => true],
            ['label' => 'Descuento', 'value' => $descuento,       'bold_label' => true],
            ['label' => 'Vigencia',  'value' => $vigencia,        'bold_label' => true],
        ];

        $links = [];

        $tienda = OfertaComunicacionHelper::url_de_la_tienda($user);

        if (!is_null($tienda)) {
            $links[] = [
                'text' => 'Ver la oferta en la tienda',
                'url'  => $tienda,
            ];
        }

        $payload = new ComercioCityMailPayload([
            'subject' => 'Tenés una oferta esperándote',
            'title' => 'Una oferta pensada para vos',
            'paragraphs' => [
                'Preparamos un descuento exclusivo para vos en ' . $nombre_articulo . '.',
                'Está disponible hasta el ' . self::format_fecha($offer->hasta) . ': entrá a la tienda y aprovechalo.',
            ],
            'detail_lines' => $detail_lines,
            'links' => $links,
            'closing' => 'Muchas gracias por elegirnos.',
            'preheader' => $descuento . ' en ' . $nombre_articulo,
        ]);

        /*
         * 🔴 ->queue() EXPLÍCITO, y no ->send() confiando en que el Mailable se
         * encole solo. Medido el 15/8/2026: los 6 archivos de app/Mail/ IMPORTAN
         * Illuminate\Contracts\Queue\ShouldQueue pero NINGUNA clase la
         * implementa — es un `use` muerto. Lo único que encola es este ->queue()
         * (QUEUE_CONNECTION=database). Si alguien lo "simplifica" a ->send(), el
         * request de activación se queda esperando al SMTP del comercio.
         *
         * 🔴 Y TAMPOCO mira los rechazos del servidor SMTP (RechazosDeCorreoHelper): el rechazo ocurre en el worker y acá no se puede leer. Lo deja en el log el
         * listener AnotarMailRechazadoPorElServidor (ver el comentario del ->queue() de new_sale() y TodoEnvioDeMailMiraLosRechazosTest::ENCOLADOS). Límite
         * dicho: `notificada_email_at` se escribe al encolar (ClientOfertaAltaHelper::avisarle_al_cliente) y seguirá diciendo "notificada" aunque el worker reciba un
         * rechazo; compensarlo necesita un job propio que escriba en client_offers: es una decisión de producto, no un arreglo de este helper.
         */
        Mail::to($email)->queue(new ComercioCityMail($payload));

        Log::info('Se mando mail de oferta a '.$email);

        return $email;
    }

    /**
     * Le manda al cliente por mail un PRESUPUESTO, con el mismo molde que el mail de una venta
     * (misión asistente-envios-a-clientes, 16/10/2026). Hasta esta misión un presupuesto no se podía
     * mandar por ninguna vía (`BudgetHelper::sendMail` es código muerto).
     *
     * Mismo molde que new_sale(), y a propósito: mismo `ComercioCityMail`, mismo remitente (no llama a
     * `ClientMailConfigHelper::apply()`, igual que la venta), mismos renglones de detalle y un link al
     * PDF con su token (el cliente que abre el mail no tiene sesión en el sistema). El link lo arma el
     * que llama —el mismo que la tarjeta del asistente le mostró al dueño antes del sí—, así lo que se
     * vio es lo que sale.
     *
     * 🔴 La extensión `enviar_mail_a_clientes` la chequea el que llama, igual que con la venta
     * (`SaleController::send_client_mail()` la mira antes de llegar a new_sale()).
     *
     * @param \App\Models\Budget $budget  Presupuesto del negocio, con su cliente.
     * @param string             $pdf_url URL del PDF del presupuesto, YA con su token.
     * @return string|null El mail al que se encoló, o null si el cliente no tiene uno válido.
     */
    public static function nuevo_presupuesto(Budget $budget, $pdf_url)
    {
        $budget->loadMissing('client');

        $client = $budget->client;

        $email = is_null($client) ? null : OfertaComunicacionHelper::email_valido($client->email);

        if (is_null($email)) {
            return null;
        }

        self::encolar_al_cliente($email, self::payload_de_presupuesto($budget, $pdf_url));

        return $email;
    }

    /**
     * El contenido del mail de un presupuesto, tal cual lo encola nuevo_presupuesto(). Público para
     * que el asistente le muestre al dueño el asunto y el texto reales antes del sí.
     *
     * @param \App\Models\Budget $budget  Presupuesto con su cliente.
     * @param string             $pdf_url URL del PDF, YA con su token.
     * @return ComercioCityMailPayload
     */
    public static function payload_de_presupuesto(Budget $budget, $pdf_url)
    {
        $budget->loadMissing('client');

        $client = $budget->client;

        $numero = $budget->num !== null && $budget->num !== '' ? (string) $budget->num : (string) $budget->id;
        $total = self::format_monto($budget->total, $budget->moneda_id);

        return new ComercioCityMailPayload([
            'subject' => 'Te enviamos un presupuesto',
            'title' => 'Tu presupuesto',
            'paragraphs' => [
                'Te enviamos el presupuesto que preparamos para vos.',
                'En el link de abajo podés ver el detalle completo.',
            ],
            'detail_lines' => [
                ['label' => 'Cliente', 'value' => (!is_null($client) && $client->name) ? $client->name : '—', 'bold_label' => true],
                ['label' => 'Nº presupuesto', 'value' => $numero, 'bold_label' => true],
                ['label' => 'Total', 'value' => $total, 'bold_label' => true],
                ['label' => 'Fecha', 'value' => self::format_fecha_hora($budget->created_at), 'bold_label' => true],
            ],
            'links' => [
                ['text' => 'Ver el presupuesto', 'url' => (string) $pdf_url],
            ],
            'closing' => 'Muchas gracias por elegirnos.',
            'preheader' => 'Presupuesto #' . $numero . ' · ' . $total,
        ]);
    }

    /**
     * Le manda al cliente por mail un RECIBO (el comprobante de un pago que hizo a su cuenta
     * corriente), con el mismo molde que el mail de una venta (misión asistente-envios-a-clientes,
     * 16/10/2026). Ver nuevo_presupuesto(): mismo molde, mismo remitente, link al PDF con token.
     *
     * @param \App\Models\CurrentAcount $recibo  Movimiento `pago_from_client` de un cliente.
     * @param string                    $pdf_url URL del PDF del recibo, YA con su token.
     * @return string|null El mail al que se encoló, o null si el cliente no tiene uno válido.
     */
    public static function nuevo_recibo(CurrentAcount $recibo, $pdf_url)
    {
        $recibo->loadMissing('client');

        $client = $recibo->client;

        $email = is_null($client) ? null : OfertaComunicacionHelper::email_valido($client->email);

        if (is_null($email)) {
            return null;
        }

        self::encolar_al_cliente($email, self::payload_de_recibo($recibo, $pdf_url));

        return $email;
    }

    /**
     * El contenido del mail de un recibo, tal cual lo encola nuevo_recibo(). Público para que el
     * asistente le muestre al dueño el asunto y el texto reales antes del sí.
     *
     * @param \App\Models\CurrentAcount $recibo  Pago de un cliente.
     * @param string                    $pdf_url URL del PDF, YA con su token.
     * @return ComercioCityMailPayload
     */
    public static function payload_de_recibo(CurrentAcount $recibo, $pdf_url)
    {
        $recibo->loadMissing('client');

        $client = $recibo->client;

        $numero = $recibo->num_receipt !== null && $recibo->num_receipt !== '' ? (string) $recibo->num_receipt : (string) $recibo->id;
        $monto = self::format_monto($recibo->haber, $recibo->moneda_id);

        return new ComercioCityMailPayload([
            'subject' => 'Te enviamos el recibo de tu pago',
            'title' => 'Recibimos tu pago',
            'paragraphs' => [
                'Te enviamos el recibo del pago que registramos en tu cuenta.',
                'En el link de abajo podés ver el detalle completo.',
            ],
            'detail_lines' => [
                ['label' => 'Cliente', 'value' => (!is_null($client) && $client->name) ? $client->name : '—', 'bold_label' => true],
                ['label' => 'Nº recibo', 'value' => $numero, 'bold_label' => true],
                ['label' => 'Importe', 'value' => $monto, 'bold_label' => true],
                ['label' => 'Fecha', 'value' => self::format_fecha_hora($recibo->created_at), 'bold_label' => true],
            ],
            'links' => [
                ['text' => 'Ver el recibo', 'url' => (string) $pdf_url],
            ],
            'closing' => 'Muchas gracias por elegirnos.',
            'preheader' => 'Recibo #' . $numero . ' · ' . $monto,
        ]);
    }

    /**
     * El encolado de los dos mails de comprobante del asistente (presupuesto y recibo), en UN solo
     * lugar.
     *
     * 🔴 ->queue() y no ->send(), por lo mismo que new_sale() (ver su comentario): no se bloquea al
     * asistente esperando al SMTP, y el rechazo de la casilla (que pasa en el worker) lo deja en el
     * log el listener AnotarMailRechazadoPorElServidor. La decisión está escrita en
     * TodoEnvioDeMailMiraLosRechazosTest::ENCOLADOS. Y por eso el asistente dice "encolado", no
     * "entregado".
     *
     * @param string                  $email
     * @param ComercioCityMailPayload $payload
     * @return void
     */
    private static function encolar_al_cliente($email, ComercioCityMailPayload $payload)
    {
        Mail::to($email)->queue(new ComercioCityMail($payload));

        Log::info('Se encoló mail de comprobante a '.$email);
    }

    /**
     * @param mixed $fecha
     * @return string dd/mm/aaaa hh:mm, en la zona del sistema ('' si no hay fecha).
     */
    private static function format_fecha_hora($fecha)
    {
        if (empty($fecha)) {
            return '';
        }

        return Carbon::parse($fecha)
            ->timezone(config('app.timezone'))
            ->format('d/m/Y H:i');
    }

    /**
     * Un monto con su moneda, con el mismo formato que format_total() de la venta.
     *
     * @param mixed    $monto
     * @param int|null $moneda_id
     * @return string
     */
    private static function format_monto($monto, $moneda_id)
    {
        if ($monto === null) {
            return '—';
        }

        $formatted = number_format((float) $monto, 2, ',', '.');

        $moneda = is_null($moneda_id) ? null : Moneda::find($moneda_id);

        if ($moneda && !empty($moneda->name)) {
            return $formatted . ' ' . $moneda->name;
        }

        return '$' . $formatted;
    }

    /**
     * @param mixed $fecha
     * @return string dd/mm/aaaa
     */
    private static function format_fecha($fecha)
    {
        if (empty($fecha)) {
            return '';
        }

        return Carbon::parse($fecha)->format('d/m/Y');
    }

    /**
     * @return string
     */
    private static function format_created_at(Sale $sale)
    {
        if (empty($sale->created_at)) {
            return '';
        }

        return Carbon::parse($sale->created_at)
            ->timezone(config('app.timezone'))
            ->format('d/m/Y H:i');
    }

    /**
     * @return string
     */
    private static function format_total(Sale $sale)
    {
        $amount = $sale->total;
        if ($amount === null) {
            return '—';
        }

        $formatted = number_format((float) $amount, 2, ',', '.');
        $moneda = $sale->moneda;
        if ($moneda && !empty($moneda->name)) {
            return $formatted . ' ' . $moneda->name;
        }

        return '$'.$formatted;
    }
}
