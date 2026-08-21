<?php

namespace App\Http\Controllers\Helpers\AfipHelper;

use App\Http\Controllers\Helpers\AfipHelper;
use Illuminate\Support\Facades\Log;

class AfipItemCalculator
{
    /** @var AfipHelper $afip_helper Contexto principal de cálculo AFIP. */
    public $afip_helper;

    /**
     * @var float|null $porcentaje_descuento_puntos Canje de puntos ya convertido a porcentaje
     * sobre el bruto facturable de la venta. `null` = todavía no se calculó. Se cachea porque el
     * cálculo recorre TODOS los renglones de la venta y este método se llama una vez por ítem.
     */
    private $porcentaje_descuento_puntos = null;

    /**
     * @var bool $calculando_bruto_de_la_venta Guard de reentrada. El bruto se arma llamando a
     * `get_article_price_with_discounts()` renglón por renglón, y ese método vuelve a pedir el
     * porcentaje del canje: sin este flag sería recursión infinita. Mientras está prendido, el
     * canje vale 0 — que es justamente lo que hace falta, porque el bruto es el total ANTES del
     * canje.
     */
    private $calculando_bruto_de_la_venta = false;

    /**
     * Inicializa el calculador de ítems con el helper principal.
     *
     * @param AfipHelper $afip_helper Instancia que contiene venta, ticket e ítem actual.
     */
    public function __construct(AfipHelper $afip_helper)
    {
        $this->afip_helper = $afip_helper;
    }

    /**
     * Calcula base imponible e IVA para combo/servicio con alícuota 21%.
     *
     * @param object $combo Ítem con pivot->amount.
     * @return array
     */
    public function get_combo_iva($combo)
    {
        /** @var float $price Precio unitario del ítem con descuentos/recargos aplicados. */
        $price = $this->get_article_price_with_discounts();
        /** @var float $total_combo Total bruto del ítem por cantidad. */
        $total_combo = $price * $combo->pivot->amount;
        /** @var float $iva Alícuota aplicada para este flujo. */
        $iva = 21;
        /** @var float $precio_sin_iva Base imponible resultante. */
        $precio_sin_iva = $total_combo / (($iva / 100) + 1);
        /** @var float $monto_iva Importe de IVA resultante. */
        $monto_iva = $total_combo - $precio_sin_iva;

        return [
            'Importe' => round($monto_iva, 2),
            'BaseImp' => round($precio_sin_iva, 2),
        ];
    }

    /**
     * Calcula base imponible e IVA para una descripción con alícuota dinámica.
     *
     * @param object $description Ítem descripción.
     * @param float|int|string $iva Alícuota usada para descomponer el precio.
     * @return array
     */
    public function get_description_iva($description, $iva)
    {
        /** @var float $total Total bruto de la descripción. */
        $total = $description->price;
        /** @var float $precio_sin_iva Base imponible resultante. */
        $precio_sin_iva = $total / (($iva / 100) + 1);
        /** @var float $monto_iva Importe de IVA resultante. */
        $monto_iva = $total - $precio_sin_iva;

        return [
            'Importe' => round($monto_iva, 2),
            'BaseImp' => round($precio_sin_iva, 2),
        ];
    }

