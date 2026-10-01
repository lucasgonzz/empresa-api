<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\Puntos\PuntosComprobanteHelper;

/**
 * La plata del pie de una venta dibujada con un diseño de página (misión diseno-pdf-configurable,
 * 1/10/2026): Sub Total, descuentos y recargos en cascada, canje de puntos, ajuste del total
 * forzado, Total, puntos, comisiones y costos.
 *
 * 🔴 ES UNA COPIA FIEL, SIN FPDF, DE LA CUENTA DE `NewSalePdf` (el remito y la factura de siempre),
 * y a propósito NO una cuenta nueva: el cliente del comercio tiene que leer los MISMOS números con
 * el diseño de siempre y con el diseño de cajas. Cada método nombra su gemelo en `NewSalePdf`; si
 * alguien cambia la cuenta allá, este archivo tiene que acompañar en el mismo cambio. No se llama a
 * `NewSalePdf` directamente porque su constructor dibuja y hace `exit`, y porque su cuenta vive
 * mezclada con el dibujo (acumuladores que se arman mientras se imprimen los renglones).
 *
 * Diferencia de forma, no de números: `NewSalePdf` arma los acumuladores renglón por renglón
 * mientras dibuja la tabla y, para el pie "en cada hoja", los fija a los totales FINALES con
 * `render_totals_box_snapshot()`. Acá no hay tabla: los acumuladores arrancan directamente en los
 * totales finales (`get_final_bucket_totals()`), que es lo que ve la última hoja del remito.
 *
 * Todo se calcula una sola vez y queda memoizado: el pie de un diseño con "pie en cada hoja" pide
 * los mismos renglones en cada hoja, y detrás de los puntos hay consultas.
 */
class TotalesDeVentaPdf
{
    /** @var \App\Models\Sale */
    private $sale;

    /** @var \App\Models\User|null Dueño de la venta (para los puntos, como en `NewSalePdf::$user`). */
    private $user;

    /** @var string 'descriptivo' (monto + porcentaje + total parcial) o 'simple' (solo "% Nombre"). */
    private $discount_display_mode;

    /** @var float Acumulador de artículos (gemelo de `NewSalePdf::$total_articles`). */
    private $total_articles;

    /** @var float Acumulador de combos (gemelo de `NewSalePdf::$total_combos`). */
    private $total_combos;

    /** @var float Acumulador de promociones (gemelo de `NewSalePdf::$total_promocion_vinotecas`). */
    private $total_promocion_vinotecas;

    /** @var float Acumulador de servicios (gemelo de `NewSalePdf::$total_services`). */
    private $total_services;

    /** @var float Total bruto corriente (gemelo de `NewSalePdf::$total_bruto`). */
    private $total_bruto;

    /** @var array<string, float>|null Totales finales por tipo de renglón (memo). */
    private $buckets_finales;

    /** @var float|null Suma de los cuatro buckets antes de descuentos y recargos (memo). */
    private $total_bruto_final;

    /** @var array<int, string>|null Renglones de descuentos (memo, se arman junto con los recargos). */
    private $renglones_de_descuentos;

    /** @var array<int, string>|null Renglones de recargos (memo, se arman después de los descuentos). */
    private $renglones_de_recargos;

    /** @var array<int, string>|null Renglones informativos de puntos (memo: hay consultas detrás). */
    private $renglones_de_puntos;

    /**
     * @param \App\Models\Sale       $sale
     * @param \App\Models\User|null  $user                  Dueño de la venta.
     * @param string                 $discount_display_mode 'descriptivo' | 'simple' (el del perfil).
     */
    public function __construct($sale, $user, $discount_display_mode)
    {
        $this->sale = $sale;
        $this->user = $user;
        $this->discount_display_mode = $discount_display_mode === 'simple' ? 'simple' : 'descriptivo';

        /**
         * Todos los acumuladores se inicializan ACÁ, juntos (clase de error "propiedad dinámica
         * leída antes de su primera asignación", APRENDER_NO_PARCHEAR.md: rompió el remito en
         * producción el 4/8/2026 con `total_bruto`).
         */
        $this->total_articles = 0;
        $this->total_combos = 0;
        $this->total_promocion_vinotecas = 0;
        $this->total_services = 0;
        $this->total_bruto = 0;
        $this->buckets_finales = null;
        $this->total_bruto_final = null;
        $this->renglones_de_descuentos = null;
        $this->renglones_de_recargos = null;
        $this->renglones_de_puntos = null;
    }

