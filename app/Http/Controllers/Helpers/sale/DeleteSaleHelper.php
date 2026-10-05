<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
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
	 * Devuelve el motivo por el que una venta NO se puede eliminar, o null si se puede.
	 *
	 * Gemelo de `SaleHelper::motivo_por_el_que_no_se_puede_editar()`, pero para la baja. Lo pregunta
	 * `SaleController::destroy()` antes de tocar nada (antes incluso de mirar las cajas).
	 *
	 * 🔴 Por que existe (mision venta-facturada-no-se-borra, 5/10/2026). En la demo `demo` (4.3.6),
	 * `DELETE api/sale/375?compensar_caja=1` sobre una venta con la Factura B N° 140 autorizada
	 * devolvio 200 y la dejo con `deleted_at`. No es cosmetico: el Libro IVA
	 * (`AfipController::comprobantes_del_libro_iva_ventas()`) y los TXT arman la lista con
	 * `AfipTicket::whereHas('sale')`, asi que al borrar la venta su factura desaparece del sistema
	 * mientras sigue vigente en ARCA. Es el mismo sintoma que el de masquito
	 * (informe 20260911-bloquear-borrado-afip-ticket-con-cae), que se cerro del lado del ticket pero
	 * no del lado de la venta.
	 *
	 * La SPA ya esconde "Eliminar" en una venta con tickets (`SaleModal.vue::show_btn_delete`), pero
	 * eso no alcanza: el borrado masivo y la baja generica del asistente llegan igual a `destroy()`.
	 *
	 * Lo que frena, en este orden (decisiones de Lucas del 5/10/2026):
	 *
	 *  1. CUALQUIER `AfipTicket` vivo de la venta, tenga CAE o no. Es el criterio conservador, el
	 *     mismo que la SPA y que `BudgetController::anular()`: un intento sin respuesta puede terminar
	 *     autorizado en ARCA, y entonces la factura quedaria huerfana. El mensaje cambia segun haya
	 *     CAE (se anula con nota de credito) o no (primero se resuelve la factura). `AfipTicket` usa
	 *     SoftDeletes, asi que un intento ya eliminado desde la factura no cuenta.
	 *     Una factura con CAE que ya tiene su nota de credito TAMBIEN frena: borrar la venta saca del
	 *     Libro IVA la factura y la NC, y las dos siguen en ARCA.
	 *  2. Una venta ORIGINAL incluida en una factura consolidada: el comprobante lo tiene la venta
	 *     contenedora (`consolidacion_facturacion_id`), no ella. Si la contenedora sigue viva y tiene
	 *     algun ticket, se frena con un mensaje que la nombra.
	 *
	 * ⚠️ NO mira `is_cerrada`, a proposito: cerrar una venta congela la edicion, no el borrado
	 * (decision de Lucas). Lo fija `tests/Feature/Facturacion/10_Destroy_Venta_Facturada_Bloquea_Test`.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @return string|null
	 */
	static function motivo_por_el_que_no_se_puede_eliminar($sale) {

		$facturacion = Self::estado_de_facturacion($sale);

		if ($facturacion === 'con_cae') {

			return 'La venta tiene factura autorizada: para anularla, hacé una devolución con nota de crédito.';
		}

		if ($facturacion === 'sin_cae') {

			return 'La venta tiene una factura sin CAE (rechazada o sin respuesta de ARCA). Consultala o eliminala desde la factura de la venta, y después borrá la venta.';
		}

		if (!is_null($sale->consolidacion_facturacion_id)) {

			// Solo la contenedora viva: si ya se borro, su factura no la sostiene esta venta.
			$consolidada = Sale::find($sale->consolidacion_facturacion_id);

			if (!is_null($consolidada)) {

				$num = !is_null($consolidada->num) ? $consolidada->num : $consolidada->id;

				$facturacion_de_la_consolidada = Self::estado_de_facturacion($consolidada);

				if ($facturacion_de_la_consolidada === 'con_cae') {

					return 'La venta está incluida en la factura de la venta consolidada N° '.$num.': para anularla, hacé una devolución con nota de crédito sobre esa venta.';
				}

				if ($facturacion_de_la_consolidada === 'sin_cae') {

					return 'La venta está incluida en la venta consolidada N° '.$num.', que tiene una factura sin CAE. Resolvé esa factura antes de borrar esta venta.';
				}
			}
		}

		return null;
	}

	/**
	 * Que tan facturada esta una venta segun sus `AfipTicket` vivos: 'con_cae' si alguno tiene CAE,
	 * 'sin_cae' si tiene tickets pero ninguno con CAE, null si no tiene ninguno.
	 *
	 * "Tiene CAE" es no nulo y distinto de '': el mismo criterio que
	 * `SaleHelper::motivo_por_el_que_no_se_puede_editar()` y `AfipTicketController::destroy()`.
	 * Nunca `is_null($cae)` a secas, que tomaria un `''` como CAE.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @return string|null
	 */
	static function estado_de_facturacion($sale) {

		$sale->loadMissing('afip_tickets');

		$tiene_tickets = false;

		foreach ($sale->afip_tickets as $afip_ticket) {

			if (!is_null($afip_ticket->cae) && $afip_ticket->cae !== '') {

				return 'con_cae';
			}

			$tiene_tickets = true;
		}

		return $tiene_tickets ? 'sin_cae' : null;
	}

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
	 *  - Las dos frenan antes una venta facturada, pero con criterios distintos: `destroy()` con
	 *    `motivo_por_el_que_no_se_puede_eliminar()` (cualquier ticket, tenga o no nota de credito,
	 *    desde el 5/10/2026) y la cancelacion de un pedido solo si la factura no tiene NC. Por eso
	 *    el salteo de la cuenta corriente cuando hay nota de credito, mas abajo, en la practica
	 *    solo lo alcanza la cancelacion.
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

			/*
				🔴 Candado de la cuenta corriente del cliente, después del de la venta (el mismo orden
				que la edición: venta, después cuenta). Misión cuenta-corriente-carrera-y-velocidad,
				23/9/2026: la baja saca el movimiento de la venta y recalcula la cadena del cliente, y
				sin esto podía correr a la vez que otra escritura sobre la misma cuenta. Ver
				CuentaCorrienteLock.
			*/
			CuentaCorrienteLock::bloquear('client', $viva->client_id);

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

		    /** Cuenta de la que salió el movimiento de la venta, si salió de alguna. */
		    $credit_account_id_del_movimiento = null;

		    /** Cuenta que ya recalculó el camino de siempre (cliente + moneda de la venta), si hubo. */
		    $credit_account_id_recalculada = null;

		    /*
		        Si no es NULL, es porque se genero nota de credito de afip.
		        En ese caso, no se elimina la cuenta corriente de la venta
		        Porque ya tiene la nota de credito en la C/C
		    */
		    if (count($model->nota_credito_afip_tickets) == 0) {

		        $credit_account_id_del_movimiento = SaleHelper::deleteCurrentAcountFromSale($model);
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

		        if (!is_null($credit_account)) {
		            $credit_account_id_recalculada = $credit_account->id;
		        }
		    }

		    /*
		        🔴 La cuenta de la que salió el movimiento se recalcula SIEMPRE (misión
		        cuenta-corriente-carrera-y-velocidad, 23/9/2026), aunque no sea la de (cliente, moneda
		        de la venta) —un movimiento que quedó en otra cuenta, o una venta sin moneda— y aunque
		        el cliente esté borrado: perdió un débito en el medio de su cadena, y si nadie la
		        recalcula queda cortada desde ahí.
		    */
		    if (!is_null($credit_account_id_del_movimiento) && $credit_account_id_recalculada != $credit_account_id_del_movimiento) {

		        CurrentAcountHelper::check_saldos_y_pagos($credit_account_id_del_movimiento);
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
                'article_variant_id'            => $renglon->variant_id_neto,
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
	 * 🔴 El GROUP BY va por POSICIÓN (`1, 2`) y no repitiendo la expresión, y eso no es estilo:
	 * el hosting compartido corre MariaDB (11.8.8, medido en Innovate el 8/9/2026), y MariaDB
	 * NO compara expresiones entre el SELECT y el GROUP BY bajo ONLY_FULL_GROUP_BY. Repetir
	 * `COALESCE(NULLIF(article_variant_id, 0), NULL)` idéntica en los dos lados igual tira
	 * 1055 "isn't in GROUP BY", y toda venta con movimientos de stock quedaba sin poder
	 * borrarse. El modo lo pone Laravel mismo (`'strict' => true`), no el hosting: MySQL local
	 * lo tolera y MariaDB no, así que esto NO se reproduce en los tests del pool.
	 *
	 * ⚠️ Si se agrega una columna al SELECT, va DESPUÉS de las dos primeras o hay que corregir
	 * las posiciones.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @return \Illuminate\Support\Collection  Objetos con article_id, variant_id_neto y neto.
	 */
	static function neto_por_renglon($sale) {

        return DB::table('stock_movements')
                    ->select('article_id', DB::raw('COALESCE(NULLIF(article_variant_id, 0), NULL) AS variant_id_neto'), DB::raw('SUM(amount) AS neto'))
                    ->where('sale_id', $sale->id)
                    ->whereNotNull('article_id')
                    ->groupByRaw('1, 2')
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