    /**
     * Calcula IVA de ítem actual (total o por alícuota puntual).
     *
     * @param float|int|string|null $iva Alícuota a filtrar; null devuelve IVA total del ítem.
     * @return float|array
     */
    public function get_importe_iva($iva = null)
    {
        if (is_null($iva)) {
            /** @var float $monto_iva IVA total por cantidad del ítem actual. */
            $monto_iva = $this->monto_iva_del_precio() * $this->get_article_amount();

            // if (
            //     $this->afip_helper->sale->moneda_id == 2
            //     && !is_null($this->afip_helper->sale->valor_dolar)
            // ) {
            //     $monto_iva *= (float) $this->afip_helper->sale->valor_dolar;
            // }

            return $monto_iva;
        }

        /** @var float $importe Importe IVA por alícuota filtrada. */
        $importe = 0;
        /** @var float $base_imp Base imponible por alícuota filtrada. */
        $base_imp = 0;

        /**
         * Usa la alícuota resuelta (pivot histórico o relación actual) para comparar.
         * Esto garantiza que si el artículo cambió de IVA después de la venta,
         * la nota de crédito usa la alícuota original.
         */
        if ((string) $this->resolve_article_iva_percentage() == (string) $iva) {
            $importe = $this->monto_iva_del_precio() * $this->get_article_amount();
            $base_imp = $this->get_price_without_iva() * $this->get_article_amount();

            // if (
            //     $this->afip_helper->sale->moneda_id == 2
            //     && !is_null($this->afip_helper->sale->valor_dolar)
            // ) {
            //     $importe *= (float) $this->afip_helper->sale->valor_dolar;
            //     $base_imp *= (float) $this->afip_helper->sale->valor_dolar;
            // }
        }

        return ['Importe' => round($importe, 2), 'BaseImp' => round($base_imp, 2)];
    }

    /**
     * Resuelve la alícuota de IVA efectiva del ítem actual.
     * Prioriza el valor persistido en el pivot al momento de la venta (iva_percentage),
     * de modo que un cambio posterior de IVA en el artículo no afecte cálculos de NC.
     * Fallback: relación iva del artículo, o 21 si no hay ninguno.
     *
     * @return float|int|string Porcentaje de IVA resuelto.
     */
    private function resolve_article_iva_percentage()
    {
        /** @var object|null $pivot Pivot del artículo en la relación actual (venta o NC). */
        $pivot = isset($this->afip_helper->article->pivot) ? $this->afip_helper->article->pivot : null;

        if ($pivot && isset($pivot->iva_percentage) && !is_null($pivot->iva_percentage)) {
            // El pivot devuelve texto a proposito: puede ser '21.00' o 'Exento'. Los consumidores de este
            // metodo (get_price_without_iva, monto_iva_del_precio) tienen que seguir tratandolo como texto.
            // No castear a float aca: perderia la distincion entre Exento, No Gravado y 0%.
            return $pivot->iva_percentage;
        }

        if (!is_null($this->afip_helper->article->iva)) {
            return $this->afip_helper->article->iva->percentage;
        }

        return 21;
    }

    /**
     * Retorna precio sin IVA del ítem actual.
     *
     * @param bool $with_discount Si es true, parte de precio con descuentos/recargos.
     * @return float
     */
    public function get_price_without_iva($with_discount = true)
    {
        /** @var float $price Precio base sobre el que se descompone IVA.
         * get_article_price_raw ya me lo da cotizado
         *  */
        $price = $with_discount ? $this->get_article_price_with_discounts() : $this->get_article_price_raw();

        /** @var float|int|string $article_iva Alícuota resuelta (pivot histórico o modelo actual). */
        $article_iva = $this->resolve_article_iva_percentage();

        if (
            $article_iva !== 'No Gravado'
            && $article_iva !== 'Exento'
            && (float) $article_iva != 0
        ) {
            return $price / (((float) $article_iva / 100) + 1);
        }

        return $price;
    }

