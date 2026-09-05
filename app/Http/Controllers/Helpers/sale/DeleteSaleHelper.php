<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosCanjeHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\CreditAccount;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class DeleteSaleHelper {


	/**
	 * Baja completa de una venta: es TODO lo que hace `SaleController@destroy`, en un solo lugar.
	 *
	 * 🔴 Vive aca y no adentro del controlador porque tiene DOS entradas: el `destroy()` del recurso
	 * (el usuario borra la venta a mano) y la cancelacion de un pedido online
	 * (`OrderController@update`, cuando el estado pasa a "Cancelado"). Duplicar esto en los dos
	 * lados garantiza que la proxima correccion entre en uno solo y los caminos se desincronicen
	 * sin que nada lo denuncie.
	 *
	 * ⚠️ Lo que este metodo hace es identico para las dos entradas, pero las entradas NO son
	 * identicas entre si, y conviene saberlo antes de tocar cualquiera:
	 *
	 *  - `destroy()` borra una venta facturada igual (solo se saltea la cuenta corriente si hay
	 *    nota de credito). La cancelacion de un pedido la frena antes con un 422.
	 *  - La cancelacion siempre pasa `compensar_caja = false`; `destroy()` lo lee del request.
	 *  - `destroy()` corre fuera de toda transaccion; la cancelacion corre adentro de la de
	 *    `update()`, asi que la notificacion de borrado sale ANTES del commit.
	 *
	 * El orden NO es intercambiable: la cuenta corriente, las comisiones y los puntos se deshacen
	 * ANTES del `delete()` (necesitan la venta viva para resolver sus relaciones), la compensacion
	 * de caja va DESPUES (usa la copia en memoria de los metodos de pago, tomada antes del delete)
	 * y `regresar_stock()` va al final.
	 *
	 * Lo que NO se mudo y sigue siendo del endpoint: leer `compensar_caja` del request y verificar
	 * que las cajas involucradas esten abiertas. Eso depende de un request y de poder responder un
	 * 422, cosas que un helper no tiene.
	 *
	 * @param  \App\Models\Sale  $model            Venta a dar de baja.
	 * @param  mixed             $instance         Controlador que dispara, para las notificaciones.
	 * @param  bool              $compensar_caja   Si crea los movimientos que revierten lo que la venta metio en caja.
	 * @param  mixed             $payment_methods  Copia en memoria de los metodos de pago, tomada ANTES del delete.
	 * @param  mixed             $helper_caja      Instancia de DeleteCajaCompensacionHelper, o null para crear una.
	 * @return bool  true si la venta se dio de baja; false si ya estaba eliminada y no se hizo nada.
	 */
	static function eliminar_venta($model, $instance, $compensar_caja = false, $payment_methods = null, $helper_caja = null) {

		/*
			🔴 Transaccion + candado sobre la fila de la venta (auditoria de stock, 5/9/2026).

			`destroy()` corria sin transaccion ni lock. Si el usuario apretaba "Eliminar" dos veces
			antes de que el primer pedido terminara (o el cliente HTTP reintentaba), los dos requests
			encontraban la venta viva con `Sale::find()` y los dos corrian `regresar_stock()`: cada
			articulo volvia al stock por duplicado. Medido en fenix el 4/9/2026: tres ventas, 22
			articulos inflados, corregidos a mano.

			El SELECT ... FOR UPDATE serializa los dos requests: el segundo espera aca hasta el commit
			del primero y recien entonces vuelve a preguntar si la venta sigue viva. Como `Sale` usa
			SoftDeletes, `where('id')` ya no la encuentra y la baja no se repite. Es el mismo candado
			con el que la confirmacion de pedidos y presupuestos cerro su propia carrera.

			Cuando ya hay una transaccion abierta (la cancelacion de un pedido corre adentro de la de
			`OrderController::update()`), Laravel anida con un savepoint y el lock se sostiene hasta
			el commit de afuera.
		*/
		return DB::transaction(function () use ($model, $instance, $compensar_caja, $payment_methods, $helper_caja) {

			$viva = Sale::where('id', $model->id)
						->lockForUpdate()
						->first();

			if (is_null($viva)) {

				Log::info('eliminar_venta: la venta id '.$model->id.' ya estaba eliminada. No se repite la baja ni se devuelve stock.');

				return false;
			}

			Self::ejecutar_baja($model, $instance, $compensar_caja, $payment_methods, $helper_caja);

			return true;
		});
	}

	/**
	 * El cuerpo de la baja, ya con el candado tomado. No llamar directo: entrar siempre por
	 * eliminar_venta().
	 *
	 * @param  \App\Models\Sale  $model
	 * @param  mixed             $instance
	 * @param  bool              $compensar_caja
	 * @param  mixed             $payment_methods
	 * @param  mixed             $helper_caja
	 * @return void
	 */
	static function ejecutar_baja($model, $instance, $compensar_caja, $payment_methods, $helper_caja) {

		if (is_null($helper_caja)) {
			$helper_caja = new DeleteCajaCompensacionHelper();
		}

		Log::info('Se quiere eliminar sale N° '.$model->num.'. id: '.$model->id.'. Por el empleado: '.Auth()->user()->name.', doc: '.Auth()->user()->doc_number);
		if (!is_null($model->client)) {
		    Log::info('Y pertenece al cliente '.$model->client->name);
		}

		$h = new ArticlePurchaseHelper();
		$h->borrar_article_purchase_actuales($model);
		
		if ($model->client_id) {

		    /* 
		        Si no es NULL, es porque se genero nota de credito de afip.
		        En ese caso, no se elimina la cuenta corriente de la venta
		        Porque ya tiene la nota de credito en la C/C
		    */ 
		    if (count($model->nota_credito_afip_tickets) == 0) {

		        SaleHelper::deleteCurrentAcountFromSale($model);
		    }

		    SaleHelper::deleteSellerCommissionsFromSale($model);

		    if (is_null($model->client->deleted_at)) {

		        // Busca la cuenta de crédito del cliente para la moneda de la venta
		        $credit_account = CreditAccount::where('model_name', 'client')
		                                            ->where('model_id', $model->client_id)
		                                            ->where('moneda_id', $model->moneda_id)
		                                            ->first();

		        // Verifica que la cuenta de crédito existe antes de validar saldos
		        if (!is_null($credit_account)) {
		            CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
		        } else {
		            Log::info('destroy sale '.$model->id.': el cliente '.$model->client_id.' no tiene credit account para la moneda '.$model->moneda_id.'. Se saltea el chequeo de saldos.');
		        }
		        $instance->sendAddModelNotification('client', $model->client_id, false);
		    }
		}

		/**
		 * Puntos para clientes. Los dos lados de la venta se deshacen ANTES del delete, por
		 * orden explícito y no dependiendo de que Sale use SoftDeletes: si mañana el borrado
		 * pasara a ser físico, un reconciliador que corriera después no tendría venta que leer.
		 *
		 *  - deshacer() devuelve los puntos que el cliente había canjeado en esta venta a los
		 *    lotes exactos de los que salieron (por eso existe movimiento_punto_consumos).
		 *  - revertir_venta() anula los lotes que esta venta otorgó y deja su movimiento
		 *    'revertidos'. No pregunta si corresponde: una venta borrada no otorga nada.
		 *
		 * Los dos salen sin tocar la base si el comercio no tiene la extensión.
		 *
		 * 🔴 `true` = CONSERVAR `sales.puntos_canjeados` y `sales.descuento_puntos`. Esta venta
		 * se va a la papelera con su `total` YA neteado por el canje, y esas dos columnas son
		 * lo único que explica ese número: el movimiento de puntos se borra de verdad, no es un
		 * soft-delete. Limpiarlas acá dejaba una venta restaurable cuyo descuento no se podía
		 * reconstruir, y el cliente terminaba con los puntos Y con el descuento. Ver el
		 * docblock de PuntosCanjeHelper::deshacer() y su contraparte, restaurar().
		 */
		PuntosCanjeHelper::deshacer($model, true);

		PuntosAcumulacionHelper::revertir_venta($model);

		$model->delete();

		if ($compensar_caja && ! is_null($payment_methods) && $payment_methods->count()) {
		    $helper_caja->crear_movimientos_compensacion(
		        $payment_methods,
		        DeleteCajaCompensacionHelper::MODEL_TYPE_SALE,
		        null,
		        'Eliminación de venta N° '.$model->num,
		        $model->id
		    );
		}

		$instance->sendDeleteModelNotification('sale', $model->id);

		Self::regresar_stock($model);
	}

	/**
	 * Devuelve al stock lo que la venta REALMENTE le sacó, leyendo su propio libro de movimientos.
	 *
	 * 🔴 Hasta la auditoría de stock (5/9/2026) se reponía la cantidad del renglón (`pivot->amount`,
	 * menos lo devuelto por NC). Eso reponía cosas que nunca se habían descontado, y la auditoría
	 * las midió en producción:
	 *
	 *  - renglones agregados cuando el artículo todavía no llevaba stock (stock NULL: no hubo
	 *    "Venta"), y que al borrar la venta —con el artículo ya con stock— volvían enteros
	 *    (Ferretotal 18000: +20 y +3 fantasma; FerreMas: 26 ventas borradas el 5/8 con 49
	 *    renglones inflados);
	 *  - devoluciones hechas desde Devoluciones sin "actualizar unidades devueltas":
	 *    `returned_amount` quedaba en NULL, y al borrar la venta lo devuelto volvía por segunda
	 *    vez;
	 *  - la NC del panel de Vender, que quedaba etiquetada "Ingreso manual" y por eso no se
	 *    restaba.
	 *
	 * Con el libro no hay nada que adivinar: por cada (artículo, variante) se suma todo lo que
	 * la venta movió (ventas, ajustes, renglones sacados, notas de crédito, combos) y se crea UN
	 * movimiento "Se elimino la venta" que lo deja en cero. Una venta que nunca descontó (to_check,
	 * discount_stock en 0, artículo sin stock) no tiene nada en el libro y no repone nada; una que
	 * descontó dos veces por un doble envío repone las dos. Después del borrado, el neto de la
	 * venta en `stock_movements` es 0 siempre.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @return void
	 */
	static function regresar_stock($sale) {

        /*
            Se repone lo que el libro de la venta dice que se desconto. Una venta de antes del
            libro (octubre de 2023), sin ningun movimiento, no repone nada al borrarse: su stock
            se reconto y se importo muchas veces desde entonces, y sumarle hoy lo que vendio hace
            anios es exactamente la clase de inflado que la auditoria del 5/9/2026 encontro.
        */
        foreach (Self::neto_por_renglon($sale) as $renglon) {

            $article = Article::find($renglon->article_id);

            // Artículo borrado, inexistente o que dejo de llevar stock: no hay stock que devolver
            // (mismo criterio que tenia el borrado por renglon con !is_null($article->stock)).
            if (is_null($article) || is_null($article->stock)) {
                continue;
            }

            $data = [
                'model_id'                      => $article->id,
                'amount'                        => -(float)$renglon->neto,
                'sale_id'                       => $sale->id,
                'article_variant_id'            => $renglon->article_variant_id,
                'concepto_stock_movement_name'  => 'Se elimino la venta',
            ];

            if (count($article->addresses) >= 1) {
                $data['to_address_id'] = $sale->address_id;
            }

            $ct = new StockMovementController();
            $ct->crear($data, false);
        }

        // El stock de una promoción no pasa por stock_movements: se sigue reponiendo por su renglón.
        if (!$sale->to_check && !$sale->checked && $sale->discount_stock) {

            foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {

                $promocion_vinoteca->stock += $promocion_vinoteca->pivot->amount;
                $promocion_vinoteca->save();
            }
        }
	}

	/**
	 * Neto de movimientos de stock de la venta por (artículo, variante), sólo los que no dan cero.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @return \Illuminate\Support\Collection  Objetos con article_id, article_variant_id y neto.
	 */
	static function neto_por_renglon($sale) {

        return DB::table('stock_movements')
                    ->select('article_id', DB::raw('COALESCE(NULLIF(article_variant_id, 0), NULL) AS article_variant_id'), DB::raw('SUM(amount) AS neto'))
                    ->where('sale_id', $sale->id)
                    ->whereNotNull('article_id')
                    ->groupBy('article_id', DB::raw('COALESCE(NULLIF(article_variant_id, 0), NULL)'))
                    ->havingRaw('ABS(SUM(amount)) > 0.0001')
                    ->get();
	}

    static function get_unidades_ya_devueltas_en_nota_de_credito($sale, $article) {

        $unidades_ya_devueltas = 0;

        $concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

        $stock_movement_nota_credito = StockMovement::where('article_id', $article->id)
                                                    ->where('concepto_stock_movement_id', $concepto->id)
                                                    ->where('sale_id', $sale->id);
        if (!is_null($article->pivot->article_variant_id)) {
            $stock_movement_nota_credito = $stock_movement_nota_credito->where('article_variant_id', $article->pivot->article_variant_id);
        }
             
        $stock_movement_nota_credito = $stock_movement_nota_credito->get();

        foreach ($stock_movement_nota_credito as $stock_movement) {
            
            $unidades_ya_devueltas += $stock_movement->amount;
        }

        return $unidades_ya_devueltas;
    }
}