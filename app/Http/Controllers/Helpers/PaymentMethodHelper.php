<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Support\Facades\Log;

/**
 * Los metodos de pago de una venta: el reparto en varios (`attach_payment_methods()`, que ya
 * existia) y, desde la tanda 2 de la mision vender-lista-obligatoria (18/9/2026), la regla "una
 * venta de contado no se guarda sin metodo de pago" (`validar_venta_nueva()` y
 * `validar_venta_actualizada()`, que usa `SaleController` antes de abrir la transaccion).
 *
 * EL CASO REAL: en VENDER el select de metodo de pago arranca en el placeholder (valor 0) y el
 * chequeo del front que lo frenaba (`chequeos/payment_methods.js`) estuvo APAGADO desde el
 * 4/3/2026 hasta la tanda 1 de esta mision. En ese lapso una venta de contado con el select en
 * "Seleccione metodo de pago" pasaba todos los chequeos, llegaba con
 * `current_acount_payment_method_id: 0`, `SaleHelper::attachSelectedPaymentMethods()` adjuntaba
 * ese 0 tal cual —una fila en el pivote que no apunta a ningun metodo— y `SaleCajaHelper` no
 * creaba movimiento de caja: una venta "cobrada" sin metodo y sin caja, sin un solo error. Se
 * llegaba ahi eligiendo el placeholder, cancelando el modal del boton verde (que pone metodo y caja
 * en 0 antes de abrirlo) o guardando un presupuesto editado, que dejaba el metodo en 0 para la
 * venta siguiente.
 *
 * 🔴 LA REGLA ES LA MISMA QUE LA DEL FRONT, y los dos lados tienen que decir lo mismo:
 *  - venta de contado = sin cliente, o con cliente pero omitida en cuenta corriente (es la misma
 *    condicion con la que `attachSelectedPaymentMethods()` decide adjuntar metodos);
 *  - solo si el catalogo `current_acount_payment_methods` tiene filas (es GLOBAL y sembrado fijo:
 *    1 Cheque … 3 Efectivo … 7 Retencion; una instalacion sin catalogo no tiene que exigir nada);
 *  - hace falta el metodo unico del select, valido, o un reparto con al menos un renglon valido.
 * "Valido" aca quiere decir lo mismo en las dos puntas: un id mayor a cero que EXISTE en el
 * catalogo (y en el reparto, ademas, con `amount`), o sea exactamente lo que
 * `attach_payment_methods()` termina adjuntando. Un reparto entero de ids inexistentes no adjunta
 * nada, y una venta que no adjunta nada es la venta sin caja de arriba: por eso se rechaza en vez
 * de dejarla pasar.
 *
 * ⚠️ SOLO SE OPINA SI EL REQUEST HABLA DEL COBRO: un `current_acount_payment_method_id` con valor
 * (0 incluido) o un `selected_payment_methods` que sea array. La SPA manda las dos claves SIEMPRE,
 * en el alta y en la edicion (`store/vender/vender.js`, `previus_sales.js`, el payload offline),
 * asi que para VENDER la regla es total. Un request que no trae ninguna de las dos —una
 * integracion, un script, un test que mide otra cosa— no esta cobrando con un placeholder: no esta
 * diciendo nada del cobro, y el back no le inventa un rechazo. Es el mismo criterio de "clave
 * ausente = no se opina" que `SaleController::update()` aplica a `omitir_en_cuenta_corriente` y a
 * `price_type_id`, y el que hace que un `null` pelado sea "no dijo nada" en todo este repo
 * (`!is_null($request->x) ? $request->x : default`).
 *
 * ⚠️ QUIENES NO PASAN POR ACA, a proposito: los creadores de ventas que no son `SaleController`
 * —el pedido de la tienda (`Order/CreateSaleOrderHelper`), el presupuesto confirmado
 * (`BudgetHelper::saveSale()`), la consolidacion de facturacion (`ConsolidarFacturacionHelper`) y
 * las ordenes de produccion (`OrderProductionHelper`)— no cobran en el acto: la venta nace a la
 * cuenta corriente del cliente o sin cobro, y ninguno adjunta metodos de pago. No reciben el 422
 * y no lo necesitan.
 */
class PaymentMethodHelper {

	/**
	 * El texto del 422. Lo ve el vendedor tambien con la SPA anterior, que muestra
	 * `err.response.data.message` en su catch generico.
	 *
	 * @return string
	 */
	static function mensaje_sin_metodo_de_pago() {
		return 'Elegí un método de pago para la venta.';
	}