    /**
     * Retorna precio del ítem aplicando descuentos y recargos de venta.
     *
     * @return float
     */
    public function get_article_price_with_discounts()
    {
        /** @var float $price Precio base del ítem actual. */
        $price = $this->get_article_price_raw();

        if (
            !$this->afip_helper->article->is_description
            && !is_null($this->afip_helper->article->pivot->discount)
        ) {
            Log::info('restando descuento de articulo del ' . $this->afip_helper->article->pivot->discount . ' a ' . $price);
            $price -= $price * $this->afip_helper->article->pivot->discount / 100;
            Log::info('quedo en ' . $price);
        }

        // Log::info('nota_credito_model:');
        // Log::info((array)$this->afip_helper->nota_credito_model);

        $discounts = [];
        if ($this->afip_helper->nota_credito_model) {
            // Log::info('discounts de nota_credito_model:');
            // Log::info($this->afip_helper->nota_credito_model->discounts);
            $discounts = $this->afip_helper->nota_credito_model->discounts;
        } else {
            $discounts = $this->afip_helper->sale->discounts;
        }

        $surchages = [];
        if ($this->afip_helper->nota_credito_model) {
            $surchages = $this->afip_helper->nota_credito_model->surchages;
        } else {
            $surchages = $this->afip_helper->sale->surchages;
        }

        // if (!$this->afip_helper->from_nota_credito) {
            foreach ($discounts as $discount) {
                if (
                    $this->afip_helper->article->is_article
                    || (
                        $this->afip_helper->article->is_service
                        && $this->afip_helper->sale->discounts_in_services
                    )
                ) {
                    Log::info('restando descuento de venta de ' . $discount->pivot->percentage . ' a ' . $price);
                    $price -= $price * $discount->pivot->percentage / 100;
                    Log::info('quedo en ' . $price);
                }
            }

            if (!$this->afip_helper->sale->aplicar_recargos_directo_a_items) {
                foreach ($surchages as $surchage) {
                    if (
                        $this->afip_helper->article->is_article
                        || (
                            $this->afip_helper->article->is_service
                            && $this->afip_helper->sale->surchages_in_services
                        )
                    ) {
                        Log::info('aumentando recargo de venta de ' . $surchage->pivot->percentage . ' a ' . $price);
                        $price += $price * $surchage->pivot->percentage / 100;
                        Log::info('quedo en ' . $price);
                    }
                }
            }

            if ($this->afip_helper->sale->descuento > 0) {
                $price -= $price * $this->afip_helper->sale->descuento / 100;
            }

            /**
             * El canje por puntos (`sales.descuento_puntos`) entra ACÁ, último y sobre el precio
             * que ya tiene todos los descuentos y recargos de venta encima.
             *
             * 🔴 POR QUÉ JUSTO EN ESTE PUNTO Y NO ANTES. Es el mismo orden que arma el front en
             * `empresa-spa/src/mixins/vender_set_total.js::aplicar_canje_de_puntos()`:
             *
             *     bruto = (artículos + servicios + combos + promos) ya con descuentos y recargos
             *     total = bruto - descuento_puntos
             *
             * Si el canje se aplicara antes de los descuentos de venta, el porcentaje de esos
             * descuentos caería también sobre el canje y el importe facturado dejaría de bajar
             * exactamente lo que se le descontó al cliente.
             */
            $porcentaje_puntos = $this->get_porcentaje_descuento_puntos();

            if ($porcentaje_puntos > 0) {
                $price -= $price * $porcentaje_puntos / 100;
            }
        // }

        return $price;
    }