    /**
     * Renglones de la venta en el orden de la tabla: artículos, combos, promociones y servicios.
     * Gemelo de `NewSalePdf::get_sale_items()`.
     *
     * 🔴 Les pone a los renglones las MISMAS marcas que `get_sale_items()` (is_article, is_combo,
     * is_promocion_vinotecas, is_service), y no es decorativo: `AfipItemCalculator` aplica los
     * descuentos y recargos de la venta en las columnas fiscales (precio sin IVA, importe de IVA,
     * precio con IVA) solo a los renglones marcados como artículo o servicio. Sin las marcas, la
     * factura con cajas imprimía esas columnas sin los descuentos ni los recargos de la venta.
     *
     * @return array<int, array{0: string, 1: object}> [tipo, renglón]
     */
    public function renglones()
    {
        $renglones = [];

        foreach ($this->sale->articles as $item) {
            $item->is_article = true;
            $renglones[] = ['articles', $item];
        }
        foreach ($this->sale->combos as $item) {
            $item->is_combo = true;
            $renglones[] = ['combos', $item];
        }
        foreach ($this->sale->promocion_vinotecas as $item) {
            $item->is_promocion_vinotecas = true;
            $renglones[] = ['promocion_vinotecas', $item];
        }
        foreach ($this->sale->services as $item) {
            $item->is_service = true;
            $renglones[] = ['services', $item];
        }

        return $renglones;
    }

    /**
     * Importe de un renglón: precio por cantidad menos la bonificación del renglón.
     * Gemelo de `NewSalePdf::sub_total()`.
     *
     * @param object $item
     * @return float
     */
    public function subtotal_del_renglon($item)
    {
        $amount = $item->pivot->amount;

        $total = $item->pivot->price * $amount;
        if (!is_null($item->pivot->discount)) {
            $total -= $total * ($item->pivot->discount / 100);
        }

        return $total;
    }

    /**
     * Totales finales por tipo de renglón. Gemelo de `NewSalePdf::get_final_bucket_totals()`.
     *
     * @return array<string, float> ['articles', 'combos', 'promocion_vinotecas', 'services']
     */
    public function buckets_finales()
    {
        if (is_null($this->buckets_finales)) {

            $buckets = [
                'articles' => 0,
                'combos' => 0,
                'promocion_vinotecas' => 0,
                'services' => 0,
            ];

            foreach ($this->renglones() as $renglon) {
                $buckets[$renglon[0]] += $this->subtotal_del_renglon($renglon[1]);
            }

            $this->buckets_finales = $buckets;
        }

        return $this->buckets_finales;
    }

    /**
     * Suma de los cuatro buckets ANTES de descuentos y recargos: el "Sub Total" del remito.
     * Gemelo de la primera línea de `NewSalePdf::print_totals_box()`
     * (`$this->total_bruto = total_articles + total_combos + total_promocion_vinotecas + total_services`).
     *
     * @return float
     */
    public function total_bruto()
    {
        if (is_null($this->total_bruto_final)) {
            $buckets = $this->buckets_finales();
            $this->total_bruto_final = $buckets['articles'] + $buckets['combos'] + $buckets['promocion_vinotecas'] + $buckets['services'];
        }

        return $this->total_bruto_final;
    }

    /**
     * ¿Hay algo entre el Sub Total y el Total? Es la condición con la que el remito imprime el Sub
     * Total, los descuentos, los recargos, el canje y el ajuste. Gemelo de
     * `$has_discounts_or_surchages` en `NewSalePdf::print_totals_box()`.
     *
     * Se compara con `!=` igual que allá (un float contra el decimal de la base): cambiar la
     * comparación cambiaría qué remitos imprimen Sub Total.
     *
     * @return bool
     */
    public function hay_diferencia()
    {
        return $this->total_bruto() != $this->sale->total
            || PuntosComprobanteHelper::tiene_canje($this->sale)
            || !is_null($this->renglon_ajuste_del_total());
    }

    /**
     * Valor del "Sub Total" (sin el rótulo), o null si el remito de siempre no lo imprimiría.
     * Gemelo del renglón `'Sub Total: '.Numbers::price($this->total_bruto, true, moneda_id)` de
     * `NewSalePdf::print_totals_box()`.
     *
     * @return string|null
     */
    public function texto_sub_total()
    {
        if (!$this->hay_diferencia()) {
            return null;
        }

        return Numbers::price($this->total_bruto(), true, $this->sale->moneda_id);
    }

    /**
     * Valor del "Total" (sin el rótulo): siempre `sales.total`, la fuente de verdad, nunca un
     * acumulado. Gemelo del último renglón de `NewSalePdf::print_totals_box()`.
     *
     * @return string
     */
    public function texto_total()
    {
        return Numbers::price($this->sale->total, true, $this->sale->moneda_id);
    }

