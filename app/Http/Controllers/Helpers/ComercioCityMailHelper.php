<?php

namespace App\Http\Controllers\Helpers;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Models\ClientOffer;
use App\Models\CreditAccount;
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


        if ($pdf_profile) {
            $links[] = [
                'text' => 'Ver comprobante en ComercioCity',
                'url'  => $sale->user->api_url.'/sale/pdf/'.$sale->id.'?pdf_column_profile_id='.$pdf_profile->id,
            ];
        }

        if ($credit_account) {
            $links[] = [
                'text' => 'Ver mi cuenta corriente en ComercioCity',
                'url'  => $sale->user->api_url.'/current-acount/pdf/'.$credit_account->id.'/30/simple',
            ];
        }


        $payload = new ComercioCityMailPayload([
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
