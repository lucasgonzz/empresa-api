<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosCanjeHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Models\ConceptoStockMovement;
use App\Models\CreditAccount;
use App\Models\StockMovement;
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
	 * @return void
	 */
	static function eliminar_venta($model, $instance, $compensar_caja = false, $payment_methods = null, $helper_caja = null) {

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

	static function regresar_stock($sale) {

        // Solo se revierte el stock si la venta estaba configurada para descontarlo
        if (!$sale->to_check && !$sale->checked && $sale->discount_stock) {

            foreach ($sale->articles as $article) {
                if (!is_null($article->stock)) {

                    $amount = $article->pivot->amount;
                    $amount -= self::get_unidades_ya_devueltas_en_nota_de_credito($sale, $article);
                    
                    ArticleHelper::resetStock($article, $amount, $sale);
                }
            }

            foreach ($sale->combos as $combo) {
                
                foreach ($combo->articles as $article) {
                    
                    if (!is_null($article->stock)) {

                        $amount = $combo->pivot->amount * $article->pivot->amount;
                        ArticleHelper::resetStock($article, $amount, $sale);
                    }
                }
            }

            foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {

                $promocion_vinoteca->stock += $promocion_vinoteca->pivot->amount;
                $promocion_vinoteca->save();
            }
        }
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