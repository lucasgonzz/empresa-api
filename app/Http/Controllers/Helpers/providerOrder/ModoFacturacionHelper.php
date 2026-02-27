<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use Illuminate\Support\Facades\Log;

class ModoFacturacionHelper
{
    public static function check_modo_facturacion($provider_order, $helper): void
    {
        if ($provider_order->modo_facturacion == 'automatico') {
            self::calcular_iva($provider_order, $helper);
            return;
        } else if ($provider_order->modo_facturacion == 'sin factura') {
            ProviderOrderAfipTicket::where('provider_order_id', $provider_order->id)->delete();
        }

        // manual: no tocamos nada (el usuario carga tickets)
        // not_invoiced: no tocamos nada (queda como está)
    }

    private static function calcular_iva(ProviderOrder $provider_order, $helper): void
    {
        // 1) Deja solo el primer afip_ticket del provider_order
        $afip_tickets = ProviderOrderAfipTicket::where('provider_order_id', $provider_order->id)->get();
        $index = 0;
        foreach ($afip_tickets as $afip_ticket) {
            if ($index >= 1) {
                $afip_ticket->delete();
            } else {
                ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $afip_ticket->id)->delete();
                $ticket = $afip_ticket;
                Log::info('Eliminando ivas');
            }
            $index++;
        }


        // 2) crea 1 ticket "principal" vacío en caso de que no haya habido uno ya creado (usuario completa percepciones/retenciones/descripción/etc)
        if (count($afip_tickets) == 0) {

            $ticket = ProviderOrderAfipTicket::create([
                'provider_order_id' => $provider_order->id,
                'issued_at'         => $provider_order->created_at,
                'total_iva'         => 0,
                'total'             => 0,
                'retenciones'       => 0, // lo tenés; luego lo vamos descontinuando si separás retenciones
                'user_id'           => $provider_order->user_id ?? null,
                // 'auto_calculated'   => 1,
            ]);
        } 


        // 3) autocalcular IVA por alícuota desde artículos (pivot iva_id + cost + amount + discount)
        $ivas = self::get_ivas($provider_order, $helper);

        // Si todavía no creaste la tabla ticket_ivas, por ahora
        // solo calculamos totales generales:
        $total_neto = 0;
        $total_iva  = 0;

        foreach ($ivas as $iva_id => $value) {
            $total_neto += $value['neto'];
            $total_iva  += $value['importe_iva'];

            Log::info('Sumando neto de:');
            Log::info($value);

            ProviderOrderAfipTicketIva::create([
                'provider_order_afip_ticket_id' => $ticket->id,
                'iva_id'                        => $iva_id,
                'neto'                          => $value['neto'],
                'iva_importe'                   => $value['importe_iva'],
            ]);
        }

        $ticket->total_iva = round($total_iva, 2);
        $ticket->total     = round($total_neto + $total_iva, 2);
        $ticket->save();
    }

    private static function get_ivas($provider_order, $helper): array
    {
        $provider_order->loadMissing('articles');

        $ivas = []; 

        foreach ($provider_order->articles as $article) {


            $res            = $helper->get_total_article($article);
            $total_article  = $res['total_article'];
            $article_iva    = $res['article_iva'];

            $iva_id = (int)$article_iva['iva_id'];

            if ($iva_id <= 0) {
                continue;
            }

            if (isset($ivas[$iva_id])) {

                $ivas[$iva_id]['neto']          += $article_iva['neto'];
                $ivas[$iva_id]['importe_iva']   += $article_iva['importe_iva'];

            } else {
                $ivas[$iva_id] = [
                    'neto'          => $article_iva['neto'],
                    'importe_iva'   => $article_iva['importe_iva'],
                ];
            }
        }

        // // devolver como array indexado
        // $result = array_values($acc);

        // opcional: ordenar por porcentaje
        // usort($result, fn($a, $b) => ($a['percentage'] <=> $b['percentage']));

        // redondeo
        // foreach ($result as &$r) {
        //     $r['neto'] = round((float)$r['neto'], 2);
        //     $r['iva_importe'] = round((float)$r['iva_importe'], 2);
        // }

        return $ivas;
    }
}