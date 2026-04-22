<?php

namespace App\Http\Controllers\Helpers;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Models\CreditAccount;
use App\Models\PdfColumnOption;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ComercioCityMailHelper
{
    /**
     * Envía al cliente un correo por nueva venta registrada.
     *
     * No hace nada si la venta no tiene cliente o el cliente no tiene email.
     */
    public static function new_sale(Sale $sale, $updated = false)
    {
        if (!$sale->send_mail) {
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

        Mail::to($email)->queue(new ComercioCityMail($payload));

        Log::info('Se mando mail a '.$email);
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
