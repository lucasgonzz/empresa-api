<?php

namespace App\Http\Controllers\Helpers\AfipHelper;

use App\Http\Controllers\Helpers\AfipHelper;
use Illuminate\Support\Facades\Log;

class AfipItemCalculator
{
    /** @var AfipHelper $afip_helper Contexto principal de cálculo AFIP. */
    public $afip_helper;

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

            if (
                $this->afip_helper->sale->moneda_id == 2
                && !is_null($this->afip_helper->sale->valor_dolar)
            ) {
                $monto_iva *= (float) $this->afip_helper->sale->valor_dolar;
            }

            return $monto_iva;
        }

        /** @var float $importe Importe IVA por alícuota filtrada. */
        $importe = 0;
        /** @var float $base_imp Base imponible por alícuota filtrada. */
        $base_imp = 0;
        if (
            (
                is_null($this->afip_helper->article->iva)
                && $iva == 21
            )
            || (
                !is_null($this->afip_helper->article->iva)
                && $this->afip_helper->article->iva->percentage == $iva
            )
        ) {
            $importe = $this->monto_iva_del_precio() * $this->get_article_amount();
            $base_imp = $this->get_price_without_iva() * $this->get_article_amount();

            if (
                $this->afip_helper->sale->moneda_id == 2
                && !is_null($this->afip_helper->sale->valor_dolar)
            ) {
                $importe *= (float) $this->afip_helper->sale->valor_dolar;
                $base_imp *= (float) $this->afip_helper->sale->valor_dolar;
            }
        }

        return ['Importe' => round($importe, 2), 'BaseImp' => round($base_imp, 2)];
    }

    /**
     * Retorna precio sin IVA del ítem actual.
     *
     * @param bool $with_discount Si es true, parte de precio con descuentos/recargos.
     * @return float
     */
    public function get_price_without_iva($with_discount = true)
    {
        /** @var float $price Precio base sobre el que se descompone IVA. */
        $price = $with_discount ? $this->get_article_price_with_discounts() : $this->get_article_price_raw();

        if (
            is_null($this->afip_helper->article->iva)
            || (
                !is_null($this->afip_helper->article->iva
                    && $this->afip_helper->article->iva->percentage != 'No Gravado'
                    && $this->afip_helper->article->iva->percentage != 'Exento'
                    && $this->afip_helper->article->iva->percentage != 0)
            )
        ) {
            /** @var float|int|string $article_iva Alícuota del ítem actual o valor por defecto. */
            $article_iva = 21;
            if (!is_null($this->afip_helper->article->iva)) {
                $article_iva = $this->afip_helper->article->iva->percentage;
            }

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
        // }

        return $price;
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
     *
     * @return float|int
     */
    public function monto_iva_del_precio()
    {
        if (
            is_null($this->afip_helper->article->iva)
            || (
                !is_null($this->afip_helper->article->iva)
                && (
                    $this->afip_helper->article->iva->percentage != 'No Gravado'
                    || $this->afip_helper->article->iva->percentage != 'Exento'
                    || $this->afip_helper->article->iva->percentage != 0
                )
            )
        ) {
            /** @var float $iva Alícuota seleccionada para cálculo. */
            $iva = 21;
            if (!is_null($this->afip_helper->article->iva)) {
                $iva = (float) $this->afip_helper->article->iva->percentage;
            }

            return $this->get_price_without_iva() * $iva / 100;
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

            if (
                $this->afip_helper->sale->moneda_id == 2
                && !is_null($this->afip_helper->sale->valor_dolar)
            ) {
                Log::info('Venta en dolares, multiplicando ' . $gravado . ' * ' . $this->afip_helper->sale->valor_dolar);
                $gravado *= (float) $this->afip_helper->sale->valor_dolar;
                Log::info('QUedo en ' . $gravado);
            }

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