    /**
     * Convierte el canje por puntos —que está en PESOS— al porcentaje equivalente sobre el bruto
     * facturable de la venta.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 POR QUÉ UN PORCENTAJE Y NO UNA RESTA AL FINAL.
     *
     *  `sales.descuento` ya es un porcentaje y se aplica renglón por renglón (ver arriba, en
     *  `get_article_price_with_discounts()`). Al hacer lo mismo con el canje:
     *
     *   1. El descuento se PRORRATEA solo entre las alícuotas, en proporción a lo que cada
     *      renglón aporta al total. El desglose que ARCA valida (neto gravado por alícuota,
     *      IVA por alícuota, exento, no gravado) sigue sumando el total porque cada bucket se
     *      achica en el mismo porcentaje. Restar los pesos al final del cálculo, en cambio,
     *      obligaría a decidir a mano de qué alícuota salen, y ahí es donde el desglose deja de
     *      cerrar y ARCA rechaza el comprobante.
     *
     *   2. Una nota de crédito PARCIAL queda bien sin escribir una línea extra: se factura el
     *      subconjunto de renglones devueltos y cada uno viene con su porcentaje de canje ya
     *      restado. Con una resta en pesos, una NC por la mitad de la mercadería devolvería el
     *      canje ENTERO.
     *
     *   3. Todo lo que ya consume este calculador —`get_importe_gravado()`, `get_importe_iva()`,
     *      `sub_total()`, `get_article_price()`, y por lo tanto los PDF y tickets— queda
     *      consistente sin tocarse, igual que con `sales.descuento`.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * Alcance: el canje cae sobre los MISMOS renglones que `sales.descuento` (artículos, combos,
     * servicios y promociones) y NO sobre las descripciones, que no pasan por este método.
     * Como el bruto se calcula sobre ese mismo conjunto, la resta en pesos da EXACTA:
     * `porcentaje * bruto == descuento_puntos`.
     *
     * @return float Porcentaje a restar (0 si la venta no canjeó puntos).
     */
    public function get_porcentaje_descuento_puntos()
    {
        if ($this->calculando_bruto_de_la_venta) {
            return 0.0;
        }

        if (!is_null($this->porcentaje_descuento_puntos)) {
            return $this->porcentaje_descuento_puntos;
        }

        // Se cachea el 0 primero: casi ninguna venta canjea puntos y ese es el camino barato.
        $this->porcentaje_descuento_puntos = 0.0;

        /** @var \App\Models\Sale|null $sale Venta sobre la que se está facturando. */
        $sale = $this->afip_helper->sale;

        if (is_null($sale)) {
            return 0.0;
        }

        /** @var float $descuento Pesos que canjeó el cliente, tal como los recalculó el servidor. */
        $descuento = isset($sale->descuento_puntos) ? (float) $sale->descuento_puntos : 0.0;

        if ($descuento <= 0) {
            return 0.0;
        }

        /**
         * El canje viaja en la MONEDA DE LA VENTA (el front hace `total -= descuento_puntos`
         * sobre el total en esa moneda), mientras que el bruto que se arma abajo ya viene
         * cotizado a pesos por `get_article_price_raw()`. Sin cotizar el canje, el cociente
         * mezclaría dólares con pesos y el porcentaje saldría ridículamente chico.
         */
        if ($sale->moneda_id == 2 && $sale->valor_dolar) {
            $descuento *= (float) $sale->valor_dolar;
        }

        /** @var float $bruto Total facturable de la venta ANTES del canje, en pesos. */
        $bruto = $this->get_bruto_facturable_de_la_venta();

        if ($bruto <= 0) {
            Log::warning(
                'AfipItemCalculator: la venta '.$sale->id.' tiene descuento_puntos ('.$descuento.
                ') pero su bruto facturable es '.$bruto.'. No se puede prorratear el canje: se factura sin descontarlo.'
            );

            return 0.0;
        }

        /** @var float $porcentaje Equivalente porcentual del canje sobre el bruto. */
        $porcentaje = $descuento / $bruto * 100;

        /**
         * Piso en cero del comprobante. El tope del programa de puntos (20 % por defecto) hace
         * que no se llegue nunca, pero un comercio puede configurarlo en 100 y, con descuentos de
         * venta encima, el canje puede superar al bruto. Un comprobante con importes negativos
         * lo rechaza ARCA; con total 0, `AfipWsfeHelper::solicitar_cae()` ya corta antes de
         * pedir el CAE.
         */
        if ($porcentaje > 100) {
            Log::warning(
                'AfipItemCalculator: en la venta '.$sale->id.' el canje por puntos ('.$descuento.
                ') supera al bruto facturable ('.$bruto.'). Se factura en 0 en vez de mandar importes negativos.'
            );

            $porcentaje = 100.0;
        }

        $this->porcentaje_descuento_puntos = $porcentaje;

        return $porcentaje;
    }