	/**
	 * Un `current_acount_payment_method_id` tal como llega del request, convertido a lo unico que
	 * puede ser: el id de un metodo que existe en el catalogo, o null.
	 *
	 * 0, '', null, lo no numerico y lo negativo son "ningun metodo" (el 0 es el placeholder del
	 * select de VENDER). Un id positivo que no esta en el catalogo tambien es null: adjuntarlo
	 * dejaria una fila en el pivote que no apunta a nada, que es la venta sin caja del docblock de
	 * la clase.
	 *
	 * @param  mixed  $valor
	 * @return int|null
	 */
	static function metodo_de_pago_valido($valor) {

		if (is_null($valor) || is_bool($valor) || is_array($valor) || is_object($valor)) {
			return null;
		}

		if (!is_numeric($valor)) {
			return null;
		}

		$id = (int) $valor;

		if ($id <= 0) {
			return null;
		}

		if (!CurrentAcountPaymentMethod::where('id', $id)->exists()) {
			return null;
		}

		return $id;
	}

	/**
	 * Si el reparto trae al menos un renglon que `attach_payment_methods()` va a adjuntar: con
	 * `amount` y con un `current_acount_payment_method_id` que existe. Es la misma condicion que
	 * usa el loop de abajo para no saltear el renglon, asi que "hay un renglon valido" y "la venta
	 * va a quedar con al menos un metodo" son la misma pregunta.
	 *
	 * @param  mixed  $selected_payment_methods
	 * @return bool
	 */
	static function reparto_tiene_un_metodo_valido($selected_payment_methods) {

		if (!is_array($selected_payment_methods)) {
			return false;
		}

		foreach ($selected_payment_methods as $payment_method) {

			if (!is_array($payment_method)) {
				continue;
			}

			if (!isset($payment_method['amount'])) {
				continue;
			}

			if (!isset($payment_method['current_acount_payment_method_id'])) {
				continue;
			}

			if (!is_null(self::metodo_de_pago_valido($payment_method['current_acount_payment_method_id']))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * El 422 de una venta NUEVA de contado que llega sin metodo de pago, o null si la venta puede
	 * seguir. La regla completa esta en el docblock de la clase. Sin query cuando no hay nada que
	 * mirar (venta a cuenta corriente, o request que no habla del cobro).
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return array|null  El cuerpo del 422 (`message` + `sin_metodo_de_pago`), o null.
	 */
	static function validar_venta_nueva($request) {

		$es_de_contado = is_null($request->client_id) || $request->omitir_en_cuenta_corriente;

		return self::motivo_sin_metodo_de_pago($request, $es_de_contado);
	}

	/**
	 * Lo mismo para la EDICION de una venta. "De contado" se mira sobre lo que la venta va a tener
	 * DESPUES del update, que es lo que `attachSelectedPaymentMethods()` va a ver: el `client_id`
	 * del request (que `update()` asigna pelado) y el `omitir_en_cuenta_corriente` del request si
	 * viaja, o el guardado si no (`update()` lo preserva con `exists()`).
	 *
	 * En la edicion el metodo se rehace desde cero —detach y attach de lo que traiga el PUT—, asi
	 * que un PUT de una venta de contado con el select en el placeholder la dejaria cobrada con
	 * nada. Que la edicion de ventas cobradas este acotada a comercios sin cajas
	 * (`SaleHelper::motivo_por_el_que_no_se_puede_editar()`) no cambia el problema: sin caja no hay
	 * arqueo que descuadrar, pero la venta igual queda sin metodo.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  \App\Models\Sale          $sale     La venta guardada, antes del update.
	 * @return array|null
	 */
	static function validar_venta_actualizada($request, $sale) {

		$omitir = $request->exists('omitir_en_cuenta_corriente')
					? $request->omitir_en_cuenta_corriente
					: $sale->omitir_en_cuenta_corriente;

		$es_de_contado = is_null($request->client_id) || $omitir;

		return self::motivo_sin_metodo_de_pago($request, $es_de_contado);
	}

	/**
	 * La regla, compartida por el alta y la edicion.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  bool                      $es_de_contado
	 * @return array|null
	 */
	protected static function motivo_sin_metodo_de_pago($request, $es_de_contado) {

		if (!$es_de_contado) {
			return null;
		}

		$habla_del_cobro = !is_null($request->current_acount_payment_method_id)
							|| is_array($request->selected_payment_methods);

		if (!$habla_del_cobro) {
			return null;
		}

		if (!CurrentAcountPaymentMethod::query()->exists()) {
			return null;
		}

		/*
			🔴 Se valida EXACTAMENTE la rama que despues va a adjuntar SaleHelper::attachSelectedPaymentMethods():
			con reparto (array con al menos un renglon) manda SOLO el reparto; sin reparto, el metodo
			unico. Cuando esto era un OR --metodo unico valido O reparto valido--, un request con el
			metodo unico en 3 y un reparto no vacio con todos los renglones invalidos pasaba la
			validacion por el 3, pero el attach tomaba la rama del reparto, salteaba los renglones
			invalidos y la venta quedaba con CERO metodos: justo la venta cobrada sin metodo ni caja que
			esta regla existe para impedir. Lo encontro el revisor adversarial de la tanda 2 (18/9/2026).
		*/
		$hay_reparto = is_array($request->selected_payment_methods) && count($request->selected_payment_methods) >= 1;

		if ($hay_reparto) {
			if (self::reparto_tiene_un_metodo_valido($request->selected_payment_methods)) {
				return null;
			}
		} else {
			if (!is_null(self::metodo_de_pago_valido($request->current_acount_payment_method_id))) {
				return null;
			}
		}

		return [
			'message'               => self::mensaje_sin_metodo_de_pago(),
			'sin_metodo_de_pago'    => true,
		];
	}

	static function attach_payment_methods($model, $payment_methods) {

		foreach ($payment_methods as $payment_method) {

            if (!is_null($payment_method['amount'])) {

                $amount 			= $payment_method['amount'];
                $amount_cotizado 	= isset($payment_method['amount_cotizado']) ? $payment_method['amount_cotizado'] : null;
                $cotizacion 		= isset($payment_method['cotizacion']) ? $payment_method['cotizacion'] : null;
                $moneda_id          = isset($payment_method['moneda_id']) ? $payment_method['moneda_id'] : null;
                $cuota_id 			= isset($payment_method['cuota_id']) ? $payment_method['cuota_id'] : null;
                $caja_id 			= null;

                /*
                    Un metodo de pago sin current_acount_payment_method_id se saltea, pero el loop
                    SIGUE con los demas.

                    Hasta el 3/8/2026 aca habia un `return`, que abortaba el loop entero: si el
                    primer elemento venia mal, la venta quedaba con CERO metodos de pago adjuntos, y
                    como SaleCajaHelper::check_caja() solo crea movimiento si hay al menos uno, la
                    venta terminaba cobrada y sin ningun movimiento de caja. Sin excepcion y sin
                    error visible.
                */
                if (!isset($payment_method['current_acount_payment_method_id'])) {

                    Log::warning('attach_payment_methods: metodo de pago sin current_acount_payment_method_id, se saltea. Modelo: '.get_class($model).' id: '.$model->id);

                    continue;
                }

                $payment_method_model = CurrentAcountPaymentMethod::find($payment_method['current_acount_payment_method_id']);

                if (is_null($payment_method_model)) {

                    Log::warning('attach_payment_methods: current_acount_payment_method_id '.$payment_method['current_acount_payment_method_id'].' no existe, se saltea. Modelo: '.get_class($model).' id: '.$model->id);

                    continue;
                }

                if (
                    !is_null($payment_method_model->type) 
                    && $payment_method_model->type->slug == 'tarjeta_de_credito' 
                    && isset($request->monto_credito_real)
                    && !is_null($request->monto_credito_real)
                ) {

                    $amount = $request->monto_credito_real;
                }


                if (isset($payment_method['caja_id'])
                    && $payment_method['caja_id'] != 0) {
                    $caja_id = $payment_method['caja_id'];
                }
                
	            if (
	            	!is_null($payment_method_model->type) 
                    && $payment_method_model->type->slug == 'cheque'
	            ) {
	                ChequeHelper::crear_cheque($model, $payment_method);
	            }

                $model->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'],[
                    'amount'            => $amount,
                    'caja_id'           => $caja_id,
                    'amount_cotizado'   => $amount_cotizado,
                    'cotizacion'        => $cotizacion,
                    'moneda_id'         => $moneda_id,
                    'cuota_id'          => $cuota_id,
                ]);

            }
        }
	}
	
}