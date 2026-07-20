<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderExtraCost;
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

        // Prompt 516: los costos extra (flete, seguro) facturados con `en_factura_compra = true`
        // entran en la MISMA factura de la compra: se mergean por alícuota propia (`iva_id` del
        // costo extra, no la de los artículos) junto con el desglose de los artículos. Los
        // costos extra facturados con `en_factura_compra = false` NO entran acá: se resuelven
        // aparte, en un comprobante propio (ver más abajo), porque tienen otro emisor.
        $ivas = self::merge_ivas($ivas, self::get_ivas_costos_extra_en_factura($provider_order, $helper));

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

        // Prompt 516: costos extra facturados con `en_factura_compra = false` (ej. flete
        // tercerizado, facturado por otro CUIT) generan comprobante(s) APARTE, uno por emisor
        // distinto, con su propio desglose de IVA. Corre después de guardar el ticket principal
        // porque no lo toca (son registros `provider_order_afip_ticket` independientes).
        self::crear_tickets_costos_extra_aparte($provider_order, $helper);
    }

    /**
     * Suma dos desgloses de IVA (`[iva_id => ['neto' => x, 'importe_iva' => y]]`), acumulando
     * neto e importe_iva por alícuota cuando la misma `iva_id` aparece en ambos.
     *
     * @param  array $ivas_base
     * @param  array $ivas_extra
     * @return array
     */
    private static function merge_ivas(array $ivas_base, array $ivas_extra): array
    {
        foreach ($ivas_extra as $iva_id => $value) {

            if (isset($ivas_base[$iva_id])) {

                $ivas_base[$iva_id]['neto']        += $value['neto'];
                $ivas_base[$iva_id]['importe_iva'] += $value['importe_iva'];

            } else {
                $ivas_base[$iva_id] = $value;
            }
        }

        return $ivas_base;
    }

    /**
     * Prompt 516: desglose neto/IVA (por `iva_id` PROPIO del costo extra, no el de los
     * artículos) de los costos extra facturados que van DENTRO de la factura principal de la
     * compra (`facturado = true` y `en_factura_compra = true`).
     *
     * Se asume que `provider_order_extra_costs->value` es el monto TAL COMO SE FACTURÓ (con IVA
     * incluido, igual que un renglón de factura real de flete/seguro) — mismo criterio de
     * back-out "final -> neto" que `get_ivas()` (515) y `ArticlePricesHelper::back_out_iva()`
     * (514): `neto = bruto / (1 + alicuota/100)`, `iva = bruto - neto`.
     *
     * @param  ProviderOrder $provider_order
     * @param  $helper                        NewProviderOrderHelper (para `get_iva()`).
     * @return array                          [iva_id => ['neto' => x, 'importe_iva' => y]]
     */
    private static function get_ivas_costos_extra_en_factura(ProviderOrder $provider_order, $helper): array
    {
        $provider_order->loadMissing('provider_order_extra_costs');

        $ivas = [];

        foreach ($provider_order->provider_order_extra_costs as $extra_cost) {

            if (!$extra_cost->facturado || !$extra_cost->en_factura_compra) {
                continue;
            }

            $desglose = self::desglosar_costo_extra($extra_cost, $helper);

            if (is_null($desglose)) {
                continue;
            }

            $iva_id = (int)$extra_cost->iva_id;

            if (isset($ivas[$iva_id])) {
                $ivas[$iva_id]['neto']        += $desglose['neto'];
                $ivas[$iva_id]['importe_iva'] += $desglose['importe_iva'];
            } else {
                $ivas[$iva_id] = $desglose;
            }
        }

        return $ivas;
    }

    /**
     * Prompt 516: genera un `provider_order_afip_ticket` APARTE (con su propio `emisor_cuit`/
     * `emisor_razon_social`) por cada emisor distinto entre los costos extra facturados con
     * `en_factura_compra = false`. Ej: dos costos extra "flete" facturados por el mismo
     * transportista van en UN solo comprobante; si hubiera costos extra de emisores distintos,
     * cada uno se agrupa en su propio comprobante.
     *
     * No crea comprobantes vacíos: si no hay costos extra en esta condición, no hace nada. Los
     * comprobantes de una corrida anterior ya fueron eliminados por `calcular_iva()` (paso 1,
     * "deja solo el primer afip_ticket"), así que esta función siempre parte de cero.
     *
     * @param  ProviderOrder $provider_order
     * @param  $helper                        NewProviderOrderHelper (para `get_iva()`).
     * @return void
     */
    private static function crear_tickets_costos_extra_aparte(ProviderOrder $provider_order, $helper): void
    {
        $provider_order->loadMissing('provider_order_extra_costs');

        // Agrupa los costos extra "aparte" por emisor (CUIT como clave principal; si no hay
        // CUIT cargado, se agrupa por razón social; si no hay ninguno de los dos, van todos
        // juntos en un comprobante "sin emisor cargado" para no perder el desglose de IVA).
        $grupos = [];

        foreach ($provider_order->provider_order_extra_costs as $extra_cost) {

            if (!$extra_cost->facturado || $extra_cost->en_factura_compra) {
                continue;
            }

            $desglose = self::desglosar_costo_extra($extra_cost, $helper);

            if (is_null($desglose)) {
                continue;
            }

            $cuit   = trim((string)$extra_cost->emisor_cuit);
            $razon  = trim((string)$extra_cost->emisor_razon_social);
            $clave  = $cuit !== '' ? $cuit : ($razon !== '' ? 'razon:'.$razon : 'sin_emisor');

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'emisor_cuit'         => $cuit !== '' ? $cuit : null,
                    'emisor_razon_social' => $razon !== '' ? $razon : null,
                    'ivas'                => [],
                ];
            }

            $iva_id = (int)$extra_cost->iva_id;

            if (isset($grupos[$clave]['ivas'][$iva_id])) {
                $grupos[$clave]['ivas'][$iva_id]['neto']        += $desglose['neto'];
                $grupos[$clave]['ivas'][$iva_id]['importe_iva'] += $desglose['importe_iva'];
            } else {
                $grupos[$clave]['ivas'][$iva_id] = $desglose;
            }
        }

        foreach ($grupos as $grupo) {

            $total_neto = 0;
            $total_iva  = 0;

            foreach ($grupo['ivas'] as $value) {
                $total_neto += $value['neto'];
                $total_iva  += $value['importe_iva'];
            }

            $ticket_aparte = ProviderOrderAfipTicket::create([
                'provider_order_id'    => $provider_order->id,
                'issued_at'            => $provider_order->created_at,
                'emisor_cuit'          => $grupo['emisor_cuit'],
                'emisor_razon_social'  => $grupo['emisor_razon_social'],
                'total_iva'            => round($total_iva, 2),
                'total'                => round($total_neto + $total_iva, 2),
                'retenciones'          => 0,
                'user_id'              => $provider_order->user_id ?? null,
            ]);

            foreach ($grupo['ivas'] as $iva_id => $value) {
                ProviderOrderAfipTicketIva::create([
                    'provider_order_afip_ticket_id' => $ticket_aparte->id,
                    'iva_id'                        => $iva_id,
                    'neto'                          => $value['neto'],
                    'iva_importe'                   => $value['importe_iva'],
                ]);
            }
        }
    }

    /**
     * Prompt 516: desglosa neto/IVA de UN costo extra facturado, usando su propia `iva_id` (no
     * la de los artículos). Devuelve null si el costo extra no tiene valor positivo o no tiene
     * `iva_id` cargado/válido (no se puede facturar sin alícuota).
     *
     * Mismo criterio "final -> neto" que el resto del prorrateo de IVA de la orden (515/514):
     * `neto = bruto / (1 + alicuota/100)`, `iva = bruto - neto`.
     *
     * @param  ProviderOrderExtraCost $extra_cost
     * @param  $helper                             NewProviderOrderHelper (para `get_iva()`).
     * @return array|null                          ['neto' => x, 'importe_iva' => y] o null.
     */
    private static function desglosar_costo_extra($extra_cost, $helper)
    {
        $valor = (float)$extra_cost->value;

        if ($valor <= 0) {
            return null;
        }

        $iva_id = (int)$extra_cost->iva_id;

        if ($iva_id <= 0) {
            return null;
        }

        $iva = $helper->get_iva($iva_id);

        if (is_null($iva)) {
            return null;
        }

        $alicuota = (float)$iva->percentage;

        $neto        = $alicuota > 0 ? ($valor / (1 + ($alicuota / 100))) : $valor;
        $importe_iva = $valor - $neto;

        return [
            'neto'        => $neto,
            'importe_iva' => $importe_iva,
        ];
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