    /**
     * Renglones de descuentos, tal cual los imprime el remito. Vacío si no hay o si el remito no
     * los imprimiría (sin diferencia entre Sub Total y Total).
     * Gemelo de `NewSalePdf::build_discount_rows()`.
     *
     * @return array<int, string>
     */
    public function renglones_de_descuentos()
    {
        $this->calcular_cascada();

        return $this->renglones_de_descuentos;
    }

    /**
     * Renglones de recargos, calculados DESPUÉS de los descuentos (en cascada, como el remito).
     * Gemelo de `NewSalePdf::build_surchage_rows()`.
     *
     * @return array<int, string>
     */
    public function renglones_de_recargos()
    {
        $this->calcular_cascada();

        return $this->renglones_de_recargos;
    }

    /**
     * Arma, UNA sola vez y en el orden del remito, los renglones de descuentos y después los de
     * recargos: los recargos se aplican sobre los buckets ya descontados, igual que en
     * `NewSalePdf::print_totals_box()` (que llama a `build_discount_rows()` y después a
     * `build_surchage_rows()` sobre los mismos acumuladores).
     *
     * @return void
     */
    private function calcular_cascada()
    {
        if (!is_null($this->renglones_de_descuentos)) {
            return;
        }

        $this->renglones_de_descuentos = [];
        $this->renglones_de_recargos = [];

        /**
         * El remito solo arma estos renglones adentro de `if ($has_discounts_or_surchages)`.
         */
        if (!$this->hay_diferencia()) {
            return;
        }

        /**
         * Acumuladores en los totales finales, como los deja `render_totals_box_snapshot()` y
         * `print_totals_box()` en la última hoja.
         */
        $buckets = $this->buckets_finales();
        $this->total_articles = $buckets['articles'];
        $this->total_combos = $buckets['combos'];
        $this->total_promocion_vinotecas = $buckets['promocion_vinotecas'];
        $this->total_services = $buckets['services'];
        $this->total_bruto = $this->total_bruto();

        $this->renglones_de_descuentos = $this->armar_renglones_de_descuentos();
        $this->renglones_de_recargos = $this->armar_renglones_de_recargos();
    }

    /**
     * Gemelo de `NewSalePdf::build_discount_rows()`: muta los acumuladores igual que allá.
     *
     * @return array<int, string>
     */
    private function armar_renglones_de_descuentos()
    {
        $rows = [];

        if (count($this->sale->discounts) >= 1) {

            foreach ($this->sale->discounts as $discount) {

                $total_descuento = 0;

                $monto_descuento = $this->total_articles * floatval($discount->pivot->percentage) / 100;
                $this->total_articles -= $monto_descuento;
                $total_descuento += $monto_descuento;

                $monto_descuento = $this->total_combos * floatval($discount->pivot->percentage) / 100;
                $this->total_combos -= $monto_descuento;
                $total_descuento += $monto_descuento;

                $monto_descuento = $this->total_promocion_vinotecas * floatval($discount->pivot->percentage) / 100;
                $this->total_promocion_vinotecas -= $monto_descuento;
                $total_descuento += $monto_descuento;

                if ($this->sale->discounts_in_services) {

                    $monto_descuento = $this->total_services * floatval($discount->pivot->percentage) / 100;
                    $this->total_services -= $monto_descuento;
                    $total_descuento += $monto_descuento;
                }

                $this->total_bruto -= $total_descuento;

                if ($this->discount_display_mode === 'simple') {
                    $rows[] = $discount->pivot->percentage.'% '.$discount->name;
                } else {
                    $rows[] = 'Menos '.Numbers::price($total_descuento, true, $this->sale->moneda_id).' ('.$discount->pivot->percentage.'% '.$discount->name.') = '.Numbers::price($this->total_bruto, true);
                }
            }

            if (count($this->sale->services) > 0) {
                $rows[] = $this->sale->discounts_in_services
                    ? 'Se aplican descuentos a los servicios'
                    : 'No se aplican descuentos a los servicios';
            }
        }

        return $rows;
    }

