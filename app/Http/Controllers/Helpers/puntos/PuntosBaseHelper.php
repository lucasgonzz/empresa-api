<?php

namespace App\Http\Controllers\Helpers\puntos;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * El cálculo del monto base y de los puntos de una venta. Clase sin estado: se le pasa la
 * venta y el programa, y devuelve números. No escribe una sola fila y no engancha nada.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 SOLO ACUMULAN LOS RENGLONES DE `article_sale`.
 *  Los servicios (`sale_service`), los combos (`combo_sale`) y las promociones de vinoteca
 *  NO suman puntos, y no es una omisión: es la única decisión posible con el schema que hay.
 *
 *   1. NO SE PUEDE SABER SU LISTA DE PRECIO EFECTIVA. `price_type_personalizado_id` existe
 *      solo en `article_sale`. De un servicio o un combo solo se tiene el encabezado
 *      (`sales.price_type_id`), así que en una venta mixta el filtro por lista sería
 *      adivinanza. El pedido es explícitamente "por renglón, no por venta".
 *   2. NO SE LES PUEDE SACAR EL IVA CON DATOS CONGELADOS. No tienen `iva_percentage` ni
 *      `price_sin_iva`. Netearlos exigiría ir a buscar el IVA ACTUAL del servicio o de los
 *      artículos del combo, que además cambia con el tiempo: es leer un derivado de otra
 *      fuente, exactamente el error que originó `iva_percentage` en `article_sale`.
 *
 *  Si mañana se quiere que sumen, lo que hay que hacer PRIMERO es agregarles las tres
 *  columnas al pivot (price_sin_iva, iva_percentage, price_type_personalizado_id), no
 *  parchear este helper para que adivine.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PuntosBaseHelper {

    /**
     * Centinela de "sin lista de precio" en `movimiento_puntos.price_type_id`.
     *
     * Es 0 y no NULL porque esa columna participa del unique
     * (sale_id, tipo, price_type_id) y MySQL permite N filas con NULL en un unique: con NULL
     * la red que protege contra los puntos duplicados no protegería nada justo en el caso más
     * común, que es el comercio que no usa listas de precio.
     */
    const SIN_LISTA = 0;

    /**
     * Tolerancia de un centavo, el mismo $delta que usa CurrentAcountPagoHelper:141 para
     * decidir si un débito quedó saldado. Los decimales de MySQL y los float de PHP no cierran
     * al bit, y un resto de 0.004 pesos no puede cambiar la cantidad de puntos.
     */
    const DELTA = 0.01;

    /**
     * Calcula los grupos de puntos de una venta, uno por lista de precio efectiva.
     *
     * Se agrupa POR LISTA y no se emite un solo movimiento por venta porque la decisión es
     * guardar CON QUÉ LISTA se otorgó cada punto, y una venta mixta puede tener renglones de
     * dos listas distintas. Consecuencia visible para el usuario, y por eso queda escrita:
     * dos renglones de listas distintas redondean POR SEPARADO, así que la suma de los dos
     * grupos puede dar un punto menos que el redondeo del total junto.
     *
     * @param  \App\Models\Sale                 $sale      Venta ya persistida, con sus artículos.
     * @param  \App\Models\SistemaDePuntos|null $sistema   El programa activo del comercio.
     * @param  float|null                       $factor_nc El factor de nota de crédito, si el que
     *                                                     llama ya lo calculó de a muchos
     *                                                     (reconciliar_cuenta_corriente lo hace).
     *                                                     `null` = se calcula acá, como siempre.
     * @return array  Lista de array('price_type_id' => int, 'base' => float, 'puntos' => float).
     *                🔴 SOLO los grupos que efectivamente ganan puntos (puntos > 0): un
     *                movimiento 'ganados' de 0 puntos es ruido en la ficha del cliente y ocupa
     *                al pedo un lugar del unique. Un array vacío significa "esta venta no
     *                otorga nada", que es lo que el reconciliador necesita para saber que tiene
     *                que revertir lo que hubiera de antes.
     */
    static function calcular_grupos($sale, $sistema, $factor_nc = null) {

        if (is_null($sale) || is_null($sistema)) {
            return [];
        }

        $listas       = PuntosConfigHelper::listas_habilitadas($sistema);
        $tiene_filtro = count($listas) > 0;

        /*
         * Dos acumuladores por grupo, no uno. `bruta` es la base sin descontar devoluciones y
         * `neta` es con las devoluciones descontadas: los dos hacen falta para el tapón de la
         * nota de crédito (ver factor_nota_credito()), que compara una contra la otra.
         */
        $bruta_por_lista = [];
        $neta_por_lista  = [];

        /*
         * Las TRES capas de descuento que viven en el ENCABEZADO de la venta, resueltas una
         * sola vez y aplicadas a cada renglón. Ver factor_descuentos_de_venta().
         */
        $factor_venta = self::factor_descuentos_de_venta($sale);

        foreach ($sale->articles as $article) {

            $pivot = $article->pivot;

            if (is_null($pivot)) {
                continue;
            }

            $lista_efectiva = self::lista_efectiva_del_renglon($sale, $pivot);

            if (!self::el_renglon_suma($lista_efectiva, $listas, $tiene_filtro)) {
                continue;
            }

            $clave = is_null($lista_efectiva) ? self::SIN_LISTA : (int) $lista_efectiva;

            $neto_unitario = self::neto_unitario_sin_iva($sale, $pivot);

            /*
             * El `discount` del renglón SÍ se aplica: es un porcentaje sobre esa línea y forma
             * parte del subtotal del sistema (SaleHelper::getTotalItem(), SaleHelper.php:1774,
             * hace exactamente lo mismo). Es la PRIMERA de las cuatro capas de descuento; las
             * otras tres son del encabezado y entran por $factor_venta.
             */
            $factor_descuento = 1 - (self::a_float($pivot->discount) / 100);

            if ($factor_descuento < 0) {
                $factor_descuento = 0;
            }

            $unidades_vendidas = self::a_float($pivot->amount);

            /*
             * `returned_amount` SÍ se resta: es la cantidad devuelta por nota de crédito, y la
             * mantienen los flujos de NC (SaleHelper::getReturnedAmount() al guardar,
             * NotaCreditoHelper::resetUnidadesDevueltas() al borrar la NC). Es lo que hace que
             * una devolución baje los puntos sin una sola línea de código extra.
             */
            $unidades_netas = $unidades_vendidas - self::a_float($pivot->returned_amount);

            if ($unidades_netas < 0) {
                $unidades_netas = 0;
            }

            if (!array_key_exists($clave, $bruta_por_lista)) {
                $bruta_por_lista[$clave] = 0;
                $neta_por_lista[$clave]  = 0;
            }

            $bruta_por_lista[$clave] += $neto_unitario * $unidades_vendidas * $factor_descuento * $factor_venta;
            $neta_por_lista[$clave]  += $neto_unitario * $unidades_netas    * $factor_descuento * $factor_venta;
        }

        if (is_null($factor_nc)) {
            $factor_nc = self::factor_nota_credito($sale);
        }

        $grupos = [];

        foreach ($neta_por_lista as $clave => $base_neta) {

            $base_final = self::aplicar_factor_nota_credito($base_neta, $bruta_por_lista[$clave], $factor_nc);

            if ($base_final <= self::DELTA) {
                continue;
            }

            $puntos = self::puntos_de_la_base($base_final, $sistema, self::multiplicador_efectivo($clave, $listas));

            if ($puntos <= 0) {
                continue;
            }

            $grupos[] = [
                'price_type_id' => (int) $clave,
                'base'          => round($base_final, 2),
                'puntos'        => $puntos,
            ];
        }

        return $grupos;
    }

    /**
     * El factor que dejan las TRES capas de descuento del ENCABEZADO de la venta.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 EL BUG QUE ESTO ARREGLA: LOS PUNTOS SE CALCULABAN SOBRE EL BRUTO.
     *
     *  Hasta el 22/8/2026 acá solo se aplicaba `article_sale.discount` (el descuento del
     *  renglón) y las otras tres capas —que la SPA aplica como PORCENTAJES SOBRE EL TOTAL, no
     *  sobre el precio de la línea— quedaban afuera. Medido contra la base: una venta de
     *  $121.000 con `sales.descuento = 50` cerraba con `sales.total = 60.500` y el libro de
     *  puntos escribía `monto_base = 100.000` y otorgaba 100 puntos, cuando corresponden 50.
     *  El programa está calibrado al 1% de retorno: en TODA venta con descuento global devolvía
     *  el doble. Y el `monto_base` —que es la columna de auditoría, la que explica de dónde
     *  salieron los puntos— quedaba escrito con un número que no existió nunca.
     *
     *  LAS CUATRO CAPAS, EN EL ORDEN CANÓNICO DEL SISTEMA. Es el mismo que arma
     *  `AfipHelper/AfipItemCalculator::get_article_price_with_discounts()`, que es el código que
     *  liquida el comprobante y por lo tanto la definición de referencia:
     *
     *    1. `article_sale.discount`                 (renglón)     ← se aplica arriba, en el loop
     *    2. `discount_sale.percentage`   (la lista de Descuentos de la venta)
     *    3. `sale_surchage.percentage`   (la lista de Recargos — este SUBE la base)
     *    4. `sales.descuento`            (el descuento global de la venta)
     *
     *  🔴 EL ORDEN NO CAMBIA EL NÚMERO, y por eso no hay que discutirlo: las cuatro son
     *  porcentajes multiplicativos sobre el mismo precio, así que el producto conmuta. (La SPA
     *  las aplica en otro orden —`aplicar_descuento()` antes que `aplicar_discounts()` y
     *  `aplicar_surchages()`, en `empresa-spa/src/mixins/vender_set_total.js`— y llega al mismo
     *  total. Se sigue el orden del calculador de AFIP porque es el que hay que respetar si
     *  mañana alguien mete una capa que NO sea multiplicativa.)
     *
     *  🔴 EL CANJE DE PUNTOS (`sales.descuento_puntos`) NO ENTRA ACÁ, Y ES UNA DECISIÓN DE
     *  NEGOCIO, NO UN OLVIDO. El canje también baja `sales.total` —es la quinta capa del
     *  calculador de AFIP— pero la base de ACUMULACIÓN no lo descuenta: los puntos se ganan
     *  sobre LO QUE SE COMPRÓ, no sobre lo que se pagó en efectivo. Descontarlo sería castigar
     *  al cliente que usa el programa (gasta puntos → gana menos puntos → gasta menos → ...),
     *  que es exactamente lo contrario de para qué existe un programa de fidelización. El
     *  comportamiento de hoy ya era ése —este helper nunca miró `descuento_puntos`— y queda
     *  escrito acá porque el chequeo independiente lo marcó como "decisión de negocio que no
     *  está en ningún lado". Si algún día se quiere al revés, se cambia ACÁ y en un solo lugar.
     *
     *  ⚠️ DIVERGENCIA CONOCIDA CON `AfipItemCalculator`, ANOTADA Y NO TOCADA (`AfipHelper/` está
     *  fuera del alcance de este arreglo): allá `sales.descuento` se aplica con la guarda
     *  `> 0`, o sea que un `descuento` NEGATIVO —que es como el sistema representa un recargo
     *  global— no llega al comprobante. La SPA sí lo aplica (`if (this.descuento)`, que es
     *  verdadero también con negativo) y es la SPA la que escribe `sales.total`. Acá se sigue a
     *  la SPA, con `!= 0`, porque el `sales.total` guardado es el hecho económico: medido, una
     *  venta con `descuento = -100` cierra en $242.000 y tiene que otorgar 200 puntos, no 100.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * @param  \App\Models\Sale  $sale  Con `discounts` y `surchages` cargados (los carga
     *                                  PuntosAcumulacionHelper antes de llamar).
     * @return float  Piso en 0: una venta descontada al 120% no otorga puntos negativos.
     */
    static function factor_descuentos_de_venta($sale) {

        if (is_null($sale)) {
            return 1;
        }

        $factor = 1;

        /*
         * Capa 2: la lista de Descuentos de la venta (`discount_sale`). Se aplican EN CASCADA,
         * uno sobre el resultado del anterior, igual que en el front y en el calculador de
         * AFIP: dos descuentos del 10% dan 19%, no 20%.
         */
        foreach ($sale->discounts as $discount) {
            $factor -= $factor * self::a_float($discount->pivot->percentage) / 100;
        }

        /*
         * Capa 3: los Recargos (`sale_surchage`), que van para el otro lado y SUBEN la base.
         *
         * 🔴 `aplicar_recargos_directo_a_items` es la guarda que no se puede olvidar: con esa
         * bandera prendida el recargo ya viene metido en el `price` de cada renglón, así que
         * aplicarlo otra vez acá lo cobraría dos veces. Es la misma condición que pone
         * AfipItemCalculator y la misma que pone `aplicar_surchages()` en la SPA.
         */
        if (!$sale->aplicar_recargos_directo_a_items) {

            foreach ($sale->surchages as $surchage) {
                $factor += $factor * self::a_float($surchage->pivot->percentage) / 100;
            }
        }

        /*
         * Capa 4: el descuento global de la venta. Con `!= 0` y no `> 0` — ver la divergencia
         * anotada en el docblock.
         */
        $descuento_global = self::a_float($sale->descuento);

        if ($descuento_global != 0) {
            $factor -= $factor * $descuento_global / 100;
        }

        if ($factor < 0) {
            return 0;
        }

        return $factor;
    }

    /**
     * La lista de precio con la que se vendió ESTE renglón.
     *
     * // NO volver a angostar `sales.price_type_id`: nació como $table->boolean(), o sea tinyint(1), en
     * // 2019_10_24_003026_create_sales_table.php:29, y aguantaba hasta la lista de precio N° 127.
     * // Su valor sale de price_types.id (bigint unsigned) o de clients.price_type_id (int unsigned):
     * // las dos son más anchas. Es la clase "columna derivada con tipo mas angosto que su fuente" de
     * // contexto/APRENDER_NO_PARCHEAR.md:201. Se ensanchó a INT UNSIGNED el 22/8/2026 porque este
     * // módulo decide con esa columna si un cliente cobra puntos o no.
     *
     * `article_sale.price_type_personalizado_id` es la otra mitad: es `int` con signo, o sea
     * también más angosta que `price_types.id`, pero benigna por rango (techo 2.1e9) y
     * `article_sale` es la tabla más grande del sistema, así que un ALTER ahí es un riesgo
     * desproporcionado al problema. Queda anotada, no tocada.
     *
     * @param  \App\Models\Sale  $sale
     * @param  object            $pivot  El pivot de article_sale.
     * @return int|null  null = el renglón no tiene lista (ni propia ni heredada).
     */
    static function lista_efectiva_del_renglon($sale, $pivot) {

        if (!is_null($pivot) && !is_null($pivot->price_type_personalizado_id) && $pivot->price_type_personalizado_id) {
            return (int) $pivot->price_type_personalizado_id;
        }

        if (!is_null($sale) && !is_null($sale->price_type_id) && $sale->price_type_id) {
            return (int) $sale->price_type_id;
        }

        return null;
    }

    /**
     * Las cinco reglas de borde del filtro por lista, en un solo lugar.
     *
     * | El programa NO tiene ninguna lista marcada    | suma todo, con price_type_id = 0  |
     * | Tiene listas y la efectiva está en la lista   | suma                              |
     * | Tiene listas y la efectiva NO está            | no suma                           |
     * | La efectiva es NULL y el programa filtra      | no suma                           |
     * | La efectiva es NULL y el programa NO filtra   | suma, con price_type_id = 0       |
     *
     * @param  int|null  $lista_efectiva
     * @param  array     $listas         price_type_id => multiplicador.
     * @param  bool      $tiene_filtro
     * @return bool
     */
    private static function el_renglon_suma($lista_efectiva, $listas, $tiene_filtro) {

        if (!$tiene_filtro) {
            return true;
        }

        if (is_null($lista_efectiva)) {
            return false;
        }

        return array_key_exists((int) $lista_efectiva, $listas);
    }

    /**
     * El precio unitario NETO DE IVA del renglón.
     *
     * Se prefiere SIEMPRE `price_sin_iva`, que es el valor CONGELADO que persistió
     * SaleHelper::attachArticle() (SaleHelper.php:941). La columna existe justamente para no
     * recalcular, pero es nullable y las ventas viejas la tienen en NULL: para esas se deriva
     * de `price` usando `article_sale.iva_percentage`, que también es el valor congelado y NO
     * el IVA actual del artículo (el IVA de un artículo cambia, y una venta de hace dos años
     * no se recalcula con el IVA de hoy).
     *
     * @param  \App\Models\Sale  $sale
     * @param  object            $pivot
     * @return float
     */
    static function neto_unitario_sin_iva($sale, $pivot) {

        $price = self::a_float($pivot->price);

        /*
         * `sales.iva_aplicado` (tinyint(1), default 1) dice si los precios de la venta llevan
         * IVA. Con 0 el `price` YA es neto, y volver a dividir sería restar el IVA dos veces.
         */
        if (!is_null($sale) && !$sale->iva_aplicado) {
            return $price;
        }

        if (!is_null($pivot->price_sin_iva)) {
            return self::a_float($pivot->price_sin_iva);
        }

        return self::derivar_neto($price, $pivot->iva_percentage);
    }

    /**
     * Deriva el neto a partir del precio con IVA y la alícuota congelada del renglón.
     *
     * Es exactamente la lógica de SaleHelper::get_price_sin_iva() (SaleHelper.php:1063), y
     * tiene que seguir siéndolo: `article_sale.iva_percentage` es varchar(20) y guarda
     * ETIQUETAS FISCALES además de números ('Exento', 'No Gravado'). Esa columna es
     * literalmente el caso que originó la clase de error del bloqueante de esta misión
     * (APRENDER_NO_PARCHEAR.md:201): tratarla como número la rompe.
     *
     * Cuando la alícuota tampoco está (venta vieja sin `iva_percentage`), se asume 21, igual
     * que el default de get_price_sin_iva(). Es el lado conservador: asumir el IVA más alto
     * da la base más chica, o sea menos puntos, que es el error barato.
     *
     * @param  float             $price_con_iva
     * @param  string|float|null $iva_percentage
     * @return float
     */
    static function derivar_neto($price_con_iva, $iva_percentage) {

        $price_con_iva = (float) $price_con_iva;

        if (is_null($iva_percentage) || $iva_percentage === '') {
            $iva_percentage = 21;
        }

        if (
            $iva_percentage === 'No Gravado'
            || $iva_percentage === 'Exento'
            || (float) $iva_percentage == 0
        ) {
            return round($price_con_iva, 2);
        }

        return round($price_con_iva / (((float) $iva_percentage / 100) + 1), 2);
    }

    /**
     * El factor que descuenta las notas de crédito SIN ITEMS.
     *
     * 🔴 EL AGUJERO QUE ESTO TAPA: una nota de crédito es un `haber` que se imputa DIRIGIDO al
     * débito de la venta (`to_pay_id`, CurrentAcountHelper.php:199-213). O sea que una venta
     * devuelta entera llega a `status = 'pagado'` SIN QUE EL CLIENTE HAYA PAGADO UN PESO. Con
     * la regla ingenua ("el débito está pagado, dale los puntos"), devolver una venta REGALA
     * puntos.
     *
     * Los tapones son dos y los dos hacen falta:
     *   1. `returned_amount` por renglón, que ya cubre las NC que traen items (las de los
     *      flujos de venta y de devoluciones);
     *   2. este factor, para las NC de monto libre SIN items, que crea
     *      CurrentAcountController@notaCredito y que no tocan ningún `article_current_acount`.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 UNA NC LLEGA A LA VENTA POR DOS CAMINOS, Y HAY QUE MIRAR LOS DOS.
     *
     *  Hasta el 22/8/2026 acá se buscaba únicamente por `current_acounts.sale_id`, y eso
     *  dejaba afuera justo la mitad que este factor decía tapar: la NC de monto libre de
     *  `CurrentAcountController@notaCredito` NACE SIN `sale_id` (el request de la SPA manda
     *  monto, descripción y cliente, y nada más — es un ajuste a la cuenta, que puede no
     *  corresponder a ninguna venta). Esa NC llega igual a la venta, pero por el otro camino:
     *  entra a la cola FIFO de `CurrentAcountPagoHelper`, salda el débito de la venta y queda
     *  registrada en `pagado_por` con lo que efectivamente le imputó.
     *
     *  Por eso el total de NC de esta venta es la unión de los dos:
     *    - las que se declaran suyas (`sale_id`), por su `haber` entero, como siempre;
     *    - las que la saldaron sin declararse (imputación FIFO), por lo que `pagado_por` dice
     *      que le imputaron A ESTE DÉBITO — no por su `haber`, que puede haberse repartido
     *      entre varias ventas.
     *
     *  Y se excluyen del segundo camino las que ya se contaron en el primero: la NC de
     *  `POST api/devoluciones` aparece en los dos lados (tiene `sale_id` Y se imputa dirigida
     *  por `to_pay_id`), y sumarla dos veces le comería a la venta el doble de lo devuelto.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * @param  \App\Models\Sale  $sale
     * @return float  Entre 0 y 1.
     */
    static function factor_nota_credito($sale) {

        if (is_null($sale) || is_null($sale->id)) {
            return 1;
        }

        $factores = self::factores_nota_credito([$sale->id], [(int) $sale->id => (float) $sale->total]);

        $sale_id = (int) $sale->id;

        return array_key_exists($sale_id, $factores) ? $factores[$sale_id] : 1;
    }

    /**
     * El mismo factor que `factor_nota_credito()`, pero para MUCHAS ventas y con dos consultas
     * fijas en vez de dos por venta.
     *
     * Existe para `PuntosAcumulacionHelper::reconciliar_cuenta_corriente()`, que visita un lote
     * de ventas de una: allá las dos consultas por venta eran la mitad del N+1 que se arregló el
     * 22/8/2026. La versión de una sola venta delega ACÁ, para que la definición del factor —que
     * es plata y es sutil (los dos caminos por los que una NC llega a la venta, y la resta de las
     * que se contarían dos veces)— exista una sola vez.
     *
     * @param  array  $sale_ids
     * @param  array  $totales  sale_id => `sales.total`. Hace falta porque el factor es una
     *                          proporción contra el total de la venta y esta forma no lee las
     *                          ventas.
     * @return array  sale_id => factor entre 0 y 1.
     */
    static function factores_nota_credito($sale_ids, $totales) {

        $factores = [];

        if (!count($sale_ids)) {
            return $factores;
        }

        $nc_por_venta = [];

        foreach ($sale_ids as $sale_id) {
            $nc_por_venta[(int) $sale_id] = 0;
        }

        /*
         * Camino 1: las NC que se declaran de la venta (`current_acounts.sale_id`), por su haber
         * entero.
         */
        $declaradas = CurrentAcount::whereIn('sale_id', $sale_ids)
                                    ->where('status', 'nota_credito')
                                    ->groupBy('sale_id')
                                    ->selectRaw('sale_id, SUM(haber) as total_nc')
                                    ->get();

        foreach ($declaradas as $fila) {
            $nc_por_venta[(int) $fila->sale_id] += (float) $fila->total_nc;
        }

        /*
         * Camino 2: las de monto libre, que nacen sin `sale_id` y llegan al débito de la venta
         * por la cola FIFO. Por lo que `pagado_por` dice que le imputaron A ESE DÉBITO.
         */
        foreach (self::notas_credito_imputadas_por_venta($sale_ids) as $sale_id => $imputado) {
            $nc_por_venta[(int) $sale_id] += $imputado;
        }

        foreach ($nc_por_venta as $sale_id => $nc_total) {

            $total = array_key_exists($sale_id, $totales) ? (float) $totales[$sale_id] : 0;

            $factores[$sale_id] = self::factor_de_nc($nc_total, $total);
        }

        return $factores;
    }

    /**
     * La cuenta del factor, aislada para que las dos formas no puedan divergir.
     *
     * @param  float  $nc_total
     * @param  float  $total
     * @return float
     */
    private static function factor_de_nc($nc_total, $total) {

        /*
         * Sin nota de crédito no hay nada que descontar, y se devuelve 1 SIN mirar el total de
         * la venta. Es a propósito: `sales.total` es nullable y hay flujos que lo dejan en
         * null o en 0 (presupuestos convertidos, ventas con el total todavía sin recalcular).
         * Con la fórmula aplicada siempre, una venta de total 0 daría factor 0 y se comería
         * puntos legítimos por un motivo que no tiene nada que ver con una devolución.
         */
        if ($nc_total <= self::DELTA) {
            return 1;
        }

        if ($total <= 0) {
            return 0;
        }

        $factor = 1 - ($nc_total / $total);

        if ($factor < 0) {
            return 0;
        }

        return $factor;
    }

    /**
     * Lo que las notas de crédito AJENAS a cada venta terminaron imputándole a su débito.
     *
     * Son las de monto libre: nacen sin `sale_id` y el motor de imputación las manda a saldar
     * la deuda más vieja del cliente, que puede ser esta venta. `pagado_por.pagado` es cuánto
     * de esa NC cayó en ESE débito; el resto cascadeó a otros y no tiene nada que ver acá.
     *
     * Se descartan las que tienen el `sale_id` de la venta que se está mirando, porque el
     * `SUM(haber)` del camino 1 ya las contó — y por eso la comparación va contra
     * `debe.sale_id` y no contra un id fijo: en la forma de a muchas, "la venta" es distinta
     * en cada fila.
     *
     * @param  array  $sale_ids
     * @return array  sale_id => pesos imputados.
     */
    private static function notas_credito_imputadas_por_venta($sale_ids) {

        $filas = DB::table('pagado_por')
                    ->join('current_acounts as haber', 'haber.id', '=', 'pagado_por.haber_id')
                    ->join('current_acounts as debe', 'debe.id', '=', 'pagado_por.debe_id')
                    ->whereIn('debe.sale_id', $sale_ids)
                    ->whereNull('debe.haber')
                    ->where('haber.status', 'nota_credito')
                    ->where(function ($q) {
                        $q->whereNull('haber.sale_id')
                            ->orWhereRaw('`haber`.`sale_id` != `debe`.`sale_id`');
                    })
                    ->groupBy('debe.sale_id')
                    ->selectRaw('`debe`.`sale_id` as sale_id, SUM(`pagado_por`.`pagado`) as imputado')
                    ->get();

        $por_venta = [];

        foreach ($filas as $fila) {
            $por_venta[(int) $fila->sale_id] = round((float) $fila->imputado, 2);
        }

        return $por_venta;
    }

    /**
     * Combina los dos tapones de la nota de crédito.
     *
     * 🔴 Se toma el MÍNIMO y no el producto, para no descontar dos veces cuando la NC sí traía
     * items: en ese caso `returned_amount` ya bajó la base neta Y la NC figura en el haber, así
     * que multiplicar los dos efectos dejaría la base por debajo de lo que corresponde. La
     * señal que dice "menos" gana, y ninguna de las dos se aplica sobre el resultado de la otra.
     *
     * @param  float  $base_neta   Base con las devoluciones por renglón ya descontadas.
     * @param  float  $base_bruta  Base sin descontar devoluciones.
     * @param  float  $factor_nc
     * @return float
     */
    static function aplicar_factor_nota_credito($base_neta, $base_bruta, $factor_nc) {

        $por_nota_credito = $base_bruta * $factor_nc;

        return $base_neta < $por_nota_credito ? $base_neta : $por_nota_credito;
    }

    /**
     * De pesos a puntos.
     *
     * `floor` y NO `round`: se redondea PARA ABAJO, que es lo que hace todo programa de puntos
     * y lo que no genera reclamos. Redondear para arriba haría que una compra de $999 con la
     * tasa "1 punto cada $1000" otorgue un punto, y el cliente que compre $1 menos la próxima
     * vez va a preguntar por qué.
     *
     * @param  float                        $base
     * @param  \App\Models\SistemaDePuntos  $sistema
     * @param  float                        $multiplicador
     * @return float
     */
    static function puntos_de_la_base($base, $sistema, $multiplicador = 1) {

        $puntos_cada = (float) $sistema->puntos_cada;

        /*
         * Guard de división por cero: la columna tiene default 1000.00, pero el ABM la deja
         * escribir y un 0 acá tiraría el guardado de la venta entera, que es el camino más
         * caliente del sistema. Sin tramo definido no se otorga nada.
         */
        if ($puntos_cada <= 0) {
            return 0;
        }

        $tramos = floor(($base + self::DELTA) / $puntos_cada);

        if ($tramos <= 0) {
            return 0;
        }

        $puntos = $tramos * (float) $sistema->puntos_por_tramo * (float) $multiplicador;

        return round($puntos, 2);
    }

    /**
     * El multiplicador de la lista, con el default resuelto.
     *
     * `null` en el pivot = se usa la tasa global del programa, o sea multiplicador 1. La
     * columna existe y la interfaz del MVP no la expone a propósito (hedge de la decisión de
     * Lucas del 21/8/2026); el motor la respeta desde el día uno para que exponerla después
     * sea un cambio de la SPA y nada más.
     *
     * @param  int    $price_type_id
     * @param  array  $listas
     * @return float
     */
    static function multiplicador_efectivo($price_type_id, $listas) {

        $price_type_id = (int) $price_type_id;

        if (!array_key_exists($price_type_id, $listas) || is_null($listas[$price_type_id])) {
            return 1;
        }

        return (float) $listas[$price_type_id];
    }

    /**
     * Normaliza a float tolerando null y string, que es como vienen los decimales del pivot.
     *
     * @param  mixed  $valor
     * @return float
     */
    private static function a_float($valor) {

        if (is_null($valor)) {
            return 0;
        }

        return (float) $valor;
    }
}
