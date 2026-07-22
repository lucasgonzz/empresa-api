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

        // 1) Deja solo el primer afip_ticket "principal" del provider_order (el de la compra en
        // sí). Prompt 610: se filtra explícitamente por `provider_order_extra_cost_id IS NULL`
        // porque los comprobantes "aparte" generados por un costo extra (ver
        // sincronizar_tickets_costos_extra_aparte() más abajo) tienen su propio ciclo de vida
        // idempotente — si se los dejara caer acá, se borrarían y recrearían en cada guardado de
        // la compra, perdiendo el número de comprobante/fecha que el usuario ya les hubiera
        // cargado a mano.
        $afip_tickets = ProviderOrderAfipTicket::where('provider_order_id', $provider_order->id)
                                                ->whereNull('provider_order_extra_cost_id')
                                                ->get();
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


        // Prompt 610 — Tarea 1: qué muestra la factura automática depende de la condición de
        // IVA de la cuenta (misma fuente de verdad que el Prompt 609: `NewProviderOrderHelper::
        // get_condicion_iva_precios()`, con su mismo fallback seguro a 'RRII' mientras
        // `condicion_iva_precios` no exista como columna real).
        $condicion = $helper->get_condicion_iva_precios();

        if ($condicion == 'MT') {
            self::calcular_ticket_monotributista($provider_order, $ticket);
        } else {
            self::calcular_ticket_responsable_inscripto($provider_order, $helper, $ticket);
        }

        // Prompt 516/610: costos extra facturados con `en_factura_compra = false` (ej. flete
        // tercerizado, facturado por otro CUIT) generan un comprobante APARTE por costo extra,
        // idempotente (no se duplica al re-guardar la compra). Corre después de guardar el
        // ticket principal porque no lo toca (son registros `provider_order_afip_ticket`
        // independientes).
        self::sincronizar_tickets_costos_extra_aparte($provider_order, $helper, $condicion);
    }

    /**
     * Prompt 610 — Tarea 1: arma el ticket "principal" de la compra para una cuenta Responsable
     * Inscripto — comportamiento de siempre: neto + desglose de IVA por alícuota
     * (`provider_order_afip_ticket_ivas`) + total.
     *
     * @param  ProviderOrder                                          $provider_order
     * @param  \App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper $helper
     * @param  ProviderOrderAfipTicket                                $ticket          Ticket principal ya creado/reusado.
     * @return void
     */
    private static function calcular_ticket_responsable_inscripto(ProviderOrder $provider_order, $helper, ProviderOrderAfipTicket $ticket): void
    {
        // autocalcular IVA por alícuota desde artículos (pivot iva_id + cost + amount + discount)
        $ivas = self::get_ivas($provider_order, $helper);

        // Prompt 516: los costos extra (flete, seguro) facturados con `en_factura_compra = true`
        // entran en la MISMA factura de la compra: se mergean por alícuota propia (`iva_id` del
        // costo extra, no la de los artículos) junto con el desglose de los artículos. Los
        // costos extra facturados con `en_factura_compra = false` NO entran acá: se resuelven
        // aparte, en un comprobante propio, porque tienen otro emisor.
        $ivas = self::merge_ivas($ivas, self::get_ivas_costos_extra_en_factura($provider_order, $helper));

        // Prompt 610 — Tarea 1: redondea cada alícuota a 2 decimales (mismo tipo de columna que
        // `provider_order_afip_ticket_ivas.neto`/`iva_importe`) y absorbe en la de mayor importe
        // el centavo de diferencia que puede quedar por redondear cada fila de forma
        // independiente, para que neto + IVA sumado por fila dé EXACTAMENTE el total del
        // comprobante (crítico con `precios_incluyen_iva = true`, donde el neto sale de
        // descomponer el bruto línea por línea con su propia alícuota).
        $ivas = self::redondear_ivas_sin_diferencia($ivas);

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
     * Prompt 610 — Tarea 1: arma el ticket "principal" de la compra para una cuenta
     * Monotributista — un MT no discrimina IVA (lo que paga es un monto final, sin crédito
     * fiscal recuperable), así que la factura le muestra SOLO el total: sin neto, sin desglose
     * de IVA (no se crean `provider_order_afip_ticket_ivas`), sin total de IVA.
     *
     * El total es la suma de `cantidad * costo_pagado` de cada línea (ya neto de descuentos
     * individuales y de compra, calculados por `NewProviderOrderHelper::set_totales()`, que
     * corrió justo antes en `calcular_iva()`) más los costos extra que entran en esta misma
     * factura (`facturado = true` y `en_factura_compra = true`), tomados por su valor BRUTO
     * completo — un MT no le saca IVA a nada.
     *
     * `total_iva` queda en `NULL` a propósito (no en `0`): para un MT el IVA "no aplica" (no se
     * calcula), algo distinto de "el IVA da 0", que sí puede pasar en una cuenta RRII con
     * artículos exentos. Deja que el frontend distinga ambos casos.
     *
     * @param  ProviderOrder            $provider_order
     * @param  ProviderOrderAfipTicket  $ticket          Ticket principal ya creado/reusado.
     * @return void
     */
    private static function calcular_ticket_monotributista(ProviderOrder $provider_order, ProviderOrderAfipTicket $ticket): void
    {
        // Suma de líneas ya neta de descuentos individuales y de compra ($sub_total = bruto de
        // todas las líneas sin descontar nada, $total_descuento = individuales + de compra ya
        // sumados por set_totales()).
        $total_lineas = (float)$provider_order->sub_total - (float)$provider_order->total_descuento;

        $total_extra_en_factura = 0;

        $provider_order->loadMissing('provider_order_extra_costs');

        foreach ($provider_order->provider_order_extra_costs as $extra_cost) {

            if ($extra_cost->facturado && $extra_cost->en_factura_compra) {
                $total_extra_en_factura += (float)$extra_cost->value;
            }
        }

        $ticket->total_iva = null;
        $ticket->total      = round($total_lineas + $total_extra_en_factura, 2);
        $ticket->save();
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
     * Prompt 516/610: sincroniza (crea o actualiza, sin duplicar) un `provider_order_afip_ticket`
     * APARTE por cada costo extra facturado con `en_factura_compra = false` (ej. flete
     * tercerizado, facturado por otro CUIT distinto del proveedor de la compra).
     *
     * Idempotencia (Prompt 610, Tarea 3 — antes no existía y era el bug real: `calcular_iva()`
     * borraba y recreaba TODOS los comprobantes en cada guardado de la compra, perdiendo el
     * número de comprobante/fecha que el usuario ya le hubiera cargado a mano al comprobante
     * aparte): cada comprobante aparte queda referenciado 1 a 1 a su costo extra de origen vía
     * `provider_order_afip_tickets.provider_order_extra_cost_id` (columna agregada en este mismo
     * prompt). Antes de crear uno nuevo, se busca si ya existe el de ese costo extra puntual —
     * si existe, se actualiza (total/IVA/emisor) sin tocar su `id` ni el `code`/fecha que el
     * usuario ya haya completado. Al final se borran los comprobantes aparte "huérfanos" (el
     * costo extra que los generó se borró, dejó de estar facturado, o pasó a
     * `en_factura_compra = true`).
     *
     * Nota (cambio de criterio respecto del Prompt 516): antes se agrupaban varios costos extra
     * del MISMO emisor en un solo comprobante ("dos fletes del mismo transportista van juntos").
     * Se pasa a UN comprobante POR costo extra porque la idempotencia pedida acá necesita una
     * referencia 1 a 1 clara (`provider_order_extra_cost_id`) para saber qué comprobante
     * corresponde a qué costo extra sin ambigüedad — agrupar por emisor complicaría esa
     * referencia (un comprobante ligado a N costos extra) sin necesidad real planteada por este
     * prompt. Si más adelante hace falta volver a agrupar por emisor, se puede resolver con una
     * tabla pivot dedicada; queda fuera de alcance acá.
     *
     * @param  ProviderOrder $provider_order
     * @param  $helper                        NewProviderOrderHelper (para `get_iva()`).
     * @param  string        $condicion       'RRII' o 'MT' (misma condición que decide el
     *                                        ticket principal).
     * @return void
     */
    private static function sincronizar_tickets_costos_extra_aparte(ProviderOrder $provider_order, $helper, string $condicion): void
    {
        $provider_order->loadMissing('provider_order_extra_costs');

        // IDs de costo extra que siguen calificando para comprobante aparte en esta corrida —
        // se usa al final para detectar y borrar comprobantes "huérfanos".
        $extra_cost_ids_vigentes = [];

        foreach ($provider_order->provider_order_extra_costs as $extra_cost) {

            if (!$extra_cost->facturado || $extra_cost->en_factura_compra) {
                continue;
            }

            $valor_bruto = (float)$extra_cost->value;

            if ($valor_bruto <= 0) {
                continue;
            }

            $extra_cost_ids_vigentes[] = $extra_cost->id;

            // Busca el comprobante YA generado para este costo extra puntual (idempotencia); si
            // no existe todavía, se crea uno nuevo (sin `code`/fecha propia: eso lo completa el
            // usuario después, mismo criterio que el comprobante principal auto-generado).
            $ticket_aparte = ProviderOrderAfipTicket::where('provider_order_extra_cost_id', $extra_cost->id)->first();

            if (is_null($ticket_aparte)) {
                $ticket_aparte                                = new ProviderOrderAfipTicket();
                $ticket_aparte->provider_order_id             = $provider_order->id;
                $ticket_aparte->provider_order_extra_cost_id  = $extra_cost->id;
                $ticket_aparte->issued_at                     = $provider_order->created_at;
                $ticket_aparte->retenciones                   = 0;
                $ticket_aparte->user_id                       = $provider_order->user_id ?? null;
            }

            $ticket_aparte->emisor_cuit         = trim((string)$extra_cost->emisor_cuit) !== '' ? $extra_cost->emisor_cuit : null;
            $ticket_aparte->emisor_razon_social = trim((string)$extra_cost->emisor_razon_social) !== '' ? $extra_cost->emisor_razon_social : null;

            $desglose = ($condicion == 'MT') ? null : self::desglosar_costo_extra($extra_cost, $helper);

            if (is_null($desglose)) {

                // MT (no discrimina IVA) o RRII sin alícuota cargada (no se puede desglosar):
                // en ambos casos se guarda directo el bruto como total, sin desglose de IVA.
                $ticket_aparte->total_iva = null;
                $ticket_aparte->total     = round($valor_bruto, 2);
                $ticket_aparte->save();

                ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket_aparte->id)->delete();

            } else {

                // RRII con alícuota cargada: desglose neto/IVA con la alícuota PROPIA del costo
                // extra (redondeado a 2 decimales, mismo criterio que el ticket principal).
                $ivas_redondeados = self::redondear_ivas_sin_diferencia([(int)$extra_cost->iva_id => $desglose]);
                $valor_redondeado = reset($ivas_redondeados);

                $ticket_aparte->total_iva = round($valor_redondeado['importe_iva'], 2);
                $ticket_aparte->total     = round($valor_redondeado['neto'] + $valor_redondeado['importe_iva'], 2);
                $ticket_aparte->save();

                ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket_aparte->id)->delete();

                ProviderOrderAfipTicketIva::create([
                    'provider_order_afip_ticket_id' => $ticket_aparte->id,
                    'iva_id'                         => (int)$extra_cost->iva_id,
                    'neto'                           => $valor_redondeado['neto'],
                    'iva_importe'                    => $valor_redondeado['importe_iva'],
                ]);
            }
        }

        // Limpieza de comprobantes aparte "huérfanos": el costo extra que los generó se borró,
        // dejó de estar facturado, o pasó a `en_factura_compra = true` (ya no le corresponde
        // comprobante aparte).
        $query_huerfanos = ProviderOrderAfipTicket::where('provider_order_id', $provider_order->id)
                                                    ->whereNotNull('provider_order_extra_cost_id');

        if (count($extra_cost_ids_vigentes) > 0) {
            $query_huerfanos->whereNotIn('provider_order_extra_cost_id', $extra_cost_ids_vigentes);
        }

        foreach ($query_huerfanos->get() as $huerfano) {
            ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $huerfano->id)->delete();
            $huerfano->delete();
        }
    }

    /**
     * Prompt 610: redondea cada fila de un desglose de IVA (`[iva_id => ['neto' => x,
     * 'importe_iva' => y]]`) a 2 decimales — mismo tipo de columna que
     * `provider_order_afip_ticket_ivas.neto`/`iva_importe` (`decimal(20,2)`) — y absorbe en la
     * alícuota de MAYOR importe (neto + IVA) el centavo de diferencia que puede quedar por
     * redondear cada fila de forma independiente, para que la suma de netos + la suma de IVA por
     * fila dé EXACTAMENTE el mismo total que redondear la suma cruda una sola vez.
     *
     * Sin esto, sumar filas ya redondeadas individualmente por MySQL (`decimal(20,2)`) puede
     * quedar un centavo por debajo o por arriba del total del comprobante (que se calcula sobre
     * los valores crudos, sin redondear por fila) — más notorio con `precios_incluyen_iva = true`,
     * donde el neto sale de descomponer el bruto línea por línea con su propia alícuota.
     *
     * @param  array $ivas [iva_id => ['neto' => x, 'importe_iva' => y]] (valores crudos, sin redondear)
     * @return array        mismo shape, valores ya redondeados a 2 decimales y ajustados para cerrar sin diferencia
     */
    private static function redondear_ivas_sin_diferencia(array $ivas): array
    {
        if (empty($ivas)) {
            return $ivas;
        }

        // Totales "crudos" (sin redondear por fila): el objetivo a igualar después de redondear
        // cada alícuota por separado.
        $total_neto_objetivo = 0;
        $total_iva_objetivo  = 0;

        foreach ($ivas as $value) {
            $total_neto_objetivo += $value['neto'];
            $total_iva_objetivo  += $value['importe_iva'];
        }

        $total_neto_objetivo = round($total_neto_objetivo, 2);
        $total_iva_objetivo  = round($total_iva_objetivo, 2);

        // Redondea cada fila a 2 decimales de forma independiente.
        $redondeados = [];
        foreach ($ivas as $iva_id => $value) {
            $redondeados[$iva_id] = [
                'neto'        => round($value['neto'], 2),
                'importe_iva' => round($value['importe_iva'], 2),
            ];
        }

        $suma_neto = 0;
        $suma_iva  = 0;
        foreach ($redondeados as $value) {
            $suma_neto += $value['neto'];
            $suma_iva  += $value['importe_iva'];
        }

        $diff_neto = round($total_neto_objetivo - $suma_neto, 2);
        $diff_iva  = round($total_iva_objetivo - $suma_iva, 2);

        if ($diff_neto != 0 || $diff_iva != 0) {

            // Se busca la alícuota de mayor importe (neto + IVA) del comprobante, para que el
            // ajuste de centavos quede lo menos visible posible.
            $iva_id_mayor  = null;
            $importe_mayor = -1;

            foreach ($redondeados as $iva_id => $value) {

                $importe = $value['neto'] + $value['importe_iva'];

                if ($importe > $importe_mayor) {
                    $importe_mayor = $importe;
                    $iva_id_mayor  = $iva_id;
                }
            }

            if (!is_null($iva_id_mayor)) {
                $redondeados[$iva_id_mayor]['neto']        = round($redondeados[$iva_id_mayor]['neto'] + $diff_neto, 2);
                $redondeados[$iva_id_mayor]['importe_iva'] = round($redondeados[$iva_id_mayor]['importe_iva'] + $diff_iva, 2);
            }
        }

        return $redondeados;
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