    /**
     * Gemelo de `NewSalePdf::build_surchage_rows()`: con `aplicar_recargos_directo_a_items` el
     * recargo ya está en el precio de cada renglón y no se lista.
     *
     * @return array<int, string>
     */
    private function armar_renglones_de_recargos()
    {
        $rows = [];

        if (
            count($this->sale->surchages) >= 1
            && !$this->sale->aplicar_recargos_directo_a_items
        ) {

            foreach ($this->sale->surchages as $surchage) {

                $total_recargo = 0;

                $monto_recargo = $this->total_articles * floatval($surchage->pivot->percentage) / 100;
                $this->total_articles += $monto_recargo;
                $total_recargo += $monto_recargo;

                $monto_recargo = $this->total_combos * floatval($surchage->pivot->percentage) / 100;
                $this->total_combos += $monto_recargo;
                $total_recargo += $monto_recargo;

                $monto_recargo = $this->total_promocion_vinotecas * floatval($surchage->pivot->percentage) / 100;
                $this->total_promocion_vinotecas += $monto_recargo;
                $total_recargo += $monto_recargo;

                if ($this->sale->surchages_in_services) {

                    $monto_recargo = $this->total_services * floatval($surchage->pivot->percentage) / 100;
                    $this->total_services += $monto_recargo;
                    $total_recargo += $monto_recargo;
                }

                $this->total_bruto += $total_recargo;

                if ($this->discount_display_mode === 'simple') {
                    $rows[] = $surchage->pivot->percentage.'% '.$surchage->name;
                } else {
                    $rows[] = 'Mas '.Numbers::price($total_recargo, true, $this->sale->moneda_id).' ('.$surchage->pivot->percentage.'% '.$surchage->name.') = '.Numbers::price($this->total_bruto, true);
                }
            }

            if (count($this->sale->services) > 0) {
                $rows[] = $this->sale->surchages_in_services
                    ? 'Se aplican recargos a los servicios'
                    : 'No se aplican recargos a los servicios';
            }
        }

        return $rows;
    }

    /**
     * Renglón del canje de puntos, o null. Va adentro de `if ($has_discounts_or_surchages)` en
     * `NewSalePdf::print_totals_box()`, que igual siempre es verdadero cuando hay canje.
     *
     * @return string|null
     */
    public function renglon_canje()
    {
        if (!$this->hay_diferencia()) {
            return null;
        }

        return PuntosComprobanteHelper::renglon_descuento($this->sale);
    }

    /**
     * Renglón que explica el ajuste del total forzado, o null si la venta no se forzó.
     * Gemelo de `NewSalePdf::renglon_total_forzado()` (se imprime también en modo 'simple': el
     * ajuste ES un monto).
     *
     * @return string|null
     */
    public function renglon_ajuste_del_total()
    {
        /** @var float $monto Monto con signo. Negativo = descuento, positivo = recargo, 0 = no hubo. */
        $monto = SaleHelper::get_forzar_total_monto($this->sale);

        if ($monto == 0) {
            return null;
        }

        $palabra = $monto < 0 ? 'Menos ' : 'Mas ';

        return $palabra.Numbers::price(abs($monto), true, $this->sale->moneda_id).' (ajuste del total)';
    }

    /**
     * Renglones informativos de puntos (sumados y acumulados), memoizados.
     * Gemelo de `NewSalePdf::get_puntos_rows()`.
     *
     * @return array<int, string>
     */
    public function renglones_de_puntos()
    {
        if (is_null($this->renglones_de_puntos)) {
            $this->renglones_de_puntos = PuntosComprobanteHelper::renglones_puntos($this->sale, $this->user);
        }

        return $this->renglones_de_puntos;
    }

    /**
     * Comisiones de los vendedores, una por renglón ("Carla Gómez 5%: $625"). Los dos textos son
     * los de las dos celdas de la tabla de `NewSalePdf::print_commissions_block()` (nombre +
     * porcentaje, y '$'.Numbers::price(debe)), en un solo renglón.
     *
     * @return array<int, string>
     */
    public function renglones_de_comisiones()
    {
        $renglones = [];

        foreach ($this->sale->seller_commissions as $commission) {
            $nombre = $commission->seller ? $commission->seller->name : '';
            $renglones[] = trim($nombre.' '.$commission->percentage.'%').': $'.Numbers::price($commission->debe);
        }

        return $renglones;
    }

    /**
     * Valor del "Total menos comisiones" (sin el rótulo), o null si la venta no tiene comisiones.
     * Gemelo del último renglón de `NewSalePdf::print_commissions_block()`.
     *
     * @return string|null
     */
    public function texto_total_menos_comisiones()
    {
        if (count($this->sale->seller_commissions) < 1) {
            return null;
        }

        $total = (float) $this->sale->total;

        foreach ($this->sale->seller_commissions as $commission) {
            $total -= (float) $commission->debe;
        }

        return Numbers::price($total, true);
    }

    /**
     * Valor de "Costos" (sin el rótulo). Gemelo de `NewSalePdf::print_total_costs_block()`.
     *
     * @return string
     */
    public function texto_costos()
    {
        return '$'.Numbers::price(SaleHelper::getTotalCostSale($this->sale));
    }
}
