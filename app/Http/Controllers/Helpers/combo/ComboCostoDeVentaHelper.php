<?php

namespace App\Http\Controllers\Helpers\combo;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Combo;
use Illuminate\Support\Facades\Log;

/**
 * El costo de un combo en el momento de venderlo (misión combos-calculados, Parte A2, 30/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL PROBLEMA QUE RESUELVE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `sales.total` incluye el precio del combo, pero `sales.total_cost` no incluía su costo: la
 *  ganancia de una venta con combo salía inflada por el precio ENTERO del combo. La columna donde
 *  guardar el costo ya existía (`combo_sale.cost`) y la leía `Sale::combos()`, pero nadie la
 *  escribía. Esta clase decide el número que se escribe ahí; `SaleTotalesHelper::set_total_cost()`
 *  lo suma.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA REGLA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Es el costo UNITARIO del combo (el de UN combo), igual que `article_sale.cost` es el de UNA
 *  unidad del artículo; `set_total_cost()` lo multiplica por la cantidad. Se congela en la venta:
 *  cambiar después el combo o el costo de un artículo no toca lo ya vendido.
 *
 *   - Combo CALCULADO (`calcular_desde_articulos = 1`): Σ (costo de línea de cada componente x su
 *     cantidad dentro del combo). El "costo de línea" es EXACTAMENTE el que tendría ese artículo
 *     vendido suelto en esta misma venta, porque se le pide a `SaleHelper::getCost()` — la regla
 *     de la venta no se reimplementa acá: respeta la moneda de la venta y su `valor_dolar`, el
 *     costo en dólares del artículo, las unidades individuales y la opción de la cuenta
 *     `aplicar_descuentos_de_venta_a_costos`.
 *   - Combo MANUAL: `combos.cost` tal cual, pasado por el mismo `getCost()` como si fuera un ítem
 *     en pesos (un combo no tiene moneda propia): en una venta en dólares se divide por el
 *     `valor_dolar` de la venta, igual que el SPA divide su precio para mostrarlo en dólares.
 *   - NULL si no se puede resolver: el combo no existe (o es de otra cuenta), el manual no tiene
 *     costo cargado, el calculado no tiene componentes o ninguno tiene costo, o la venta es en
 *     dólares sin cotización. NULL y no 0: "no sé" no es lo mismo que "no cuesta nada", y
 *     `set_total_cost()` los trata igual (suma 0, como las ventas viejas) pero la fila conserva
 *     la diferencia para el que la mire después.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL SERVIDOR DECIDE, NUNCA EL PAYLOAD
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Acá no entra ningún `cost` que venga en el request, en el presupuesto o en el pedido de la
 *  tienda (`order_combo.cost`, que hoy la tienda deja en NULL y mañana puede traer cualquier
 *  cosa): el costo sale de la base, por el id del combo. Es la lección del proyecto — "el API le
 *  creía al front" (venta 54.499 de ferretotal): la ganancia es plata, y el costo de un combo lo
 *  sabe el servidor.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  DOS DÓLARES DISTINTOS, A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `combos.cost` (el del ABM, lo escribe `ComboCalculadoHelper`) cotiza los componentes en dólares
 *  con `ArticleHelper::cotizar()`: el dólar del proveedor o el global del dueño, porque ahí no hay
 *  venta. Acá manda `sales.valor_dolar`: en una venta el costo se mide con la cotización de ESA
 *  venta, igual que el de cualquier artículo suelto. Por eso el costo congelado de un combo
 *  calculado puede no coincidir con `combos.cost` de hoy, y está bien.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  COMPONENTES BORRADOS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `Combo::articles()` trae también los borrados (`withTrashed`) y se cuentan con sus últimos
 *  valores, igual que los cuenta el cálculo del ABM. Sacarlos de la suma abarataría el combo en
 *  silencio y la ganancia saldría inflada, que es justo lo que esto viene a evitar. (El combo con
 *  un componente borrado no se puede vender por stock — `ComboStockHelper` — pero una venta vieja
 *  que se edita, o un pedido ya armado, sí puede llegar hasta acá.)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LÍMITE CONOCIDO (no se arregla acá)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `CostoDeVentaHelper::medir_venta()` (el crédito fiscal que trae adentro el costo en las cuentas
 *  con el costo BRUTO) recorre solo los artículos de la venta: no ve los combos. En esas cuentas
 *  el costo del combo entra bruto al `total_cost` y su IVA de compra no se recupera en la
 *  ganancia. Es una subestimación de la ganancia (no una inflación), de una sola clase de cuentas,
 *  y arreglarlo es tocar esa clase, que tiene su propia misión.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ComboCostoDeVentaHelper {

    /**
     * El mapa `combo_id => cost` de los combos que la venta YA TENÍA antes de editarla.
     *
     * 🔴 Editar una venta borra `combo_sale` y lo vuelve a armar (`SaleHelper::detachItems()` +
     * `attachCombos()`). Si cada combo se recalculara con el costo de HOY, una venta vieja cambiaría
     * su `total_cost` y su ganancia retroactivamente por editarle una observación — cosa que los
     * artículos sueltos NO hacen: `getCost()` devuelve el `pivot.cost` guardado cuando el ítem lo
     * trae. El combo hace lo mismo: el costo que la venta ya congeló se conserva tal cual, incluso
     * si es NULL (una venta anterior a esta misión no pasa a tener costo por editarse).
     *
     * `$previus_combos` es la colección que `SaleController::update()` lee de la BASE antes de
     * borrar nada (`$model->combos()->lockForUpdate()->get()`), con `pivot->cost`. Nunca sale del
     * payload.
     *
     * @param  iterable|null  $previus_combos
     * @return array<int,float|null>
     */
    static function costos_previos($previus_combos) {

        $mapa = [];

        if (is_null($previus_combos)) {
            return $mapa;
        }

        foreach ($previus_combos as $combo) {

            $id = (int) $combo->id;

            // Si el mismo combo estuviera en dos renglones, vale el primero (tienen el mismo costo).
            if (array_key_exists($id, $mapa)) {
                continue;
            }

            $mapa[$id] = (isset($combo->pivot) && !is_null($combo->pivot->cost)) ? (float) $combo->pivot->cost : null;
        }

        return $mapa;
    }

    /**
     * El costo con el que se adjunta un renglón de combo a la venta: el que la venta ya tenía si el
     * combo YA estaba (ver `costos_previos()`), o el calculado hoy si es NUEVO en la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  mixed             $combo_id
     * @param  array|null        $costos_previos  Lo que devolvió `costos_previos()`; null = alta.
     * @return float|null
     */
    static function costo_para_renglon($sale, $combo_id, $costos_previos = null) {

        if (is_array($costos_previos) && is_numeric($combo_id) && array_key_exists((int) $combo_id, $costos_previos)) {
            return $costos_previos[(int) $combo_id];
        }

        return self::costo_unitario($sale, $combo_id);
    }

    /**
     * El costo unitario del combo para esta venta, o null si no se puede resolver.
     *
     * @param  \App\Models\Sale|\App\Models\Budget  $sale      Venta (con `user_id`, `moneda_id`, `valor_dolar`).
     * @param  mixed                                $combo_id  Id del combo (puede venir del payload: se valida).
     * @return float|null
     */
    static function costo_unitario($sale, $combo_id) {

        if (!is_numeric($combo_id) || (int) $combo_id <= 0) {
            return null;
        }

        /*
         * Con borrados (`withTrashed`): una venta puede llevar un combo que se borró después
         * (`Sale::combos()` también los trae), y se le tiene que poder calcular el costo. Y se
         * filtra por el dueño de la venta para no leerle el costo a un combo de otra cuenta con
         * solo mandar su id.
         */
        $combo = Combo::withTrashed()
                        ->where('id', (int) $combo_id)
                        ->where('user_id', $sale->user_id)
                        ->first();

        if (is_null($combo)) {
            return null;
        }

        /*
         * 🔴 Venta en dólares sin cotización: `getCost()` dividiría por cero (en PHP 7.4 es INF con
         * un warning, y un INF en una columna DECIMAL rompe el guardado de la venta entera). Un
         * costo que no se puede expresar en la moneda de la venta es un costo que no se puede
         * resolver.
         */
        if ((int) $sale->moneda_id === 2 && (float) $sale->valor_dolar <= 0) {

            Log::info('ComboCostoDeVentaHelper: venta en dolares sin valor_dolar, el costo del combo '.$combo->id.' queda en NULL.');

            return null;
        }

        $costo = ((int) $combo->calcular_desde_articulos === 1)
            ? self::costo_de_combo_calculado($sale, $combo)
            : self::costo_de_combo_manual($sale, $combo);

        if (is_null($costo)) {
            return null;
        }

        return round((float) $costo, 2);
    }

    /**
     * Combo manual: su `cost` tal cual, en pesos, pasado por la regla de la venta.
     *
     * @param  \App\Models\Sale   $sale
     * @param  \App\Models\Combo  $combo
     * @return float|null
     */
    protected static function costo_de_combo_manual($sale, Combo $combo) {

        if (is_null($combo->cost)) {
            return null;
        }

        /*
         * El ítem que entiende `getCost()`. Con `unidades_individuales` presente (aunque sea null)
         * `getCost()` no sale a buscarlas a la base: un combo no las tiene. `cost_in_dollars` en 0
         * porque un combo manual se carga en pesos.
         */
        $costo = SaleHelper::getCost($sale, [
            'cost'                  => $combo->cost,
            'cost_in_dollars'       => 0,
            'unidades_individuales' => null,
        ]);

        return is_null($costo) ? null : (float) $costo;
    }

    /**
     * Combo calculado: Σ (costo de línea de cada componente x su cantidad en el combo).
     *
     * Un componente sin costo cargado no suma nada (como un artículo suelto sin costo, que se
     * vende con `article_sale.cost` en NULL y no entra a `total_cost`); si NINGÚN componente tiene
     * costo, el costo del combo no se puede resolver (NULL).
     *
     * @param  \App\Models\Sale   $sale
     * @param  \App\Models\Combo  $combo
     * @return float|null
     */
    protected static function costo_de_combo_calculado($sale, Combo $combo) {

        $total  = 0.0;
        $alguno = false;

        // `articles()` incluye los borrados y trae `pivot->amount` (ver "Componentes borrados").
        foreach ($combo->articles()->get() as $article) {

            /*
             * El ítem mínimo que `getCost()` necesita, armado desde la base y no con `toArray()`:
             * el modelo serializa accesores pesados que acá no hacen falta. Con la clave
             * `unidades_individuales` presente, `getCost()` divide por las del artículo sin ir a
             * buscarlas de nuevo.
             */
            $costo = SaleHelper::getCost($sale, [
                'id'                    => $article->id,
                'cost'                  => $article->cost,
                'costo_real'            => $article->costo_real,
                'cost_in_dollars'       => $article->cost_in_dollars,
                'unidades_individuales' => $article->unidades_individuales,
            ]);

            if (is_null($costo)) {
                continue;
            }

            /*
             * `getCost()` devuelve 0 (no null) para un artículo sin costo cuando la cuenta aplica
             * los descuentos de la venta a los costos, así que "tiene costo" se decide mirando la
             * ficha, no el resultado.
             */
            if (!is_null($article->costo_real) || !is_null($article->cost)) {
                $alguno = true;
            }

            $total += (float) $costo * (float) $article->pivot->amount;
        }

        return $alguno ? $total : null;
    }
}
