<?php

namespace App\Http\Controllers\Helpers\AfipHelper;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\SaleHelper;
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
     * @var float|null $factor_total_forzado Factor del total forzado ya resuelto. `null` = todavía
     * no se calculó. Se cachea por el mismo motivo que el canje, y pesa más: el cálculo recorre
     * TODOS los renglones de la venta (arma el bruto facturable) y este método se llama una vez
     * por ítem, así que sin memoria la factura sería O(n²).
     */
    private $factor_total_forzado = null;

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

            /**
             * El total forzado (misión forzar-total-por-monto, 17/9/2026), último de todo.
             *
             * ─────────────────────────────────────────────────────────────────────────────
             *  🔴 POR QUÉ UN FACTOR SOBRE CADA RENGLÓN Y NO UNA RESTA AL FINAL DE LA FACTURA
             * ─────────────────────────────────────────────────────────────────────────────
             *
             *  Es el mismo motivo que ya está escrito largo para el canje por puntos, unas
             *  líneas más abajo, y vale igual acá: este calculador arma el total del comprobante
             *  SUMANDO LOS RENGLONES, no leyendo `sale->total`. Restar los pesos del forzado al
             *  final obligaría a decidir a mano de qué alícuota salen, y ahí el desglose que ARCA
             *  valida (neto gravado por alícuota + IVA por alícuota = Importe Total) deja de
             *  cerrar y el comprobante se rechaza. Escalando cada renglón por el mismo factor, el
             *  IVA se reparte en la misma proporción que el resto de la factura y las cuentas
             *  cierran solas — y una nota de crédito parcial devuelve la parte que le toca del
             *  forzado sin una línea extra.
             *
             *  Escalar es correcto tanto si el precio viene con IVA como si viene neto: el factor
             *  es adimensional y se aplica antes de que `get_price_sin_iva()` separe la base.
             */
            $factor_forzado = $this->get_factor_total_forzado();

            if ($factor_forzado != 1.0) {
                $price *= $factor_forzado;
            }
        // }

        return $price;
    }

    /**
     * El factor por el que hay que escalar cada renglón para que la factura dé el total forzado.
     *
     *     base   = bruto facturable de la venta − el canje por puntos, en pesos
     *     factor = sale->total (cotizado) / base
     *
     * Con ese factor, la suma de los renglones —que sin él daría `base`— da `sale->total` EXACTO,
     * que es el número que el vendedor le cobró al cliente y el único que puede ir en la factura.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 POR QUÉ EL DENOMINADOR NO ES `sale->total − forzar_total_monto`
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  Esa resta parece el total "antes del forzado" y es lo primero que uno escribe, pero NO es
     *  lo que suman los renglones de ESTE calculador, que es lo único contra lo que el factor
     *  tiene sentido. `sales.total` lo arma el front con capas que acá no existen:
     *
     *   - Los descuentos y recargos POR MEDIO DE PAGO viven en el pivot
     *     `current_acount_payment_method_sale` y están adentro de `sales.total`, pero ni
     *     `getTotalSale()` ni este calculador los conocen.
     *   - `sales.descuento` se aplica solo sobre `total_articles` en `getTotalSale()` y renglón
     *     por renglón acá: sobre una venta con servicios los dos números no coinciden.
     *
     *  Medido: con un 10 % de descuento por medio de pago, el ajuste se aplicaba un 11 % de más.
     *  Usando el bruto que este mismo calculador suma, el cociente se cancela solo y la factura
     *  cae en `sale->total` sin importar cuántas capas haya arriba.
     *
     *  Se le resta el canje por puntos porque el canje se aplica DESPUÉS, como porcentaje sobre
     *  ese mismo bruto: la suma final es `(bruto − canje) · factor`, así que el denominador tiene
     *  que ser `bruto − canje` para que el resultado dé el total.
     *
     * ⚠️ EL TOTAL SE COTIZA Y LA BASE YA VIENE COTIZADA. `get_article_price_raw()` multiplica por
     * `valor_dolar`, así que el bruto está en PESOS, mientras que `sales.total` está en la moneda
     * de la venta. Con la fórmula vieja no importaba —eran dos números de la misma moneda y el
     * cociente los cancelaba—; con ésta, no cotizar el total mezclaría dólares con pesos y el
     * factor saldría ridículamente chico. Es el mismo cuidado que ya tiene el canje.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 LA GUARDA DE REENTRADA NO SE PUEDE SACAR: DEVUELVE 1 MIENTRAS SE ARMA EL BRUTO
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  `get_bruto_facturable_de_la_venta()` suma los renglones llamando a
     *  `get_article_price_with_discounts()`, que es justamente donde se aplica este factor. Ese
     *  bruto es el denominador del porcentaje del canje por puntos. Si el factor entrara también
     *  ahí, el bruto quedaría escalado y la cuenta del canje se desarmaría:
     *
     *      con el factor adentro del bruto:  (bruto·f)·(1 − canje/(bruto·f)) = bruto·f − canje
     *      lo que tiene que dar:             (bruto − canje)·f               = bruto·f − canje·f
     *
     *  O sea que el canje quedaría sin escalar y el total de la factura se desviaría del forzado
     *  en exactamente `canje · (f − 1)`. Medido el 17/9/2026 sacando esta guarda, con el escenario
     *  de `tests/Feature/ForzarTotal/6` (bruto 100.000, canje 10.000, forzado a 89.000): la
     *  factura daba **88.888,90** en vez de 89.000 — $111,10 de menos, que son
     *  `10.000 · (89/90 − 1)`.
     *
     *  Con la guarda, el bruto es el total SIN forzar, el porcentaje del canje sale correcto y la
     *  suma final da `(bruto − canje) · f = sale->total`.
     *
     *  Es la misma guarda, por el mismo motivo, que ya usa `get_porcentaje_descuento_puntos()`.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  ⚠️ LA GUARDA MIRA LAS DOS PUNTAS: `base <= 0` Y `total < 0`
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  Mirar solo el denominador es el error fácil: con la base positiva y un `sale->total`
     *  NEGATIVO el factor sale negativo igual —por ejemplo −5— y se le mandan importes negativos a
     *  ARCA, que los rechaza. Se llega a eso con una venta cuyos renglones cambiaron después de
     *  forzar.
     *
     *  🔴 PERO EL TOTAL SE MIRA CON `< 0` Y NO CON `<= 0`, Y LA DIFERENCIA NO ES COSMÉTICA. Con
     *  `<= 0`, una venta forzada a total CERO caería en la guarda, el factor quedaría en 1 y se
     *  facturaría el BRUTO ENTERO: un comprobante fiscal por $100.000 sobre una venta de $0. Con
     *  `< 0`, el factor da 0, la factura da 0 y `AfipWsfeHelper::solicitar_cae()` corta antes de
     *  pedirle un CAE a ARCA — que es el camino que el repo ya tiene previsto para un total en
     *  cero, y el mismo que toma el canje por puntos cuando se come la venta entera.
     *
     *  O sea: el cero no es un estado degenerado, es lo que el vendedor pidió. El negativo sí.
     *
     *  En los dos casos de guarda se factura sin escalar y se deja dicho en el log, que es el
     *  mismo criterio que toma el canje cuando su bruto no da.
     *
     * @return float  1.0 si no hay nada que escalar.
     */
    public function get_factor_total_forzado()
    {
        /**
         * Mientras se arma el bruto facturable, el forzado no existe. Ver el bloque de arriba.
         *
         * 🔴 VA ANTES DE LA MEMORIA, no después: durante el armado del bruto la respuesta correcta
         * es 1 pero NO es la respuesta definitiva, y cachearla dejaría el factor en 1 para toda la
         * factura. Es el mismo orden, por el mismo motivo, que en `get_porcentaje_descuento_puntos()`.
         */
        if ($this->calculando_bruto_de_la_venta) {
            return 1.0;
        }

        if (!is_null($this->factor_total_forzado)) {
            return $this->factor_total_forzado;
        }

        // Se cachea el 1 primero: casi ninguna venta fuerza el total y ese es el camino barato.
        $this->factor_total_forzado = 1.0;

        /** @var \App\Models\Sale|null $sale Venta sobre la que se está facturando. */
        $sale = $this->afip_helper->sale;

        if (is_null($sale)) {
            return 1.0;
        }

        /** @var float $monto Monto con signo del forzado. 0 = la venta no se forzó. */
        $monto = SaleHelper::get_forzar_total_monto($sale);

        if ($monto == 0) {
            return 1.0;
        }

        /**
         * @var float $total El total final de la venta, que ya tiene el forzado adentro, cotizado
         * a pesos para poder compararse contra el bruto. Ver el ⚠️ de arriba.
         */
        $total = (float) $sale->total;

        if ($sale->moneda_id == 2 && $sale->valor_dolar) {
            $total *= (float) $sale->valor_dolar;
        }

        /**
         * @var float $base Lo que suman los renglones de esta factura por su cuenta: el bruto
         * facturable menos el canje por puntos, que se aplica después.
         */
        $base = $this->get_bruto_facturable_de_la_venta() - $this->get_canje_en_pesos();

        if ($base <= 0 || $total < 0) {
            Log::warning(
                'AfipItemCalculator: la venta '.$sale->id.' tiene forzar_total_monto ('.$monto.
                ') pero su base facturable es '.$base.' y su total es '.$total.
                '. No se puede prorratear sin mandar importes negativos: se factura sin escalar.'
            );

            return 1.0;
        }

        $this->factor_total_forzado = $total / $base;

        return $this->factor_total_forzado;
    }

    /**
     * Los pesos que el cliente canjeó en puntos en esta venta, ya cotizados.
     *
     * Existe como método propio porque lo necesitan DOS cuentas que tienen que usar exactamente el
     * mismo número: el porcentaje del canje (`get_porcentaje_descuento_puntos()`) y la base del
     * factor del total forzado (`get_factor_total_forzado()`). Si las dos lo derivaran por su
     * cuenta, alcanzaría con que una se olvidara de cotizar para que la factura dejara de cerrar.
     *
     * @return float  0 si la venta no canjeó puntos.
     */
    private function get_canje_en_pesos()
    {
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
         * El canje viaja en la MONEDA DE LA VENTA (el front hace `total -= descuento_puntos` sobre
         * el total en esa moneda), mientras que el bruto contra el que se compara ya viene cotizado
         * a pesos por `get_article_price_raw()`.
         */
        if ($sale->moneda_id == 2 && $sale->valor_dolar) {
            $descuento *= (float) $sale->valor_dolar;
        }

        return $descuento;
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

        /**
         * @var float $descuento Pesos que canjeó el cliente, ya cotizados. Sale del mismo método
         * que usa la base del factor del total forzado, para que las dos cuentas no puedan
         * discrepar.
         */
        $descuento = $this->get_canje_en_pesos();

        if ($descuento <= 0) {
            return 0.0;
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

        /**
         * 🔴 EL FACTOR DEL TOTAL FORZADO, TAMBIÉN EN LA RAMA CRUDA (misión forzar-total-por-monto,
         * 17/9/2026).
         *
         * Acá se llega en Factura C (monotributo), en exportación, y en cualquier ítem Exento / No
         * Gravado / 0 % de una A/B. El precio que se devuelve es CRUDO: no lleva los descuentos de
         * venta, ni `sales.descuento`, ni el canje por puntos. Eso es preexistente, está reportado
         * y NO se toca acá — arreglarlo cambiaría el precio impreso de todo el parque.
         *
         * Lo que sí se agrega es el factor, y no es una inconsistencia más: es la que evita una.
         * `sub_total()` —la columna de al lado, en la misma fila— pasa por la misma rama cruda, así
         * que si el factor entrara solo en una de las dos, en una Factura C forzada dejaría de
         * valer `Precio × Cantidad == Subtotal`. Las dos lo llevan o ninguna.
         *
         * Con una venta sin forzar el factor es 1 y esta línea no cambia absolutamente nada.
         */
        return $price * $this->get_factor_total_forzado();
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

        /**
         * El factor del total forzado, por el mismo motivo y con el mismo alcance que en
         * `get_article_price()`: las dos columnas de la fila pasan por esta rama cruda en una
         * Factura C, y si el factor entrara en una sola dejaría de valer
         * `Precio × Cantidad == Subtotal`. Ver el bloque largo de allá.
         */
        return $this->get_article_price_raw() * $this->get_article_amount() * $this->get_factor_total_forzado();
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
