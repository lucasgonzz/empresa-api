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
        // Prompt 515: `check_modo_facturacion()` corre ANTES de `$helper->procesar_pedido()`
        // (ver ProviderOrderController::store/update), o sea que `provider_order->sub_total` y
        // `->descuentos_compra` todavía no están frescos para esta orden (los setea
        // `set_totales()`, que corre después). El prorrateo del descuento de compra por línea
        // (más abajo, en get_ivas()) necesita esos dos valores YA calculados, así que se fuerza
        // el cálculo acá. Es idempotente/determinístico (siempre recalcula desde
        // articles/provider_order_discounts, no acumula) — que `procesar_pedido()` lo vuelva a
        // llamar después no cambia el resultado, solo lo deja consistente si además cambia el
        // total en base a las facturas (`total_from_provider_order_afip_tickets`).
        $helper->set_totales();

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

    /**
     * Prompt 515: arma el desglose neto/IVA que va a `provider_order_afip_ticket_ivas`,
     * calculado POR LÍNEA y POR ALÍCUOTA (nunca sobre el total de la orden), porque una compra
     * puede mezclar artículos con distinto `iva_id` (ej. 21% y 10,5%).
     *
     * Por cada línea se restan, ANTES de calcular el IVA:
     *   (a) el descuento individual del pivot (ya lo resta `get_total_article()` en
     *       `total_article`), y
     *   (b) la cuota que le toca a esa línea del descuento de compra HEREDADO del proveedor
     *       (`provider_order->descuentos_compra`, calculado por
     *       `NewProviderOrderHelper::set_totales()`), prorrateada por el mismo criterio que el
     *       prorrateo de costos extra/flete (prompt 264): peso = subtotal bruto de la línea /
     *       subtotal bruto de la orden. A la ÚLTIMA línea con IVA se le asigna el resto exacto
     *       (en vez de su cuota por peso) para que la suma de las cuotas cierre sin centavos
     *       perdidos por redondeo flotante contra `descuentos_compra`.
     *
     * Con el costo de la línea ya neto de ambos descuentos, el neto/IVA se calcula según
     * `provider_order->precios_incluyen_iva` (prompt 513/514):
     *   - false (default): el costo cargado es NETO -> `neto = costo`, `iva = neto * alicuota`.
     *   - true: el costo cargado es FINAL (con IVA incluido) -> modo final->neto, mismo criterio
     *     que `ArticlePricesHelper::back_out_iva()` pero aplicado por línea con la alícuota de
     *     esa línea: `neto = costo / (1 + alicuota)`, `iva = costo - neto`.
     */
    private static function get_ivas($provider_order, $helper): array
    {
        $provider_order->loadMissing('articles');

        $ivas = [];

        // Base del prorrateo: subtotal bruto de la orden (sin descuentos) ya calculado por
        // set_totales(), y el total de descuento de compra heredado del proveedor a repartir.
        $total_articulos = (float)$provider_order->sub_total;
        $descuento_compra = (float)$provider_order->descuentos_compra;

        // Cuántas líneas tienen IVA cargado (iva_id > 0): a la última de ellas se le asigna el
        // resto del descuento de compra sin repartir, para que el prorrateo cierre exacto.
        $total_lineas_con_iva = 0;
        foreach ($provider_order->articles as $article) {
            if ((int)$article->pivot->iva_id > 0) {
                $total_lineas_con_iva++;
            }
        }

        $descuento_compra_repartido = 0;
        $index_con_iva = 0;

        foreach ($provider_order->articles as $article) {

            $res                = $helper->get_total_article($article);
            $total_article      = $res['total_article']; // ya con el descuento individual restado
            $sub_total_article  = $res['sub_total_article']; // bruto, sin descuentos
            $article_iva        = $res['article_iva'];

            $iva_id = (int)$article_iva['iva_id'];

            if ($iva_id <= 0) {
                continue;
            }

            $index_con_iva++;

            // Cuota del descuento de compra que le toca a esta línea, prorrateada por su peso
            // bruto en el subtotal de la orden (mismo patrón que aplicar_costos_extra_a_recargos_articulos).
            if ($total_articulos > 0 && $descuento_compra > 0) {

                if ($index_con_iva == $total_lineas_con_iva) {
                    // Última línea con IVA: se lleva el resto exacto (evita descuadre por
                    // redondeo flotante acumulado en las cuotas anteriores).
                    $descuento_compra_linea = $descuento_compra - $descuento_compra_repartido;
                } else {
                    $descuento_compra_linea = $descuento_compra * $sub_total_article / $total_articulos;
                }
            } else {
                $descuento_compra_linea = 0;
            }

            $descuento_compra_repartido += $descuento_compra_linea;

            // Costo de la línea con AMBOS descuentos aplicados (individual + cuota de compra),
            // base sobre la que se calcula el neto/IVA de esta línea.
            $costo_con_descuentos = $total_article - $descuento_compra_linea;

            $iva = $helper->get_iva($iva_id);

            if (is_null($iva)) {
                continue;
            }

            $alicuota = (float)$iva->percentage;

            if ($provider_order->precios_incluyen_iva) {

                // Modo final -> neto: el costo cargado en la línea ya trae el IVA incluido.
                $neto        = $alicuota > 0 ? ($costo_con_descuentos / (1 + ($alicuota / 100))) : $costo_con_descuentos;
                $importe_iva = $costo_con_descuentos - $neto;
            } else {

                // Modo neto -> IVA (comportamiento de siempre).
                $neto        = $costo_con_descuentos;
                $importe_iva = $neto * $alicuota / 100;
            }

            if (isset($ivas[$iva_id])) {

                $ivas[$iva_id]['neto']          += $neto;
                $ivas[$iva_id]['importe_iva']   += $importe_iva;

            } else {
                $ivas[$iva_id] = [
                    'neto'          => $neto,
                    'importe_iva'   => $importe_iva,
                ];
            }
        }

        return $ivas;
    }
}