    /**
     * Suma el total facturable de la venta ENTERA antes del canje, en pesos.
     *
     * 🔴 SIEMPRE la venta entera, nunca `$afip_helper->articles`. En una nota de crédito parcial,
     * `$afip_helper->articles` son solo los renglones devueltos: sacar el porcentaje de ahí
     * devolvería el canje completo por una devolución parcial.
     *
     * 🔴 EL RECORRIDO ES EL MISMO QUE `AfipImportesCalculator::calculate_from_sale_items()`, EN EL
     * MISMO ORDEN (artículos, combos, servicios, promociones), y a propósito NO se le setea
     * `$afip_helper->article` a los combos ni a las promociones: allá tampoco se les setea, así
     * que su renglón se liquida con el precio del ÚLTIMO ítem del bloque anterior. Es una rareza
     * preexistente y está fuera del alcance de este arreglo — pero replicarla acá es lo que
     * garantiza que `porcentaje * bruto` sea exactamente los pesos que se descuentan del total
     * que sale hacia ARCA. Si algún día se corrige allá, hay que corregirla acá en el mismo
     * commit.
     *
     * Las descripciones quedan afuera porque `get_description_iva()` no pasa por
     * `get_article_price_with_discounts()`: tampoco reciben `sales.descuento`.
     *
     * @return float
     */
    private function get_bruto_facturable_de_la_venta()
    {
        /** @var \App\Models\Sale $sale Venta completa. */
        $sale = $this->afip_helper->sale;

        /** @var object|null $article_original Ítem que estaba en curso; se restaura antes de salir. */
        $article_original = $this->afip_helper->article;

        $this->calculando_bruto_de_la_venta = true;

        /** @var float $bruto Acumulador del total facturable sin canje. */
        $bruto = 0;

        foreach ($sale->articles as $item) {
            $item->is_article = true;
            $this->afip_helper->article = $item;
            $bruto += $this->get_article_price_with_discounts() * $this->get_article_amount();
        }

        foreach ($sale->combos as $combo) {
            // Sin reasignar `article`: ver la nota de arriba. El guard es para no convertir en un
            // fatal un flujo que hoy no lo es: una venta de solo combos ya revienta en
            // `calculate_from_sale_items()` por este mismo motivo, y no es este el lugar donde
            // tiene que aparecer el error.
            if (is_null($this->afip_helper->article)) {
                continue;
            }

            $bruto += $this->get_article_price_with_discounts() * $combo->pivot->amount;
        }

        foreach ($sale->services as $item) {
            $item->is_service = true;
            $this->afip_helper->article = $item;
            $bruto += $this->get_article_price_with_discounts() * $this->get_article_amount();
        }

        foreach ($sale->promocion_vinotecas as $promo) {
            // Sin reasignar `article`: ver la nota de arriba (y el mismo guard que en combos).
            if (is_null($this->afip_helper->article)) {
                continue;
            }

            $bruto += $this->get_article_price_with_discounts() * $promo->pivot->amount;
        }

        $this->calculando_bruto_de_la_venta = false;
        $this->afip_helper->article = $article_original;

        return $bruto;
    }

    /**
     * Retorna precio unitario para impresión/cálculo externo, según tipo de comprobante.
     *
     * @param mixed $sale Se conserva por compatibilidad de firma.
     * @param object $article Ítem a evaluar.
     * @param bool $precio_neto_sin_iva Se conserva por compatibilidad de firma.
     * @return float
     */
    public function get_article_price($sale, $article, $precio_neto_sin_iva = false)
    {
        $this->afip_helper->article = $article;
        /** @var float $price Precio bruto actual del ítem. */
        $price = $this->get_article_price_raw();

        if (
            !$this->exportacion()
            && !$this->monotributo()
        ) {
            if (
                !is_null($article->iva)
                && $article->iva->percentage != 'No Gravado'
                && $article->iva->percentage != 'Exento'
                && $article->iva->percentage != 0
            ) {
                return $this->get_price_without_iva();
            }
        }

        return $price;
    }

