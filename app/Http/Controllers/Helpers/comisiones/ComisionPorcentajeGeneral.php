<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

/**
 * Grupo 268 · Prompt 02 — motor de comision GENERICO por porcentaje, el camino por defecto para
 * cualquier cliente que no tenga uno de los cinco `comision_funcion` hardcodeados (ver el `else`
 * final de ComisionesHelper::crear_comision()).
 *
 * A diferencia de DistriCreoComision (que asume 10% si el vendedor no tiene percentage_commission
 * cargado), este motor NO inventa un porcentaje por defecto: sin percentage_commission cargado y
 * mayor a cero, no crea nada. Ese comportamiento de "10% por defecto" es propio de ese cliente y
 * no se generaliza.
 */
class ComisionPorcentajeGeneral {

    /**
     * Crea una sola comision sobre el TOTAL FINAL de la venta (con descuentos, recargos e IVA ya
     * aplicados — decision explicita de Lucas, 29/7/2026, no usar subtotales ni netos).
     *
     * Mision comision-vendedor-liquidacion-iva (14/9/2026): esa decision sigue firme como
     * comportamiento POR DEFECTO. La excepcion es un vendedor con `commission_with_iva = 0`: ahi
     * se resta del total el IVA de los articulos (ver total_iva_articulos()) antes de aplicar el
     * porcentaje, para que la comision se liquide sobre el neto.
     *
     * @param \App\Models\Sale $sale
     * @return void
     */
    function crear_comision($sale) {

        $seller = $sale->seller;

        if (is_null($seller) || (float)$seller->percentage_commission == 0) {
            // percentage_commission es decimal nullable: null, '0.00' o vacio caen todos aca via
            // el casteo a float. Sin porcentaje cargado, no se inventa uno (a diferencia de
            // DistriCreoComision).
            return;
        }

        $porcentaje_comision = $seller->percentage_commission;

        $moneda_id = $sale->moneda_id;
        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }

        $status = Helper::get_status($sale);

        $ct = new Controller();

        // Base de calculo: el total final de la venta, salvo que el vendedor tenga el check
        // desactivado explicitamente (columna NOT NULL, default 1: solo puede ser 1 o 0).
        $base_comision = $sale->total;

        if ((int)$seller->commission_with_iva === 0) {
            $base_comision -= Self::total_iva_articulos($sale);
        }

        $seller_commission = SellerCommission::create([
            'num'           => $ct->num('seller_commissions'),
            'seller_id'     => $sale->seller_id,
            'percentage'    => $porcentaje_comision,
            'sale_id'       => $sale->id,
            'moneda_id'     => $moneda_id,
            'debe'          => Numbers::redondear($base_comision * (float)$porcentaje_comision / 100),
            'status'        => $status,
            'liquidada_at'  => $status == 'active' ? now() : null,
            'description'   => 'Venta N°'.$sale->num,
            'user_id'       => $ct->userId(),
        ]);

        ComisionesHelper::recalcular_saldos($sale->seller_id, $moneda_id);

        Log::info('Se creo comision generica para sale_id '.$sale->id);
    }

    /**
     * Suma el IVA de cada renglon de ARTICULO vendido (tabla `article_sale`), usando el precio
     * unitario congelado en el pivot al momento de la venta (`price` con IVA, `price_sin_iva`
     * sin IVA) — no el IVA actual del articulo, que puede haber cambiado desde entonces. Es el
     * mismo dato ya usado por ContabilidadRepository::iibb_determinado() y por
     * PuntosBaseHelper::calcular_grupos() para el mismo problema (base neta de un renglon).
     *
     * Para un articulo Exento/No Gravado, `price_sin_iva` ya viene igual a `price`
     * (SaleHelper::get_price_sin_iva() no divide en esos casos), asi que la resta da 0 sola, sin
     * necesidad de mirar la alicuota de nuevo aca.
     *
     * No prorratea descuentos ni recargos GLOBALES de la venta (descuento %, discounts, recargo
     * con tarjeta) — misma limitacion, ya conocida y documentada, que tiene
     * ContabilidadRepository::iibb_determinado() para el mismo calculo.
     *
     * Servicios, combos y promociones de vinoteca NO participan: sus pivots no tienen
     * `price_sin_iva` ni `iva_percentage` (limitacion de schema ya documentada en
     * PuntosBaseHelper), asi que no se les puede sacar el IVA con datos congelados.
     *
     * @param \App\Models\Sale $sale
     * @return float
     */
    static function total_iva_articulos($sale) {

        $sale->loadMissing('articles');

        $iva_total = 0;

        foreach ($sale->articles as $articulo) {

            $pivot = $articulo->pivot;

            // Fallback a `price` para renglones viejos, previos a que existiera la columna
            // price_sin_iva: sin dato congelado, no se le inventa un IVA a restar.
            $price_sin_iva = is_null($pivot->price_sin_iva) ? (float)$pivot->price : (float)$pivot->price_sin_iva;

            $iva_total += ((float)$pivot->price - $price_sin_iva) * (float)$pivot->amount;
        }

        return $iva_total;
    }

}
