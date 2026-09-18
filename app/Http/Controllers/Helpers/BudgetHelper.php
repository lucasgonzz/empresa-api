<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Budget\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Http\Controllers\Helpers\sale\ForzarTotalEsquemaHelper;
use App\Http\Controllers\Helpers\sale\ComboHelper;
use App\Http\Controllers\Helpers\sale\PromocionVinotecaHelper;
use App\Http\Controllers\Helpers\sale\SaleTotalesHelper;
use App\Http\Controllers\SaleController;
use App\Models\Article;
use App\Models\Budget;
use App\Models\CurrentAcount;
use App\Models\OrderProduction;
use App\Models\Sale;
use App\Notifications\BudgetCreated;
use App\Notifications\CreatedSale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BudgetHelper {

	static function sendMail($budget, $send_mail) {
		if ($send_mail == 1 && $budget->client->email != '') {
			$budget->client->notify(new BudgetCreated($budget));
		}
	}

	static function checkStatus($budget, $previus_articles) {
		Self::deleteCurrentAcount($budget);
	    Self::deleteSale($budget);
		if ($budget->budget_status->name == 'Confirmado') {

	        Self::saveSale($budget, $previus_articles);
		} 
	    // CurrentAcountHelper::checkSaldos('client', $budget->client_id);
	}

	static function saveSale($budget, $previus_articles) {
		if (is_null($budget->sale)) {
	        $ct = new Controller();

	        /*
	         * Lo que la venta nacida de un presupuesto se lleva IGUAL que una venta de VENDER
	         * (tanda 2 de la mision vender-lista-obligatoria, 18/9/2026, item A3). Hasta hoy este
	         * INSERT dejaba en el default de la columna tres cosas que `SaleController::store()`
	         * si resuelve:
	         *
	         *  - `seller_id`: quedaba null, asi que la venta no tenia vendedor y no habia comision
	         *    por este camino, aunque el cliente tuviera vendedor asignado. Se resuelve con la
	         *    MISMA regla que el alta (`SaleHelper::get_seller_id_desde()`: cliente → empleado
	         *    que confirma → 0), y mas abajo se crea la comision como hace `attachProperies()`.
	         *    Un presupuesto no elige vendedor, por eso el primer argumento va en null.
	         *  - `terminada_at`: quedaba null con `terminada = 1`. Mismo criterio que el alta
	         *    (`SaleHelper::get_terminada()` / `get_terminada_at()`): sin la extension
	         *    `check_sales` la venta nace terminada y fechada ahora; con ella nace `to_check`,
	         *    sin terminar y sin fecha. Un presupuesto no tiene fecha de entrega.
	         *  - `valor_dolar`: no se copiaba del presupuesto; la venta perdia la cotizacion con la
	         *    que se preciaron sus renglones.
	         */
	        $to_check = UserHelper::hasExtencion('check_sales') ? 1 : 0;

	        $employee_id = SaleHelper::getEmployeeId();

	        $sale = Sale::create(ForzarTotalEsquemaHelper::agregar_al_payload([
	            'num' 					=> $ct->num('sales'),
	            'user_id' 				=> UserHelper::userId(),
	            'client_id' 			=> $budget->client_id,
	            'budget_id' 			=> $budget->id,
	            'observations' 			=> $budget->observations,
	            'total' 				=> $budget->total,
	            'address_id' 			=> $budget->address_id,
	            // Pesos si el presupuesto no tiene moneda (item A6, 18/9/2026): `sales.moneda_id` es
	            // nullable y con null ninguna cotizacion aplica. Mismo default que SaleController.
	            'moneda_id' 			=> $budget->moneda_id ? $budget->moneda_id : 1,
	            'discounts_in_services'	=> $budget->discounts_in_services,
	            'surchages_in_services'	=> $budget->surchages_in_services,
	            // La venta que nace del presupuesto se lleva la opcion: sus articulos ya vienen con
	            // el recargo adentro del precio, asi que SaleHelper::getTotalSale() tampoco lo tiene
	            // que volver a sumar (pedido explicito de Lucas).
	            'aplicar_recargos_directo_a_items' => $budget->aplicar_recargos_directo_a_items,
            	'price_type_id'         => Self::get_price_type_id($budget),
            	'sale_status_id'        => $budget->sale_status_id,
            	// Misma semántica que en SaleController: si no viene definido en el presupuesto, descontar stock por defecto.
            	'discount_stock'        => !is_null($budget->discount_stock) ? ($budget->discount_stock ? 1 : 0) : 1,
            	'iva_aplicado'          => !is_null($budget->iva_aplicado) ? ($budget->iva_aplicado ? 1 : 0) : 1,
            	'employee_id'           => $employee_id,
            	'seller_id'             => SaleHelper::get_seller_id_desde(null, $budget->client_id, $employee_id),
            	'valor_dolar'           => $budget->valor_dolar,
	            'save_current_acount' 	=> Self::get_guardar_cuenta_corriente($budget),
	            'to_check'				=> $to_check,
	            'terminada'				=> SaleHelper::get_terminada($to_check, null),
	            'terminada_at'			=> SaleHelper::get_terminada_at($to_check, null),
	            /*
	             * Se arrastra tal cual, y desde la tanda 2 de la mision vender-lista-obligatoria
	             * (18/9/2026, item A4) el presupuesto lo tiene guardado de verdad: hasta entonces
	             * `BudgetController` no lo persistia y aca llegaba siempre el 0 del default, asi que
	             * la venta nunca omitia la cuenta corriente aunque el vendedor lo hubiera tildado.
	             * Quien lo respeta es `SaleHelper::va_a_volver_a_la_cuenta_corriente()`
	             * (`save_current_acount && !omitir_en_cuenta_corriente`), que lee
	             * `create_current_acount()` mas abajo: `get_guardar_cuenta_corriente()` decide solo
	             * `save_current_acount`, y con el omitir en 1 la venta no entra a la cuenta.
	             */
                'omitir_en_cuenta_corriente'        => $budget->omitir_en_cuenta_corriente,
	        /*
	         * El monto del total forzado viaja del presupuesto a la venta (mision
	         * forzar-total-por-monto, 17/9/2026).
	         *
	         * 🔴 VA JUNTO CON `total`, EN LA MISMA LINEA CONCEPTUAL. El total del presupuesto ya es
	         * el forzado; si la venta se llevara el total pero no el monto, nadie podria volver a
	         * explicar de donde sale ese numero: el comprobante no tendria renglon de ajuste, el
	         * prorrateo de AFIP facturaria el total sin forzar y cualquier recalculo del back
	         * (`getTotalSale()` al confirmar una venta chequeada) pisaria el total con la suma
	         * pelada de los renglones.
	         *
	         * ⚠️ Y ENTRA POR LA GUARDA DE ESQUEMA. Confirmar un presupuesto no es un caso borde: es
	         * mostrador normal, y es el MISMO circuito que `develop` tapo el 16/9 con la guarda de
	         * `budget_combo`. En la ventana en la que el codigo esta y la columna no, este
	         * `$budget->forzar_total_monto` devuelve null sin error —el atributo no existe— y ese
	         * null viaja igual al INSERT, que revienta con `Unknown column`. Ver
	         * `ForzarTotalEsquemaHelper`.
	         */
	        ], $budget->forzar_total_monto, 'sales'));
	        Self::attachSaleArticles($sale, $budget, $previus_articles);

	        Self::attachSaleServices($sale, $budget);

	        Self::attachSalePromocionVinotecas($sale, $budget);

	        Self::attachSaleCombos($sale, $budget);

	        Self::attachSaleDiscountsAndSurchages($sale, $budget);

	        /*
	            🔴 EL `sub_total` DE LA VENTA NACIDA DE UN PRESUPUESTO (mision forzar-total-por-monto,
	            17/9/2026).

	            Hasta hoy `saveSale()` NO escribia esta columna: `sales.sub_total` se escribe solo en
	            `SaleController` (alta y actualizacion), desde el request de VENDER. Una venta nacida
	            de un presupuesto quedaba con `sub_total` en null, y nadie se enteraba porque nadie lo
	            leia.

	            Lo leen los comprobantes, y esta mision los hizo leerlo de verdad. Con null adentro,
	            el ticket de 80mm arranca `total_sale` en 0 e imprime "Total $0", despues
	            "Ajuste -$12   $-12" y despues "Total sin descuentos: $-12"; y la factura A/B imprime
	            "Total Original: $0". O sea: tres renglones sin sentido en EL comprobante del caso de
	            uso, justo en el camino que esta misma mision habilito al arrastrar el monto del
	            presupuesto a la venta.

	            Se calcula con `SaleHelper::get_sub_total()`, que suma los renglones ya adjuntados
	            —articulos, combos, promociones y servicios, con el descuento por linea aplicado— y
	            NO aplica ni descuentos ni recargos de venta ni el forzado. Es exactamente la misma
	            definicion que manda VENDER en el alta, que es lo que hace que el desglose del
	            comprobante cierre: sub_total menos los renglones del medio da el total.

	            ⚠️ Va DESPUES de adjuntar los cuatro tipos de item: antes, la venta todavia no tiene
	            renglones y la suma daria 0.
	        */
	        $sale->sub_total = SaleHelper::get_sub_total($sale);
	        $sale->save();

	        if (!$sale->to_check) {
	        	SaleHelper::create_current_acount($sale);

	        	/*
	        	 * La comision del vendedor, en el mismo orden que `SaleHelper::attachProperies()`
	        	 * para una venta de VENDER: despues del movimiento de cuenta corriente, porque el
	        	 * motor de Fenix pregunta por `$sale->current_acount` y el estado de la comision
	        	 * (`comisiones\Helper::get_status()`) depende de si la venta entro a la cuenta.
	        	 * Hasta hoy (item A3) no se llamaba, y como ademas `seller_id` quedaba null, un
	        	 * presupuesto confirmado nunca generaba comision. Sin vendedor (0) es un no-op.
	        	 */
	        	SaleHelper::crear_comision($sale);
	        }

	        SaleTotalesHelper::set_total_cost($sale);

	        /*
	         * La ganancia de la venta se persiste igual que en el camino normal
	         * (`SaleHelper::updateOrCreate()`: set_total_cost y enseguida set_sale_ganancia). Hasta
	         * el 17/9/2026 acá solo se llamaba a set_total_cost, asi que TODA venta nacida de un
	         * presupuesto se quedaba con `sales.ganancia` en NULL hasta que algo la tocara.
	         */
	        SaleHelper::set_sale_ganancia($sale);


	        $sale->load('articles');
		    $h = new ArticlePurchaseHelper();
		    $h->set_article_purcase($sale);

        	$ct->sendAddModelNotification('Sale', $sale->id, false);
		}
	}

	/**
	 * La lista de precios con la que nace la venta al confirmar: la del presupuesto, o la del
	 * cliente, o ninguna.
	 *
	 * ⚠️ Un presupuesto viejo sin lista —guardado antes de la mision vender-lista-obligatoria
	 * (17/9/2026), o de una cuenta que no trabaja con listas— confirma con null A PROPOSITO, y aca
	 * no se le exige lista: sus renglones ya se preciaron asi cuando se guardo
	 * (`article_budget.price` viaja tal cual a `article_sale.price` en attachSaleArticles()), y
	 * ponerle una lista ahora diria que la venta se cobro con precios que nadie aplico. La
	 * obligatoriedad vive en el alta y en la edicion (`BudgetController` + `PriceTypeHelper`), que
	 * es donde se eligen los precios.
	 *
	 * 🔴 Y el 0 se lee como "ninguna" en las DOS puntas, presupuesto y cliente, con el mismo
	 * resolvedor que usa el alta: `clients.price_type_id` nace en 0 desde el form generico de
	 * clientes (`src/models/client.js`, `ClientController` lo guarda pelado) y un presupuesto viejo
	 * puede traer 0 por el mismo camino. Hasta el 17/9/2026 esto preguntaba `!is_null` y un 0
	 * pasaba como si fuera una lista: la venta nacia con `price_type_id = 0`, que ningun lector
	 * distingue de "sin lista" pero que tampoco cae al cliente.
	 *
	 * @param  \App\Models\Budget  $budget
	 * @return int|null
	 */
	static function get_price_type_id($budget) {

		return PriceTypeHelper::resolver_price_type_id_para_guardar($budget->price_type_id, $budget->client);
	}

	static function get_guardar_cuenta_corriente($budget) {
		if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')) {
			if (!is_null($budget->client) && !$budget->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
				return false;
			}
		}
		return true;		
	}

	static function attachSaleArticles($sale, $budget, $previus_articles) {
		
		$has_extencion_check_sales = UserHelper::hasExtencion('check_sales');
		
		foreach($budget->articles as $article) {
			Log::info('Adjuntando '.$article->name.' a la nueva venta');
			Log::info('amount: '.$article->pivot->amount);
			Log::info('cost: '.$article->pivot->cost);
			Log::info('price: '.$article->pivot->price);
			
			$cost = $article->pivot->cost;
			$price = $article->pivot->price;
			$amount = $article->pivot->amount;

			/*
			 * 🔴 `article_sale.cost` es UNITARIO y `article_sale.ganancia` es el TOTAL de la linea:
			 * la convencion la fijan SaleHelper::attachArticle(), SaleTotalesHelper::set_total_cost()
			 * y ContabilidadRepository::costo_mercaderia_vendida(). Hasta el 17/9/2026 acá se
			 * guardaba (price − cost) SIN multiplicar por la cantidad, asi que TODA venta nacida de
			 * un presupuesto tenia la ganancia de linea dividida por la cantidad. Y el presupuesto
			 * es el camino dominante de las ventas en ferretotal.
			 */
        	$ganancia = ((float)$price - (float)$cost) * (float)$amount;

			$sale->articles()->attach($article->id, [
				'amount'			=> $amount,
				'checked_amount'	=> Self::get_checked_amount($has_extencion_check_sales, $article),
				'price'	    		=> $price,
				'cost'	    		=> $cost,
				'ganancia'	    	=> $ganancia,
				'price_type_personalizado_id'	    		=> $article->pivot->price_type_personalizado_id,
				'discount'			=> $article->pivot->bonus,
				'name'				=> $article->pivot->name,
			]);

			Log::info('sale articles:');
			Log::info($sale->articles);

			// Solo descontar stock de artículos si la venta lleva discount_stock (como en flujo de SaleHelper).
			if (!$has_extencion_check_sales && (bool) $sale->discount_stock) {

            	ArticleHelper::discountStock($article->id, $article->pivot->amount, $sale, [], false, null);
			}
		}
	}

	static function attachSalePromocionVinotecas($sale, $budget) {

		foreach($budget->promocion_vinotecas as $promo) {
			
			$sale->promocion_vinotecas()->attach($promo->id, [
				'amount'			=> $promo->pivot->amount,
				'price'	    		=> $promo->pivot->price,
			]);

			$promo_array = [
				'id'		=> $promo->id,
				'amount'	=> $promo->pivot->amount,
			];

			if ((bool) $sale->discount_stock) {
				PromocionVinotecaHelper::discount_stock_promocion_vinoteca($sale, $promo_array);
			}
		}
	}

	/**
	 * Pasa los combos del presupuesto a la venta que nace al confirmarlo
	 * (mision combos-y-rangos-de-precio, 16/9/2026).
	 *
	 * 🔴 ACA ES DONDE EL MOLDE DE `promocion_vinoteca` NO SE COPIA, y no es un detalle de estilo.
	 * `attachSalePromocionVinotecas()` descuenta el stock de la promo MISMA
	 * (`promocion_vinotecas.stock`), porque una promo de vinoteca es un articulo virtual con stock
	 * propio. Un combo no tiene stock: es una receta. Lo que se descuenta es el stock de CADA
	 * articulo componente, multiplicado por la cantidad de combos.
	 *
	 * Ese descuento ya existe y es el mismo que usa VENDER: `sale\ComboHelper::discount_articles_stock()`,
	 * llamado desde `SaleHelper::attachCombos()`. Se reusa tal cual —no se escribe un descuento
	 * nuevo— justamente para que confirmar un presupuesto y guardar una venta muevan el stock de la
	 * misma manera. Dos implementaciones del mismo descuento es la receta para que la auditoria de
	 * stock no cierre por un lado y si por el otro.
	 *
	 * El helper espera el renglon del combo TAL COMO LLEGA DE VENDER, o sea un array con
	 * `articles[].pivot.amount`, no un modelo Eloquent. Por eso se traduce acá: es el unico lugar
	 * donde el combo viene de la base (relacion `combos.articles` del presupuesto) en vez de venir
	 * del payload.
	 *
	 * El gate de `discount_stock` NO se repite acá: `discount_articles_stock()` ya mira
	 * `!$sale->to_check && !$sale->checked && (bool)$sale->discount_stock`. Duplicarlo afuera es
	 * pedir que un dia los dos chequeos se desincronicen.
	 *
	 * `$previus_combos` va en null a proposito: la venta se acaba de crear en `saveSale()`, asi que
	 * no hay cantidad previa contra la cual calcular una diferencia.
	 *
	 * 🔴 Los combos salen por `ComboEsquemaHelper` y no por `$budget->combos`: en un cliente que
	 * todavia no corrio la migracion de `budget_combo`, tocar la relacion aca dejaria sin poder
	 * CONFIRMAR ningun presupuesto, que es lo que le da la venta al comercio.
	 *
	 * @param  \App\Models\Sale    $sale
	 * @param  \App\Models\Budget  $budget
	 * @return void
	 */
	static function attachSaleCombos($sale, $budget) {

		foreach (ComboEsquemaHelper::combos_del_presupuesto($budget) as $combo) {

			/*
				`created_at` a mano, igual que su gemelo `SaleHelper::attachCombos()`
				(SaleHelper.php:1304). `Sale::combos()` NO declara `withTimestamps()`, asi que
				Eloquent no escribe la columna solo: los combos que entraban por confirmacion de
				presupuesto quedaban con `combo_sale.created_at` en NULL y los que entraban por
				VENDER no. Dos filas de la misma tabla, una fechada y la otra no, segun por que
				puerta entro la venta.
			*/
			$sale->combos()->attach($combo->id, [
				'amount'			=> $combo->pivot->amount,
				'price'	    		=> $combo->pivot->price,
				'created_at'		=> Carbon::now(),
			]);

			$articles_array = [];

			foreach ($combo->articles as $article) {

				$articles_array[] = [
					'id'		=> $article->id,
					'pivot'		=> [
						'amount'	=> $article->pivot->amount,
					],
				];
			}

			$combo_array = [
				'id'		=> $combo->id,
				'name'		=> $combo->name,
				'amount'	=> $combo->pivot->amount,
				'articles'	=> $articles_array,
			];

			ComboHelper::discount_articles_stock($sale, $combo_array, null);
		}
	}

	static function attachSaleServices($sale, $budget) {
		
		foreach($budget->services as $service) {
			
			$sale->services()->attach($service->id, [
				'amount'			=> $service->pivot->amount,
				'price'	    		=> $service->pivot->price,
			]);

		}
	}

	static function get_checked_amount($has_extencion_check_sales, $article) {
		$checked_amount = null;
		if ($has_extencion_check_sales) {
			$stock_actual = $article->stock;
			if ($article->pivot->amount > $stock_actual) {
				if ($stock_actual < 0) {
					$checked_amount = 0;
				} else {
					$checked_amount = $stock_actual;
				}
			}
		}
		return $checked_amount;
	}

	static function attachSaleDiscountsAndSurchages($sale, $budget) {
		foreach ($budget->discounts as $discount) {
			$sale->discounts()->attach($discount->id, [
				'percentage'	=> $discount->pivot->percentage,
			]);
		}
		foreach ($budget->surchages as $surchage) {
			$sale->surchages()->attach($surchage->id, [
				'percentage'	=> $surchage->pivot->percentage,
			]);
		}
	}

	static function saveCurrentAcount($budget) {
		$debe = Self::getTotal($budget);
        $current_acount = CurrentAcount::create([
            'detalle'     => 'Presupuesto N°'.$budget->num,
            'debe'        => $debe,
            'status'      => 'sin_pagar',
            'client_id'   => $budget->client_id,
            'budget_id'   => $budget->id,
            'description' => null,
            'created_at'  => Carbon::now(),
        ]);
        Log::info('Se actualizo saldo a '.$debe);
        $current_acount->saldo = Numbers::redondear(CurrentAcountHelper::getSaldo('client', $budget->client_id, $current_acount) + $debe);
        $current_acount->save();
	}

	static function deleteCurrentAcount($budget) {
		$current_acount = CurrentAcount::where('budget_id', $budget->id)
										->first();
		if (!is_null($current_acount)) {
			$current_acount->delete();
			return true;
		}
		return false;
	}

	static function deleteSale($budget) {
		// Obtiene la venta asociada al presupuesto si existe
		$sale = Sale::where('budget_id', $budget->id)
										->first();
		if (!is_null($sale)) {
			Log::info(Auth()->user()->name.' va a eliminar la venta N° '.$sale->num.' por actualizar el presupuesto N° '.$budget->num);
			$ct = new SaleController();
			// Se pasa un Request vacío porque la venta se regenera por el update del presupuesto,
			// no es una eliminación del usuario, así que compensar_caja debe quedar en false.
			$ct->destroy(new Request(), $sale->id);
			// $sale->delete();
			return true;
		}
		return false;
	}

	static function getTotal($budget) {
		$total = 0;
		$budget->load('articles');
		$budget->load('promocion_vinotecas');
		$budget->load('services');

		/*
			🔴 El `load('combos')` va adentro de la guarda y no afuera: `load()` dispara la consulta
			en el acto, asi que en un cliente que todavia no corrio la migracion de `budget_combo`
			esta linea sola tumbaba el alta y el update de CUALQUIER presupuesto, tuviera combos o
			no. Preguntar despues no sirve: la consulta ya salio.
		*/
		if (ComboEsquemaHelper::hay_tabla()) {
			$budget->load('combos');
		}

		/*
			🔴 LA GUARDA QUE NO SE PUEDE SIMPLIFICAR: con `aplicar_recargos_directo_a_items`
			activo, el precio que viaja en el pivot YA TIENE EL RECARGO ADENTRO.

			Es la opcion "aplicar los recargos de esta venta directamente a los precios de los
			articulos" de VENDER: la SPA recarga cada `price` renglon por renglon y manda un
			`total` que NO vuelve a sumar el recargo. Si aca los `foreach ($budget->surchages ...)`
			corren igual, el recargo se aplica DOS VECES, la diferencia contra `$budget->total`
			se pasa del margen de 3 de `BudgetController::store()` y el guardado muere con
			"El total del presupuesto no corresponde con los productos ingresados" (500).

			Los DESCUENTOS si se siguen aplicando: la opcion es solo de recargos, el precio del
			pivot no los trae adentro.

			Misma guarda, mismo motivo y mismo estilo que `SaleHelper::getTotalSale()`, que es
			donde este comportamiento ya estaba resuelto del lado de las ventas.
		*/
		$aplicar_surchages = !$budget->aplicar_recargos_directo_a_items;

		foreach ($budget->articles as $article) {
			$total_article = Self::totalArticle($article);

			foreach ($budget->discounts as $discount) {
				$total_article -= $discount->pivot->percentage * $total_article / 100;
			}
			if ($aplicar_surchages) {
				foreach ($budget->surchages as $surchage) {
					$total_article += $surchage->pivot->percentage * $total_article / 100;
				}
			}

			$total += $total_article;
		}

		foreach ($budget->promocion_vinotecas as $promo) {
			$total_article = Self::totalArticle($promo);

			foreach ($budget->discounts as $discount) {
				$total_article -= $discount->pivot->percentage * $total_article / 100;
			}
			if ($aplicar_surchages) {
				foreach ($budget->surchages as $surchage) {
					$total_article += $surchage->pivot->percentage * $total_article / 100;
				}
			}

			$total += $total_article;
		}

		/*
			Combos (mision combos-y-rangos-de-precio, 16/9/2026).

			🔴 ESTE BUCLE ES EL QUE CIERRA EL 500. Hasta hoy un combo cargado en VENDER con "guardar
			como presupuesto" tildado viajaba adentro del `total` del payload pero se descartaba de
			las claves que el back leia: `getTotal()` no lo encontraba, la diferencia se pasaba del
			margen de 3 de `BudgetController::store()` y el guardado moria con "El total del
			presupuesto no corresponde con los productos ingresados". El vendedor no veia "el combo
			no se guardo": veia un total descuadrado que no explicaba nada.

			La regla que aplica es EXACTAMENTE la de los otros tres buckets, ni mas ni menos: los
			descuentos siempre, los recargos solo si `$aplicar_surchages`. Con
			`aplicar_recargos_directo_a_items` activo el precio del pivot YA TRAE el recargo adentro
			y volver a sumarlo lo aplicaria dos veces —el mismo bug, en el mismo lugar, para otro
			tipo de item—. Ver el comentario largo de arriba de `$aplicar_surchages`.

			El bucket entra por `ComboEsquemaHelper`: sin la tabla `budget_combo` es un bucket
			vacio, que es exactamente lo que vale para un cliente que todavia no puede tener ningun
			combo presupuestado.
		*/
		foreach (ComboEsquemaHelper::combos_del_presupuesto($budget) as $combo) {
			$total_combo = Self::totalArticle($combo);

			foreach ($budget->discounts as $discount) {
				$total_combo -= $discount->pivot->percentage * $total_combo / 100;
			}
			if ($aplicar_surchages) {
				foreach ($budget->surchages as $surchage) {
					$total_combo += $surchage->pivot->percentage * $total_combo / 100;
				}
			}

			$total += $total_combo;
		}

		foreach ($budget->services as $service) {
			$total_service = Self::totalArticle($service);

			if ($budget->discounts_in_services) {
				foreach ($budget->discounts as $discount) {
					$total_service -= $discount->pivot->percentage * $total_service / 100;
				}
			}

			if ($budget->surchages_in_services && $aplicar_surchages) {
				foreach ($budget->surchages as $surchage) {
					$total_service += $surchage->pivot->percentage * $total_service / 100;
				}
			}

			$total += $total_service;
		}

		/*
			EL TOTAL FORZADO, ULTIMO Y SOBRE EL TOTAL COMPLETO (mision forzar-total-por-monto,
			17/9/2026). Mismo lugar y mismo motivo que en `SaleHelper::getTotalSale()`: el monto es
			la diferencia contra el total que vio el vendedor en pantalla, asi que aplicarlo antes
			de los descuentos y recargos haria que esos porcentajes cayeran tambien sobre el.

			🔴 ESTA LINEA ES LA QUE DEJA GUARDAR UN PRESUPUESTO CON EL TOTAL FORZADO. Los dos
			llamadores de arriba —`BudgetController::store()` y `::duplicate()`— comparan lo que
			devuelve este metodo contra `budgets.total` y cortan con "El total del presupuesto no
			corresponde con los productos ingresados" si difieren en mas de 3. Con un total forzado,
			`budgets.total` ES el forzado; sin sumar el monto aca, la diferencia seria exactamente
			el monto del forzado y el guardado moriria con un 500 que no nombra la causa. Es el
			mismo defecto que ya tuvieron los combos (ver el bucle de combos, mas arriba) y el
			recargo directo a items: un bucket que entra en el `total` del payload pero no en esta
			cuenta.

			Y ademas hace que la cuenta corriente reciba el numero correcto: `saveCurrentAcount()`
			usa este mismo metodo para el `debe` del presupuesto.
		*/
		$total = SaleHelper::aplicar_forzar_total_monto($budget, $total);

		return $total;
	}

	static function totalArticle($article, $con_descuentos = true) {
		$total = $article->pivot->price * $article->pivot->amount;
		if (!is_null($article->pivot->bonus) && $con_descuentos) {
			$total -= $total * (float)$article->pivot->bonus / 100;
		}
		Log::info('sumando '.$total. ' de '.$article->name);
		return $total;
	}

	static function attachArticles($budget, $articles, $from_update = false) {
		/**
		 * Snapshot de los nombres personalizados por linea ANTES del detach.
		 * Se usa para preservarlos cuando el payload entrante no trae la senal
		 * name_vender_personalizado (ej: el form generico del modulo Presupuestos,
		 * que re-adjunta los articulos sin ese dato). Sin esto, guardar o confirmar
		 * desde ese form pisaria el nombre personalizado a null.
		 */
		$existing_names = [];
		foreach ($budget->articles as $existing_article) {
			$existing_names[$existing_article->id] = $existing_article->pivot->name;
		}
		$budget->articles()->detach();
		foreach ($articles as $article) {
			$id = (int)$article['id'];

			// $amount = array_key_exists('amount', $article) ? $article['amount'] : $article['pivot']['amount'];
			// $bonus = array_key_exists('bonus', $article) ? $article['bonus'] : $article['pivot']['bonus'];
			// $location = array_key_exists('location', $article) ? $article['location'] : $article['pivot']['location'];
			// $price = array_key_exists('price', $article) ? $article['price'] : $article['pivot']['price'];

			if (
				isset($article['pivot'])
				&& is_array($article['pivot'])
			) {

				$amount = $article['pivot']['amount'];
				$bonus = $article['pivot']['bonus'];
				$location = $article['pivot']['location'];
				$price = $article['pivot']['price'];
			} else {

				$amount = $article['amount'];
				$bonus = $article['bonus'];
				$location = $article['location'];
				$price = $article['price'];
			}
			
			$cost = SaleHelper::getCost($budget, $article);

			/*
			 * La lista por linea (rangos por cantidad), plano primero y despues en `pivot`
			 * (mision vender-lista-obligatoria, 17/9/2026). Hasta hoy se leia SOLO de `pivot`, y el
			 * alta desde VENDER (`vender_presupuestos.js::crear()`) manda el articulo plano, con la
			 * clave en la raiz: la lista por linea se perdia al guardar el presupuesto. En la
			 * actualizacion y en el form generico viaja bajo `pivot`, con el 0 con que nacen los
			 * items de VENDER, y ese 0 se guardaba tal cual: al confirmar llegaba a `article_sale`,
			 * donde `SaleHelper::get_price_type_personalizado()` nunca escribe un 0. Se normaliza
			 * con ESE mismo helper (0 y '' son null) para que las dos tablas digan lo mismo.
			 */
			$price_type_personalizado_id = SaleHelper::get_price_type_personalizado($article);

			if (is_null($price_type_personalizado_id) && isset($article['pivot']) && is_array($article['pivot'])) {
				$price_type_personalizado_id = SaleHelper::get_price_type_personalizado($article['pivot']);
			}
			
			if ($article['status'] == 'inactive' && $id > 0) {
				$art = Article::find($article['id']);
				$art->bar_code 		= $article['bar_code'];
				$art->provider_code = $article['provider_code'];
				$art->name 			= $article['name'];
				$art->save();
			}
			/**
			 * Si el payload trae la senal name_vender_personalizado (flujo de VENDER y de
			 * duplicar), se respeta la edicion, incluyendo limpiar el nombre. Si NO la trae
			 * (form generico del modulo Presupuestos, que no maneja el nombre por linea),
			 * se preserva el nombre ya guardado para no pisarlo a null.
			 */
			if (array_key_exists('name_vender_personalizado', $article)) {
				$pivot_name = SaleHelper::get_custom_name_for_pivot($article);
			} else {
				$pivot_name = isset($existing_names[$id]) ? $existing_names[$id] : null;
			}
			$budget->articles()->attach($article['id'], [
									'amount' 	=> $amount,
									'price' 	=> $price,
									'cost' 		=> $cost,
									'bonus' 	=> $bonus,
									'location' 	=> $location,
									'price_type_personalizado_id' 	=> $price_type_personalizado_id,
									'name' 		=> $pivot_name,
								]);
		}
	}

    static function getCost($item) {
        if (isset($item['pivot']['presentacion'])) {

            $item_cost = (float)$item['pivot']['cost'];
            if (isset($item['pivot']['costo_real'])) {
                $item_cost = (float)$item['pivot']['costo_real'];
            }
            
            $cost =  $item_cost * (float)$item['pivot']['presentacion'];
            return $cost;
        }
        if (isset($item['pivot']['costo_real'])) {
            return $item['pivot']['costo_real'];
        }
        if (isset($item['pivot']['cost'])) {
            return $item['pivot']['cost'];
        }
        return null;
    }

	static function attachServices($budget, $services) {
		$budget->services()->detach();

		foreach ($services as $service) {
			$id = (int)$service['id'];
			$amount = $service['pivot']['amount'];
			$price = $service['pivot']['price'];
			
			$budget->services()->attach($service['id'], [
									'amount' 	=> $amount,
									'price' 	=> $price,
								]);
		}		
	}

	static function attachPromocionVinotecas($budget, $promocion_vinotecas) {
		$budget->promocion_vinotecas()->detach();

		foreach ($promocion_vinotecas as $service) {

			$id = (int)$service['id'];
			$amount = $service['pivot']['amount'];
			$price = $service['pivot']['price'];
			
			$budget->promocion_vinotecas()->attach($service['id'], [
									'amount' 	=> $amount,
									'price' 	=> $price,
								]);
		}
	}

	/**
	 * Adjunta los combos del payload al presupuesto
	 * (mision combos-y-rangos-de-precio, 16/9/2026).
	 *
	 * Forma que espera, calcada de `get_promocion_vinotecas()` de la SPA:
	 *
	 *     combos: [ { id: <combo_id>, pivot: { amount: <cantidad>, price: <precio unitario> } } ]
	 *
	 * 🔴 LA CLAVE AUSENTE NO ES LO MISMO QUE LA CLAVE VACIA, y la diferencia es a proposito:
	 *
	 * - `combos: []` (presente y vacia) = el usuario saco todos los combos → se hace el detach.
	 * - clave ausente (null) = el que manda el request NO SABE de combos → no se toca nada.
	 *
	 * Los otros tres helpers de este archivo detachan siempre y despues hacen `foreach` sin
	 * chequear null, asi que con la clave ausente revientan (Laravel convierte el warning de
	 * `foreach (null)` en ErrorException). Acá no se copia esa parte por dos motivos concretos:
	 *
	 * 1. Una empresa-spa vieja contra esta API nueva no manda `combos`. Tiene que poder guardar un
	 *    presupuesto igual, no llevarse un 500.
	 * 2. `BudgetController::update()` lo pegan DOS frentes: VENDER (`vender_presupuestos.js`) y el
	 *    form generico del modulo Presupuestos. Si alguno de los dos no maneja combos, detachar por
	 *    las dudas le borraria al vendedor los combos del presupuesto sin decirle nada. Es
	 *    exactamente el criterio que este mismo archivo ya aplica con `name_vender_personalizado`
	 *    en `attachArticles()`: lo que el payload no nombra, no se pisa.
	 *
	 * 🔴 Y ANTES QUE TODO ESO, la guarda de esquema. Sin la tabla `budget_combo` no hay ni donde
	 * detachar ni donde adjuntar: `$budget->combos()->detach()` es un DELETE contra una tabla que
	 * no existe y se lleva puesta el alta entera del presupuesto. Se corta primero y el presupuesto
	 * se guarda sin combos, que es lo unico que ese cliente puede tener hasta que migre. Si el
	 * payload traia combos, se pierden en silencio: es la unica salida posible —no hay tabla donde
	 * escribirlos— y es preferible a un 500 que le impide guardar.
	 *
	 * @param  \App\Models\Budget  $budget
	 * @param  array|null          $combos
	 * @return void
	 */
	static function attachCombos($budget, $combos) {

		if (!ComboEsquemaHelper::hay_tabla()) {
			return;
		}

		if (!is_array($combos)) {
			return;
		}

		$budget->combos()->detach();

		foreach ($combos as $combo) {

			$amount = $combo['pivot']['amount'];
			$price = $combo['pivot']['price'];

			$budget->combos()->attach($combo['id'], [
									'amount' 	=> $amount,
									'price' 	=> $price,
								]);
		}
	}

}