    /**
     * Retorna precio bruto del ítem actual, contemplando moneda.
     *
     * @return float|int
     */
    public function get_article_price_raw()
    {
        /** @var float|int $price Precio base del item según tipo de entidad. */
        $price = $this->afip_helper->article->is_description
            ? $this->afip_helper->article->price
            : $this->afip_helper->article->pivot->price;

        if (
            $this->afip_helper->sale->moneda_id == 2
            && $this->afip_helper->sale->valor_dolar
        ) {
            $price *= $this->afip_helper->sale->valor_dolar;
        }

        return $price;
    }

    /**
     * Retorna cantidad del ítem actual.
     *
     * @return float|int
     */
    public function get_article_amount()
    {
        /** @var float|int $amount Cantidad, o 1 para descripciones. */
        $amount = $this->afip_helper->article->is_description
            ? 1
            : $this->afip_helper->article->pivot->amount;

        return $amount;
    }

    /**
     * Retorna el monto de IVA correspondiente al precio del ítem actual.
     * Usa resolve_article_iva_percentage para respetar el IVA histórico del pivot.
     *
     * @return float|int
     */
    public function monto_iva_del_precio()
    {
        /** @var float|int|string $iva Alícuota resuelta (pivot histórico o relación actual). */
        $iva = $this->resolve_article_iva_percentage();

        if (
            $iva !== 'No Gravado'
            && $iva !== 'Exento'
            && (float) $iva != 0
        ) {
            return $this->get_price_without_iva() * (float) $iva / 100;
        }

        return 0;
    }

    /**
     * Retorna importe gravado del ítem actual.
     *
     * @return float|int
     */
    public function get_importe_gravado()
    {
        if (
            is_null($this->afip_helper->article->iva)
            || (
                !is_null($this->afip_helper->article->iva)
                && $this->afip_helper->article->iva->percentage != 'No Gravado'
                && $this->afip_helper->article->iva->percentage != 'Exento'
            )
        ) {
            /** @var float $gravado Base imponible total para cantidad actual. */
            $gravado = $this->get_price_without_iva() * $this->get_article_amount();

            // if (
            //     $this->afip_helper->sale->moneda_id == 2
            //     && !is_null($this->afip_helper->sale->valor_dolar)
            // ) {
            //     Log::info('Venta en dolares, multiplicando ' . $gravado . ' * ' . $this->afip_helper->sale->valor_dolar);
            //     $gravado *= (float) $this->afip_helper->sale->valor_dolar;
            //     Log::info('QUedo en ' . $gravado);
            // }

            return $gravado;
        }

        return 0;
    }

    /**
     * Retorna subtotal del ítem actual según tipo de comprobante.
     *
     * @param object $article Ítem a subtotalizar.
     * @return float|int
     */
    public function sub_total($article)
    {
        $this->afip_helper->article = $article;
        if (
            !$this->exportacion()
            && !$this->monotributo()
        ) {
            return $this->get_price_without_iva() * $this->get_article_amount();
        }

        return $this->get_article_price_raw() * $this->get_article_amount();
    }

    /**
     * Informa si el comprobante del ticket corresponde a exportación.
     *
     * @return bool
     */
    public function exportacion()
    {
        return $this->afip_helper->afip_ticket->cbte_tipo == 19 || $this->afip_helper->afip_ticket->cbte_tipo == 21;
    }

    /**
     * Informa si el comprobante del ticket corresponde a monotributo.
     *
     * @return bool
     */
    public function monotributo()
    {
        return $this->afip_helper->afip_ticket->cbte_tipo == 11 || $this->afip_helper->afip_ticket->cbte_tipo == 12 || $this->afip_helper->afip_ticket->cbte_tipo == 13;
    }
}
