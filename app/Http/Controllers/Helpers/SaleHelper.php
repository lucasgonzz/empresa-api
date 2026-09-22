<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountAndCommissionHelper;
use App\Http\Controllers\Helpers\CurrentAcountFromSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\SaleModificationsHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Http\Controllers\Helpers\sale\ComboHelper;
use App\Http\Controllers\Helpers\sale\CostoDeVentaHelper;
use App\Http\Controllers\Helpers\sale\IvaDeVentaHelper;
use App\Http\Controllers\Helpers\sale\PromocionVinotecaHelper;
use App\Http\Controllers\Helpers\sale\SaleCajaHelper;
use App\Http\Controllers\Helpers\sale\SaleTotalesHelper;
use App\Http\Controllers\Helpers\sale\UpdateHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SellerCommissionController;
/*
 * Import que faltaba: returnToStock() hace `new StockMovementController()` y sin esta línea
 * PHP lo resolvía a App\Http\Controllers\Helpers\StockMovementController (inexistente).
 * Nunca se notó porque el único camino que llega ahí (el panel NC de Vender) reventaba
 * antes por la firma vieja de notaCredito(), corregida el 24/8/2026.
 */
use App\Http\Controllers\Stock\StockMovementController;
use App\Http\Controllers\Helpers\Devoluciones\ValidarDevolucionHelper;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Caja;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Commissioner;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\Cuota;
use App\Models\Discount;
use App\Models\Iva;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\SellerCommission;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class SaleHelper extends Controller {

    static function set_total_sales($user_id) {

        $sales = Sale::where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC')
                        ->get();

        echo count($sales).' ventas <br> ';

        foreach ($sales as $sale) {
            
            $sale->total = Self::getTotalSale($sale);
            $sale->timestamps = false;

            $sale->save();
        }

        echo 'Termino <br> ';
    }

    static function update_total_sale($sale) {
        $sale->total = Self::getTotalSale($sale);
        $sale->save();
    }

    static function get_se_esta_confirmando($request, $sale) {
        if ($request->confirmed && $sale->checked) {
            return true;
        }
        return false;
    }

    static function get_terminada($to_check, $fecha_entrega) {
        if (UserHelper::hasExtencion('check_sales') && $to_check) {
            return 0;
        }

        if (UserHelper::hasExtencion('ventas_con_fecha_de_entrega') && $fecha_entrega) {
            return 0;
        }

        return 1;
    }

    static function get_terminada_at($to_check, $fecha_entrega) {
        if (Self::get_terminada($to_check, $fecha_entrega)) {
            return Carbon::now();
        }
        return null;
    }

    /**
     * El `created_at` que se va a guardar en un ALTA, a partir de la fecha que eligio el usuario.
     *
     * 🔴 ES EL UNICO LUGAR DONDE SE INTERPRETA EL CAMPO, para los dos flujos que lo usan: el alta
     * de una venta (`SaleController::store()`) y el alta de una compra a proveedor
     * (`ProviderOrderController::store()`). Si aparece un tercero, llama a este metodo; no se
     * copia el parseo.
     *
     * 🔴 POR QUE LA HORA ACTUAL Y NO MEDIANOCHE. Un `<input type="date">` manda solo `YYYY-MM-DD`.
     * Guardar eso tal cual dejaria TODAS las ventas del dia a las `00:00:00`, y eso rompe tres
     * cosas que hoy funcionan:
     *   (a) el cierre de caja por turno (`ResumenCajaController:69-78` acota por `desde`/`hasta`
     *       CON hora, no por dia);
     *   (b) la guarda anti-duplicados del doble clic (`SaleController::venta_ya_cread()`, que
     *       mira una ventana de 5 segundos alrededor de este mismo instante);
     *   (c) el desempate por `created_at` de los movimientos de cuenta corriente.
     * Con la hora actual, el caso normal —el usuario no toca el campo, la fecha es hoy— guarda
     * exactamente lo mismo que hoy: cero cambio de comportamiento en el 99% de las ventas.
     *
     * 🔴 POR QUE LISTA BLANCA Y NO PARSEO LIBRE. Medido el 21/9/2026: reasignar un `created_at`
     * que viajo como ISO con `Z` lo corre +3 horas (`toArray()` serializa en UTC y al volver a
     * asignarlo se reinterpreta en la zona local). Por eso esto NO es un `try/catch` alrededor de
     * `Carbon::parse()` que acepte cualquier cosa parseable: se acepta EXACTAMENTE `YYYY-MM-DD` y
     * nada mas. Cualquier otro valor —null, vacio, un ISO con `Z`, basura, una fecha imposible
     * como `2026-13-45`— cae al comportamiento de siempre: `Carbon::now()`.
     *
     * @param  mixed  $valor  Lo que mando el front en la clave `created_at`.
     * @return \Carbon\Carbon
     */
    static function resolver_created_at($valor) {
        $ahora = Carbon::now();

        $dia = Self::dia_pedido($valor);

        if (is_null($dia)) {
            return $ahora;
        }

        return Carbon::create($dia[0], $dia[1], $dia[2], $ahora->hour, $ahora->minute, $ahora->second);
    }

    /**
     * El `created_at` que hay que guardar en un UPDATE, o null si no hay nada que tocar.
     *
     * En el update "la hora actual" no significa nada: el registro ya tiene su hora, que es cuando
     * se cargo de verdad. Asi que aca solo se le cambia el DIA y se conserva la hora, los minutos
     * y los segundos originales.
     *
     * Devuelve null —o sea "no toques `created_at`"— en los dos casos en que no hay nada pedido:
     * cuando el valor no pasa la lista blanca (ver `resolver_created_at()`) y cuando el dia pedido
     * es el mismo que el guardado. Lo segundo no es una optimizacion: reescribir el mismo dia con
     * la hora de ahora le correria la hora a la venta sin que nadie lo haya pedido.
     *
     * @param  mixed  $created_at_guardado  El `created_at` que tiene hoy el registro (Carbon o string).
     * @param  mixed  $valor                Lo que mando el front en la clave `created_at`.
     * @return \Carbon\Carbon|null
     */
    static function resolver_created_at_de_update($created_at_guardado, $valor) {
        $dia = Self::dia_pedido($valor);

        if (is_null($dia)) {
            return null;
        }

        // Un registro sin `created_at` no tiene hora que conservar: vale lo mismo que un alta.
        if (is_null($created_at_guardado)) {
            return Self::resolver_created_at($valor);
        }

        $guardado = $created_at_guardado instanceof Carbon
                        ? $created_at_guardado->copy()
                        : new Carbon($created_at_guardado);

        if ($guardado->format('Y-m-d') === sprintf('%04d-%02d-%02d', $dia[0], $dia[1], $dia[2])) {
            return null;
        }

        return $guardado->copy()->setDate($dia[0], $dia[1], $dia[2]);
    }

    /**
     * La lista blanca: devuelve [anio, mes, dia] si el valor es EXACTAMENTE un `YYYY-MM-DD` que
     * existe en el calendario, y null en cualquier otro caso.
     *
     * El `checkdate()` no sobra: `2026-13-45` pasa el regex y no es una fecha. Y el regex tampoco
     * sobra: sin el, un ISO con `Z` entraria por `explode('-')` y terminaria guardando un dia con
     * la hora corrida.
     *
     * @param  mixed  $valor
     * @return array|null
     */
    protected static function dia_pedido($valor) {
        if (!is_string($valor) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $valor) !== 1) {
            return null;
        }

        $partes = explode('-', $valor);

        $anio = (int) $partes[0];
        $mes  = (int) $partes[1];
        $dia  = (int) $partes[2];

        if (!checkdate($mes, $dia, $anio)) {
            return null;
        }

        return [$anio, $mes, $dia];
    }

    static function check_guardad_cuenta_corriente_despues_de_facturar($sale, $instance) {
        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')
            && !Self::al_cliente_se_le_factura_en_el_acto($sale) ) {
            $sale->save_current_acount = 0;
            $sale->save();
        }
    }

    static function al_cliente_se_le_factura_en_el_acto($sale) {
        if (!is_null($sale->client) && $sale->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
            return true;            
        }
        return false;
    }

    static function setPrinted($instance, $sale, $confirmed, $user) {
        if (UserHelper::hasExtencion('check_sales', $user) && $confirmed) {
            $sale->printed = 1;
            $sale->save();
            $instance->sendAddModelNotification('Sale', $sale->id, false);
        }
    }

    static function log_client($sale) {
        $client = $sale->client;
        if (!is_null($client)) {
            Log::info('La venta '.$sale->id.' tiene el cliente: '.$client->name.'. Id: '.$client->id);
        }
    }

    static function log_articles($sale, $articles) {
        Log::info('La venta '.$sale->id.' tiene estos articulos:');
        foreach ($articles as $article) {
            Log::info('Id: '.$article->id.'. '.$article->name.'. amount: '.$article->pivot->amount.'. checked_amount: '.$article->pivot->checked_amount);
        }
    }

    static function updatePreivusClient($sale, $previus_client_id) {
        if (!is_null($sale->client_id) && $sale->client_id != $previus_client_id && !is_null($previus_client_id)) {
            CurrentAcountHelper::checkSaldos('client', $previus_client_id);
        }
    }

    static function sendUpdateClient($instance, $sale) {
        if (!is_null($sale->client_id) && !$sale->to_check && !$sale->checked) {
            $instance->sendAddModelNotification('Client', $sale->client_id);
        }
    }

    static function deleteSaleFrom($model_name, $model_id, $instance) {
        $sale = Sale::where($model_name.'_id', $model_id)
                        ->first();
        if (!is_null($sale)) {
            Log::info('Se quiere eliminar sale N° '.$sale->num.'. id: '.$sale->id.'. Por el empleado: '.Auth()->user()->name.', doc: '.Auth()->user()->doc_number);
            $sale->delete();
            $instance->sendDeleteModelNotification('sale', $sale->id, false);
        }
    }

    static function get_confirmed($to_check) {
        if (UserHelper::hasExtencion('check_sales') && $to_check) {
            return 0;
        }
        return 1;
    }

    static function getEmployeeId($request = null) {
        if (!is_null($request) && $request->employee_id != 0) {
            return $request->employee_id;
        }

        $user = Auth()->user();
        if (!is_null($user->owner_id)) {
            return $user->id;
        }
        return null;
    }

    /* 
        Retorno siempre null porque se va a empezar a usar siempre el array
        de current_acount_payment_methods
    */
    static function getCurrentAcountPaymentMethodId($request) {
        return null;
        if (is_null($request->client_id)) {
            return $request->current_acount_payment_method_id;
        }
        return null;
    }

    static function saveAfipTicket($sale) {
        if (!is_null($sale->afip_information_id) && $sale->afip_information_id != 0) {
            $ct = new AfipWsController(['sale' => $sale]);
            $afip_ticket_result = $ct->init();
            return $afip_ticket_result;
        } 
    }

    static function getSelectedAddress($request) {
        return !is_null($request->selected_address) ? $request->selected_address['id'] : null;
    }

    static function getNumSaleFromSaleId($sale_id) {
        $sale = Sale::where('id', $sale_id)
                    ->select('num')
                    ->first();
        if ($sale) {
            return $sale->num;
        }
        return null;
    }

    static function attachProperies($model, $request, $from_store = true, $previus_articles = null, $previus_combos = null, $previus_promos = null, $sale_modification = null, $se_esta_confirmando_por_primera_vez = false, $se_activando_discount_stock = false) {

        Log::info('attachProperies');

        
        Self::attachDiscounts($model, $request->discounts);
        Self::attachSurchages($model, $request->surchages);

        $fecha_agregado_by_article_id = [];

        if (!is_null($previus_articles)) {
            $fecha_agregado_by_article_id = Self::get_fecha_agregado_map_for_normal_articles($request->items, $previus_articles);
        }

        Self::attachArticles($model, $request->items, $previus_articles, $se_esta_confirmando_por_primera_vez, $fecha_agregado_by_article_id, $se_activando_discount_stock);
        
        Log::info('1');

        /*
            🔴 Si la venta recien ahora empieza a descontar stock (se confirma un presupuesto, o se
            activa discount_stock en una venta que lo tenia apagado), los combos y promos previos
            NUNCA descontaron nada: se descuentan enteros, como hace attachArticles() con los
            articulos sueltos. Restarles el previo (que es lo que hace get_combo_amount() para
            calcular la diferencia en una actualizacion comun) daba 1 - 1 = 0 y el combo quedaba
            sin descontar para siempre (auditoria de stock, 5/9/2026).

            Las promos no miran discount_stock (descuentan siempre que la venta este confirmada),
            asi que para ellas solo cuenta la confirmacion.
        */
        $previus_promos_a_restar = $se_esta_confirmando_por_primera_vez ? null : $previus_promos;
        $previus_combos_a_restar = ($se_esta_confirmando_por_primera_vez || $se_activando_discount_stock) ? null : $previus_combos;

        Self::attachPromocionVinotecas($model, $request->items, $previus_promos_a_restar);
        Self::attachCombos($model, $request->items, $previus_combos_a_restar);
        Self::attachServices($model, $request->items);

        Self::attachSelectedPaymentMethods($model, $request);
        
        Log::info('2');
        if (!$from_store) {
            SaleModificationsHelper::attach_articulos_despues_de_actualizar($model, $sale_modification);
            
            /*
                * Si la venta ya esta confirmada
                y no es que se esta confirmando por primera vez
                (osea ya estaba confirmada antes de actualizarce)
                Recien ahi veo si se elimino algun articulo para regresar al stock.
                Tampoco se revisa si se esta activando discount_stock por primera vez,
                ya que en ese caso los articulos previos nunca tuvieron stock descontado.
            */
            if (!$model->to_check && !$model->checked && !$se_esta_confirmando_por_primera_vez && !$se_activando_discount_stock) {

                UpdateHelper::check_articulos_eliminados($model, $request->items, $previus_articles, $se_esta_confirmando_por_primera_vez);

                if ((bool)$model->discount_stock) {
                    UpdateHelper::check_combos_eliminados($model, $request->items, $previus_combos);
                }
            }
        }

        Log::info('3');
        /*
            * Si se esta confirmando, el total que llega de vender esta mal
                porque no tiene en cuenta las unidades chequedas, las cuales ahora
                pasan a ser las unidades reales del articulo en la venta
            * Entonces se setea el total desde aca para tener eso en cuenta
        */
        if ($se_esta_confirmando_por_primera_vez) {
            Self::update_total_sale($model);
        }


        Log::info('4');
        if ($from_store && !$model->to_check && !$model->checked) {
            
            Self::create_current_acount($model);

            Self::crear_comision($model);

            SaleCajaHelper::check_caja($model);

            /*
             * Puntos para clientes. Lo que se cubre acá es la VENTA DE MOSTRADOR, que nunca
             * pasa por la cuenta corriente y por lo tanto nunca dispara checkPagos(): sin esta
             * línea, un comercio que no usa cuenta corriente no acumularía un solo punto.
             *
             * Para la venta de cuenta corriente esto es un no-op barato: create_current_acount()
             * ya llamó a CurrentAcountFromSaleHelper, que llama a checkPagos(), que ya
             * reconcilió esta misma venta. Correrlo dos veces no duplica nada — el
             * reconciliador compara contra lo que ya escribió y no toca la base si no cambió.
             *
             * Va DESPUÉS de check_caja() y no antes, para que si algo del cobro en el acto
             * revienta, la venta no quede con puntos otorgados por una venta que no se guardó.
             */
            PuntosAcumulacionHelper::reconciliar_venta($model);

        } else {

            Self::checkNotaCredito($model, $request);
        }

        Log::info('5');


        $h = new ArticlePurchaseHelper();
        $h->set_article_purcase($model);

        SaleTotalesHelper::set_total_cost($model);

        /**
         * Se recalcula y persiste la ganancia final de la venta
         * luego de actualizar el costo total.
         */
        Self::set_sale_ganancia($model);
    }

    /**
     * Calcula y persiste la ganancia total de la venta.
     *
     * Fórmula (misión saneo-ganancia-ventas, 17/9/2026):
     *
     *     sales.ganancia = total − costo NETO − IVA efectivamente declarado por esa venta
     *
     * donde el costo neto es `total_cost` menos el crédito fiscal que ese costo trae adentro
     * (`CostoDeVentaHelper`), que es 0 en la enorme mayoría de las cuentas.
     *
     * 🔴 El tercer término es el que faltaba, y no es un detalle: `sales.total` es el precio CON
     * IVA y `sales.total_cost` es el costo SIN IVA. Para un Responsable Inscripto que aplica el
     * IVA después del margen, la resta pelada informaba como ganancia TODO el IVA débito. Con
     * costo 100 y margen 40 %, la venta sale 169,40 y la fórmula vieja informaba $69,40 de
     * ganancia donde la ganancia real es $40: los $29,40 restantes son de ARCA.
     *
     * 🔴 Y el segundo término no siempre es neto, que es lo que hacía que la fórmula nueva fuera
     * PEOR que la vieja en una cuenta **legacy con `aplicar_iva_al_costo` prendida**: ahí el costo
     * se guarda BRUTO y restarle además el IVA débito completo descuenta el IVA dos veces (costo
     * bruto 121 y margen 40 % daban $19,00 donde la ganancia real es $40). Ese IVA de compra es
     * crédito fiscal recuperable y se lo devuelve al costo antes de restar. Quién tiene el costo
     * bruto, quién recupera ese IVA y por qué el Monotributista no entra: `CostoDeVentaHelper`.
     *
     * El IVA sale del COMPROBANTE (`IvaDeVentaHelper`), nunca de la condición fiscal del negocio:
     * las ventas sin comprobante —el 63 % de ferretotal y el 51 % de golonorte— no declaran nada,
     * el IVA cobrado se lo queda la casa y para ellas la fórmula vieja ya era correcta. Ver el
     * PHPDoc de `IvaDeVentaHelper` para la tabla de casos completa.
     *
     * 🔴 Las notas de crédito NO se netean acá, a propósito. `sales.total` y `sales.total_cost` no
     * se tocan cuando se emite una nota de crédito (la devolución vive en `current_acounts` +
     * `article_current_acount`, ver `ContabilidadRepository::devoluciones()` y
     * `costo_mercaderia_devuelta()`): la fila de `sales` sigue describiendo la venta ORIGINAL
     * entera. Netearle solo el IVA de la NC dejaría un número mestizo —precio bruto, costo bruto,
     * IVA neteado— que no describe ninguna operación real. El neteo de las devoluciones es un
     * renglón del Estado de Resultados, donde las tres puntas se netean juntas.
     *
     * 🔴 Un comprobante autorizado sin `importe_iva` medido deja la ganancia en NULL, no en el
     * número viejo ni en "IVA 0". Null ya significa "no se puede calcular" en esta columna (es lo
     * que se persiste cuando falta el total o el costo), y es la única respuesta que no miente:
     * asumir 0 sería contar una venta facturada como si hubiera sido en negro. Recuperar ese IVA es
     * una tarea aparte y hoy no hay comando que la haga (ver el PHPDoc de `IvaDeVentaHelper`).
     *
     * @param \App\Models\Sale $sale
     * @return \App\Models\Sale
     */
    static function set_sale_ganancia($sale) {
        /** IVA declarado por esta venta y comprobantes suyos que todavía no lo tienen medido. */
        $medicion_iva = IvaDeVentaHelper::medir_venta($sale);

        /** Crédito fiscal contenido en el costo (0 salvo en las cuentas con el costo BRUTO). */
        $credito_fiscal = CostoDeVentaHelper::medir_venta($sale);

        /** Se guarda sin timestamps para mantener el comportamiento actual del helper. */
        $sale->ganancia = Self::calcular_ganancia($sale->total, $sale->total_cost, $medicion_iva, $credito_fiscal);
        $sale->timestamps = false;
        $sale->save();

        return $sale;
    }

    /**
     * La fórmula de la ganancia, sola y sin efectos: la comparten el guardado en vivo
     * (`set_sale_ganancia()`) y el backfill (`php artisan set_sales_ganancia`), para que no puedan
     * dar números distintos sobre la misma venta.
     *
     * El cuarto parámetro tiene default 0 y no es un atajo: 0 es la respuesta CORRECTA para toda
     * cuenta cuyo costo ya es neto, que son casi todas. Sólo las cuentas con el costo BRUTO mandan
     * algo distinto (ver `CostoDeVentaHelper`).
     *
     * @param  mixed $total Total de la venta (`sales.total`), puede venir null.
     * @param  mixed $total_cost Costo total de la venta (`sales.total_cost`), puede venir null.
     * @param  array{iva: float, sin_medir: int} $medicion_iva Salida de `IvaDeVentaHelper`.
     * @param  mixed $credito_fiscal_en_el_costo Salida de `CostoDeVentaHelper::medir_venta()`.
     * @return float|null Null cuando el número no se puede calcular (ver PHPDoc de set_sale_ganancia).
     */
    static function calcular_ganancia($total, $total_cost, $medicion_iva, $credito_fiscal_en_el_costo = 0.0) {
        if (is_null($total) || is_null($total_cost)) {
            return null;
        }

        if ((int) $medicion_iva['sin_medir'] > 0) {
            return null;
        }

        /** Costo YA NETO: se le devuelve al costo el IVA de compra que el negocio recupera. */
        $costo_neto = (float) $total_cost - (float) $credito_fiscal_en_el_costo;

        return (float) $total - $costo_neto - (float) $medicion_iva['iva'];
    }

    // Chequeo que no falten articulos como le suele pasar a Pack
    static function check_que_este_el_articulos($sale, $article) {

        $sale->load('articles');
        $article_sale = $sale->articles()->find($article['id']);
            
        if (!$article_sale) {
            Self::attachArticle($sale, $article);
          
            // Log::info('No se estaba agregando el articulo '.$article['name'].'. N° '.$article['num'].' a la venta N° '.$sale->num);
        } else if (isset($article['name'])) {
            Log::info('La venta N° '.$sale->num.' SI tiene el articulo '.$article['name']);
        }
    }
    // static function check_que_esten_todos_los_articulos($sale) {

    //     foreach($sale->stock_movements as $stock_movement) {
    //         $article = $sale->articles()->find($stock_movement->article_id);
            
    //         if (!$article) {
                
    //             $article_faltante = $stock_movement->article;

    //             $sale->articles()->attach($stock_movement->article_id, [
    //                 'amount'    => abs($stock_movement->amount),
    //                 'price'     => $article_faltante->final_price,
    //             ]);
    //             Log::info('No se estaba agregando el articulo '.$article_faltante->name.'. N° '.$article_faltante->num.' a la venta N° '.$sale->num);
    //         } else {
    //             Log::info('La venta N° '.$sale->num.' SI tiene el articulo '.$article->name);
    //         }
    //     }
    // }

    static function set_total_a_facturar($sale, $request) {

        if (
            !is_null($request->afip_information_id) 
            && $request->afip_information_id != 0
        ) {

            $afip_ticket = new AfipTicket();
            $afip_ticket->afip_information_id = $request->afip_information_id;
            $afip_ticket->afip_tipo_comprobante_id = $request->afip_tipo_comprobante_id;
            $afip_ticket->facturar_importe_personalizado = null;
            $afip_ticket->sale = $sale;


            $afip_helper = new AfipHelper($afip_ticket);
            $importes = $afip_helper->getImportes();
            Log::info('pidiendo total_a_facturar: '.$importes['total']);

            $sale->total_a_facturar = $importes['total'];
            $sale->save();
        }
    }

    static function attachSelectedPaymentMethods($sale, $request){

        if (is_null($sale->client_id) || $sale->omitir_en_cuenta_corriente) {
            $sale->current_acount_payment_methods()->detach();

            if (
                is_array($request->selected_payment_methods)
                && count($request->selected_payment_methods) >= 1
            ) {
                PaymentMethodHelper::attach_payment_methods($sale, $request->selected_payment_methods);
                // foreach ($request->selected_payment_methods as $payment_method) {

                //     if (!is_null($payment_method['amount'])) {

                //         $amount = $payment_method['amount'];
                //         $amount_cotizado = $payment_method['amount_cotizado'];
                //         $cotizacion = $payment_method['cotizacion'];
                //         $moneda_id = $payment_method['moneda_id'];

                //         if ($payment_method['current_acount_payment_method_id'] == 5 
                //             && isset($request->monto_credito_real)
                //             && !is_null($request->monto_credito_real)) {

                //             $amount = $request->monto_credito_real;
                //         }

                //         $caja_id = null;

                //         if (isset($payment_method['caja_id'])
                //             && $payment_method['caja_id'] != 0) {
                //             $caja_id = $payment_method['caja_id'];
                //         }
                        
                //         $sale->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'],[
                //             'amount'            => $amount,
                //             'caja_id'           => $caja_id,
                //             'amount_cotizado'   => $amount_cotizado,
                //             'cotizacion'        => $cotizacion,
                //             'moneda_id'         => $moneda_id,
                //         ]);

                //         Log::info('adjuntando current_acount_payment_method_id: '.$payment_method['current_acount_payment_method_id'].' y caja_id: '.$caja_id);
                //     }
                // }
            } else {

                /*
                    🔴 El metodo unico se adjunta SOLO si es un metodo real (tanda 2 de la mision
                    vender-lista-obligatoria, 18/9/2026). Hasta hoy esta rama hacia
                    `attach($request->current_acount_payment_method_id, ...)` con lo que viniera:
                    con el 0 del placeholder del select de VENDER quedaba una fila en
                    `current_acount_payment_method_sale` apuntando a un metodo que no existe, la
                    relacion `current_acount_payment_methods` la ignoraba (no hay fila 0 contra la
                    cual unir) y `SaleCajaHelper::check_caja()` no creaba movimiento: venta
                    "cobrada" sin metodo y sin caja, sin error. Mismo criterio que
                    `PaymentMethodHelper::attach_payment_methods()` aplica al reparto desde el
                    3/8/2026: lo que no es un metodo se saltea y queda dicho en el log.

                    Con `null` el attach ya era un no-op (Eloquent no inserta nada con un id
                    nulo); ahora el no-op es explicito y logueado para el 0, el inexistente y el
                    null por igual.

                    Que no llegue nada hasta aca desde VENDER lo garantiza el 422 de
                    `SaleController` (`PaymentMethodHelper::validar_venta_nueva()`); esta guarda
                    es la ultima linea, para los llamadores que no pasan por ese chequeo.
                */
                $current_acount_payment_method_id = PaymentMethodHelper::metodo_de_pago_valido($request->current_acount_payment_method_id);

                if (is_null($current_acount_payment_method_id)) {

                    Log::warning('attachSelectedPaymentMethods: la venta '.$sale->id.' es de contado y el metodo unico ('.var_export($request->current_acount_payment_method_id, true).') no es un metodo de pago valido; no se adjunta ninguno.');

                    return;
                }

                $total = (float)$sale->total;

                if (!is_null($request->discount_amount)) {
                    $total += (float)$request->discount_amount;
                }

                // Descuento/recargo por metodo de pago: si el frontend ya lo mando calculado (comportamiento
                // actual, se preserva tal cual), se usa ese valor. Si no vino, se resuelve en base a las
                // reglas configuradas (metodo + cuotas) segun la precedencia de Capa 3 (Prompt 263).
                $discount_percentage = $request->discount_percentage;
                if (is_null($discount_percentage) && !is_null($request->current_acount_payment_method_id)) {
                    $discount_percentage = Self::resolver_descuento_recargo_metodo_pago(
                        $sale->user_id,
                        $request->current_acount_payment_method_id,
                        $request->cuotas
                    );
                }

                $sale->current_acount_payment_methods()->attach($current_acount_payment_method_id, [
                    'amount'                => $total,
                    'discount_percentage'   => $discount_percentage,
                    'discount_amount'       => $request->discount_amount,
                    'caja_id'               => $request->caja_id,
                ]);
            }
        }

    }
    /**
     * Resuelve el porcentaje de descuento/recargo aplicable al vender con un metodo de pago y una
     * cantidad de cuotas determinados (Capa 3 del motor de precios, Prompt 263).
     *
     * Precedencia (gana la primera regla que matchee; las reglas NO se acumulan), de mas especifica
     * a mas generica:
     *   1. Regla de `cuotas` con `payment_method_id` = metodo elegido Y `cantidad_cuotas` = cuotas elegidas.
     *   2. Regla de `cuotas` generica (`payment_method_id` NULL) con esa `cantidad_cuotas`.
     *   3. Regla de `current_acount_payment_method_discounts` del metodo con `cuotas` = cuotas elegidas.
     *   4. Regla de `current_acount_payment_method_discounts` del metodo con `cuotas` NULL (comportamiento
     *      actual, previo al Prompt 260/263).
     * Si ninguna regla matchea, devuelve null (sin descuento/recargo, igual que hoy).
     *
     * @param int $user_id Id del owner (dueño de cuenta) al que pertenecen las reglas configuradas.
     * @param int|null $current_acount_payment_method_id Metodo de pago elegido en la venta.
     * @param int|null $cuotas Cantidad de cuotas elegida (null si la venta no es en cuotas).
     * @return float|null Porcentaje a aplicar sobre el monto (positivo = descuento, negativo = recargo).
     */
    static function resolver_descuento_recargo_metodo_pago($user_id, $current_acount_payment_method_id, $cuotas = null) {

        if (is_null($current_acount_payment_method_id)) {
            return null;
        }

        // Pasos 1 y 2 (reglas de `cuotas`) solo aplican si se eligio una cantidad de cuotas.
        if (!is_null($cuotas)) {

            // 1. Regla especifica de este metodo de pago para esta cantidad de cuotas.
            $cuota_especifica = Cuota::where('user_id', $user_id)
                                ->where('payment_method_id', $current_acount_payment_method_id)
                                ->where('cantidad_cuotas', $cuotas)
                                ->first();
            if ($cuota_especifica) {
                return (float)$cuota_especifica->descuento - (float)$cuota_especifica->recargo;
            }

            // 2. Regla generica (sin metodo de pago) para esa cantidad de cuotas.
            $cuota_generica = Cuota::where('user_id', $user_id)
                                ->whereNull('payment_method_id')
                                ->where('cantidad_cuotas', $cuotas)
                                ->first();
            if ($cuota_generica) {
                return (float)$cuota_generica->descuento - (float)$cuota_generica->recargo;
            }

            // 3. Regla del metodo de pago limitada a esa cantidad de cuotas.
            $method_discount_con_cuotas = CurrentAcountPaymentMethodDiscount::where('user_id', $user_id)
                                ->where('current_acount_payment_method_id', $current_acount_payment_method_id)
                                ->where('cuotas', $cuotas)
                                ->first();
            if ($method_discount_con_cuotas) {
                return (float)$method_discount_con_cuotas->discount_percentage;
            }
        }

        // 4. Regla generica del metodo de pago (sin cuotas): comportamiento actual, previo al Prompt 260.
        $method_discount_generico = CurrentAcountPaymentMethodDiscount::where('user_id', $user_id)
                            ->where('current_acount_payment_method_id', $current_acount_payment_method_id)
                            ->whereNull('cuotas')
                            ->first();
        if ($method_discount_generico) {
            return (float)$method_discount_generico->discount_percentage;
        }

        return null;
    }

    /*
        Devuelve el motivo por el que una venta NO se puede editar, o null si se puede.

        La regla, definida por Lucas el 3/8/2026: los unicos dos casos editables son
        (a) una venta a la cuenta corriente de un cliente, sin facturar, y
        (b) una venta de mostrador en un comercio SIN cajas configuradas, con un unico metodo
            de pago y sin facturar.
        El caso (a) no necesita condicion propia: attachSelectedPaymentMethods() solo adjunta
        metodos de pago cuando la venta NO va a cuenta corriente, asi que una venta de cuenta
        corriente tiene cero current_acount_payment_methods y no la alcanza el chequeo de cajas.

        POR QUE ESTA EN UN SOLO LUGAR (no duplicar la condicion en cada controlador):
        hasta el 3/8/2026 esta decision vivia UNICAMENTE en el frontend
        (se_puede_actualizar.js), escondiendo el boton. El endpoint quedaba alcanzable con la
        sesion abierta, y el modal de la venta se pinta con los datos que tenia al abrirse, asi
        que el boton podia estar visible sobre una venta que ya habia cambiado. Cada lugar que
        modifique una venta ya guardada tiene que preguntar aca.

        Recibe el modelo ya cargado para no reconsultar, y usa loadMissing para no depender de
        que quien llama se haya acordado de traer las relaciones.
    */
    static function motivo_por_el_que_no_se_puede_editar($sale, $criterio_conservador_de_tickets = false) {

        $sale->loadMissing(['afip_tickets', 'current_acount_payment_methods']);

        /*
            Que congela segun el FLUJO que pregunta (tanda correctivos 2408, items 11 y su
            acotacion). Los dos criterios conviven a proposito:

            - EDICION de la venta ($criterio_conservador_de_tickets = false, el default:
              SaleController::update() y updatePrices()): solo congelan los comprobantes CON
              CAE. Un ticket RECHAZADO no existe ante ARCA y no debe trabar el mostrador
              (decision de Lucas, 24/8/2026). Es el mismo criterio que ya usa la SPA para
              decidir si una venta "tiene factura" (!!afip_ticket.cae en sale-print-buttons).

            - ANULACION del presupuesto ($criterio_conservador_de_tickets = true, lo pasa
              BudgetController::anular()): CUALQUIER AfipTicket congela, tambien uno sin CAE.
              Anular BORRA la venta, y con un ticket pendiente-sin-respuesta el borrado
              podria dejar un CAE huerfano si ARCA aprueba despues: primero se resuelve la
              situacion con ARCA. Es la politica conservadora previa, fijada por
              tests/Feature/Presupuestos/1 (anular_con_la_venta_facturada_se_rechaza_y_no_toca_nada)
              y vigente hasta que Lucas diga lo contrario.
        */
        $tickets_que_congelan = 0;

        foreach ($sale->afip_tickets as $afip_ticket) {

            if ($criterio_conservador_de_tickets) {
                $tickets_que_congelan++;
            } else if (!is_null($afip_ticket->cae) && $afip_ticket->cae !== '') {
                $tickets_que_congelan++;
            }
        }

        if ($tickets_que_congelan >= 1) {

            return 'La venta ya fue facturada. Una venta con comprobante AFIP emitido no se puede modificar.';
        }

        if ($sale->is_cerrada) {

            return 'La venta esta cerrada y no se puede modificar.';
        }

        if ($sale->caja_id && $sale->caja_id != 0) {

            return 'La venta ya movio una caja y no se puede modificar.';
        }

        /*
            Mas de un metodo de pago: actualizar la venta rehace el reparto desde cero
            (attachSelectedPaymentMethods hace detach y vuelve a adjuntar), asi que el reparto
            original se pierde y los importes por metodo dejan de cuadrar con el total.
            Va ANTES del chequeo de cajas para que el mensaje sea el mas especifico de los dos
            cuando aplican los dos.
        */
        if (count($sale->current_acount_payment_methods) > 1) {

            return 'La venta se cobro con mas de un metodo de pago. Para modificarla hay que eliminarla y volver a cargarla.';
        }

        /*
            Con cajas configuradas, cualquier venta ya cobrada movio plata en una caja y
            editarla descuadra el arqueo. La venta a cuenta corriente no entra aca porque no
            tiene metodos de pago adjuntos.
            Se cuenta contra el user_id de la venta y no contra el usuario autenticado: el
            helper es estatico y no tiene sesion, y ademas la venta es la que define el tenant.
        */
        if (count($sale->current_acount_payment_methods) >= 1) {

            $cantidad_de_cajas = Caja::where('user_id', $sale->user_id)->count();

            if ($cantidad_de_cajas >= 1) {

                return 'El comercio tiene cajas configuradas, asi que una venta ya cobrada no se puede modificar. Para corregirla hay que eliminarla y volver a cargarla.';
            }
        }

        return null;
    }
    static function checkNotaCredito($sale, $request) {
        if ($request->save_nota_credito) {

            /*
                Mismo freno que DevolucionesController::store(): la NC del panel de Vender tampoco
                puede devolver más de lo que la venta tiene sin devolver (auditoría de stock,
                5/9/2026). SaleController::update() ya lo chequea antes de abrir la transacción y
                responde 422 con el motivo; esto es la última línea para cualquier otro llamador:
                la excepción hace que el catch de update() revierta todo y la venta no se toque.
            */
            ValidarDevolucionHelper::exigir($sale->id, $request->returned_items);

            sleep(1);
            $haber = 0;

            foreach ($request->returned_items as $item) {

                // Log::info('item:');
                // Log::info($item);

                $total_item = (float)$item['price_vender'] * (float)$item['returned_amount'];

                if (!is_null($item['discount']) && $item['discount'] != 0) {
                    $total_item -= $total_item * $item['discount'] / 100;
                }

                /*
                    * Aplica los descuentos de la venta si:
                    La venta tiene descuentos 
                    Y
                    Si es un articulo (a los cuales SIEMPRE se le aplican los descuentos de la venta)
                    O (en caso que no sea un articulo, osea que es un SERVICIO) la venta tiene en TRUE discounts_in_services
                */
                if (
                    count($sale->discounts) >= 1
                    && (
                        (isset($item['is_article']) && $item['is_article'])
                        || $sale->discounts_in_services
                    )
                ) {


                    foreach ($sale->discounts as $discount) {

                        $total_item -= (float)$discount->pivot->percentage * $total_item / 100;
                    }

                }

                /*
                    * Aplica los recargos de la venta si:
                    La venta tiene recargos 
                    Y
                    Si es un articulo (a los cuales SIEMPRE se le aplican los recargos de la venta)
                    O (en caso que no sea un articulo, osea que es un SERVICIO) la venta tiene en TRUE surchages_in_services
                */
                if (
                    count($sale->surchages) >= 1
                    && (
                        (isset($item['is_article']) && $item['is_article'])
                        || $sale->surchages_in_services
                    )
                ) {


                    foreach ($sale->surchages as $surchage) {

                        $total_item += (float)$surchage->pivot->percentage * $total_item / 100;
                    }

                }


                $haber += $total_item;

            }

            /*
             * Cuenta corriente del cliente en la moneda de la venta, igual que en
             * DevolucionesController::store(). Hasta el 24/8/2026 este call site llamaba a
             * notaCredito() con la firma VIEJA (sin $credit_account_id como primer argumento):
             * el total entraba donde va el id de cuenta, la descripcion donde va el monto, y
             * todo lo demas corrido un lugar. El unico camino que quedo desactualizado era
             * este (el panel "Nota de credito" de Vender); Devoluciones ya usaba la firma nueva.
             */
            $credit_account_id = null;

            if (!is_null($request->client_id)) {

                /** Moneda de la venta; sin moneda cargada se asume pesos (id 1), mismo criterio que DevolucionesController::get_moneda_id(). */
                $moneda_id = !is_null($sale->moneda_id) && $sale->moneda_id != 0 ? $sale->moneda_id : 1;

                $credit_account = CreditAccount::where('model_name', 'client')
                                                ->where('model_id', $request->client_id)
                                                ->where('moneda_id', $moneda_id)
                                                ->first();

                if (!is_null($credit_account)) {
                    $credit_account_id = $credit_account->id;
                }
            }

            $nota_credito = CurrentAcountHelper::notaCredito(
                $credit_account_id,
                $haber,
                $request->nota_credito_description,
                'client',
                $request->client_id,
                $sale->id,
                $request->returned_items
            );

            /*
             * checkSaldos() tambien cambio de firma con el refactor de credit_account (ahora
             * recibe el id de la cuenta, no el par model_name/model_id): se recalculan los
             * saldos de la cuenta de la NC recien creada. Solo si hay cuenta: con null adentro
             * hace CreditAccount::find(null)->id y revienta.
             */
            if (!is_null($credit_account_id)) {
                CurrentAcountHelper::checkSaldos($credit_account_id);
            }

            $ct = new Controller();
            $ct->sendAddModelNotification('client', $request->client_id, false);

            Self::returnToStock($sale, $nota_credito, $request->returned_items);

            if (!is_null($sale->afip_ticket)) {
                $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
                $afip_helper->init();
            }
        }
    }

    /**
     * Devuelve al stock lo que la nota de credito del panel de Vender marca como devuelto.
     *
     * 🔴 Va por `crear()` y no por `store()` (auditoria de stock, 5/9/2026). `store()` lee un
     * puñado de claves del request y nada mas: `nota_credito_id`, `sale_id` y el texto de
     * `concepto` se perdian, y el movimiento quedaba etiquetado con el concepto por defecto,
     * "Ingreso manual", sin venta. Dos consecuencias medibles: al borrar la venta despues,
     * DeleteSaleHelper no encontraba ninguna devolucion con concepto "Nota de credito" y reponia
     * la cantidad completa (lo devuelto volvia al stock dos veces); y en un articulo con
     * `unidades_individuales` el "Ingreso manual" multiplicaba la cantidad devuelta.
     *
     * El deposito destino solo viaja si el articulo reparte por depositos; si lleva stock global,
     * la devolucion va al global (CheckToAddress tambien lo frena, pero no hace falta llegar ahi).
     *
     * @param  \App\Models\Sale           $sale
     * @param  \App\Models\CurrentAcount  $nota_credito  La NC recien creada en cuenta corriente.
     * @param  array                      $items         `returned_items` del request de Vender.
     * @return void
     */
    static function returnToStock($sale, $nota_credito, $items) {

        foreach ($items as $item) {
            if (
                isset($item['returned_amount'])
                && !is_null($item['returned_amount'])
                && (float)$item['returned_amount'] > 0
            ) {
                $article = Article::find($item['id']);

                if (is_null($article)) {
                    continue;
                }

                $a_reponer = ValidarDevolucionHelper::unidades_a_reponer($sale, $article->id, Self::getArticleVariantId($item), (float)$item['returned_amount']);

                if ($a_reponer <= 0) {
                    continue;
                }

                $data = [
                    'model_id'                      => $article->id,
                    'amount'                        => $a_reponer,
                    'sale_id'                       => $sale->id,
                    'nota_credito_id'               => !is_null($nota_credito) ? $nota_credito->id : null,
                    'article_variant_id'            => Self::getArticleVariantId($item),
                    'concepto_stock_movement_name'  => 'Nota de credito',
                    'observations'                  => 'Nota credito Venta N° '.$sale->num,
                ];

                if (count($article->addresses) >= 1) {
                    $data['to_address_id'] = $sale->address_id;
                }

                $ct = new StockMovementController();
                $ct->crear($data, false);
            }
        }

    }

    static function attachDiscounts($sale, $discounts) {
        $sale->discounts()->detach();
        // $discounts = GeneralHelper::getModelsFromId('Discount', $discounts_id);
        foreach ($discounts as $discount) {
            $sale->discounts()->attach($discount['id'], [
                'percentage' => $discount['percentage'],
            ]);
        }
    }

    static function attachSurchages($sale, $surchages) {
        $sale->surchages()->detach();
        // $surchages = GeneralHelper::getModelsFromId('Surchage', $surchages_id);
        foreach ($surchages as $surchage) {
            $sale->surchages()->attach($surchage['id'], [
                'percentage' => $surchage['percentage']
            ]);
        }
    }

    static function check_deleted_articles_from_check($sale, $previus_articles) {
        $sale->load('articles');

        if ($sale->checked && !is_null($previus_articles)) {
            Log::info('previus_articles:');
            foreach ($previus_articles as $article) {
                Log::info($article->name);
            }
            foreach ($previus_articles as $previus_article) {
                $is_deleted = true;
                foreach ($sale->articles as $sale_article) {
                    if ($previus_article->id == $sale_article->id) {
                        $is_deleted = false;
                        Log::info('Se encontro en previus_articles el articulo id: '.$previus_article->id);
                    }
                }
                if ($is_deleted) {
                    Log::info('No se encontro el articulo en previus_articles id: '.$previus_article->id);
                    $article = [
                        'id'                    => $previus_article->id,
                        'amount'                => (float)$previus_article->pivot->amount,
                        'cost'                  => $previus_article->pivot->cost,
                        'price_vender'          => $previus_article->pivot->price,
                        'returned_amount'       => $previus_article->pivot->returned_amount,
                        'delivered_amount'      => $previus_article->pivot->delivered_amount,
                        'discount'              => $previus_article->pivot->discount,
                        'checked_amount'        => $previus_article->pivot->amount,
                        'created_at'            => Carbon::now(),
                    ];
                    Self::attachArticle($sale, $article);
                }
            }
        }
    }

    /**
     * El vendedor de una venta que entra por VENDER: el del request, o el del cliente, o el del
     * empleado que vende, o ninguno (0). Firma intacta; la regla vive en `get_seller_id_desde()`
     * para que la venta nacida de un presupuesto (`BudgetHelper::saveSale()`) resuelva el vendedor
     * con EXACTAMENTE el mismo criterio sin tener que fabricar un Request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int  Id del vendedor, o 0.
     */
    static function get_seller_id($request) {

        return Self::get_seller_id_desde($request->seller_id, $request->client_id, Self::getEmployeeId($request));
    }

    /**
     * La regla del vendedor, sin request (tanda 2 de la mision vender-lista-obligatoria,
     * 18/9/2026, item A3): primero el vendedor elegido (si es un id real), despues el del cliente,
     * despues el del empleado que vende, y si no hay ninguno, 0 (que es lo que esta columna
     * siempre uso como "sin vendedor" en el alta; `ComisionesHelper` trata 0 y null igual).
     *
     * Un `client_id` que no existe se saltea en vez de reventar: hasta hoy `Client::find()` sin
     * guarda tiraba "Trying to get property 'seller_id' of null" y se llevaba puesta el alta.
     *
     * @param  mixed     $seller_id_elegido  El `seller_id` del request (null o 0 = no eligio).
     * @param  int|null  $client_id
     * @param  int|null  $employee_id        El empleado que vende (null = el dueno).
     * @return int
     */
    static function get_seller_id_desde($seller_id_elegido, $client_id, $employee_id) {

        if (!is_null($seller_id_elegido) && $seller_id_elegido != 0) {

            return $seller_id_elegido;
        }

        if (!is_null($client_id)) {

            $client = Client::find($client_id);

            if (!is_null($client) && !is_null($client->seller_id)) {

                return $client->seller_id;
            }
        }

        if ($employee_id) {

            $employee = User::find($employee_id);

            if (!is_null($employee) && $employee->seller_id) {
                Log::info('retornando seller_id en base al empleado '.$employee->name);
                return $employee->seller_id;
            }
        }

        return 0;
    }

    static function create_current_acount($sale) {
        if (!is_null($sale->client_id)
            && Self::va_a_volver_a_la_cuenta_corriente($sale)) {

            $helper = new CurrentAcountFromSaleHelper($sale);
            $helper->crear_current_acount();
        }
    }

    /**
     * Si la venta corresponde a la cuenta corriente del cliente.
     *
     * Son las dos condiciones que create_current_acount() ya usaba, extraidas para que
     * updateCurrentAcountsAndCommissions() pueda preguntarlo ANTES de borrar el movimiento y
     * dejar el rastro. Que la decision viva en un solo lugar es justamente lo que evita que el
     * borrado y la recreacion dejen de estar de acuerdo, que es lo que pasaba en el bug.
     *
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function va_a_volver_a_la_cuenta_corriente($sale) {
        return (bool) ($sale->save_current_acount && !$sale->omitir_en_cuenta_corriente);
    }

    /**
     * El `save_current_acount` con el que NACE una venta nueva, resuelto UNA sola vez a partir
     * del request: lo que viaja, o 1 si no viaja (como CreateSaleOrderHelper).
     *
     * 🔴 Lo tienen que usar TODOS los que miran el request antes del INSERT: el create() de
     * SaleController::store() y la venta hipotetica de LimiteCreditoHelper::validar_venta_nueva().
     * Cuando el default vivia solo en el create(), un POST sin la clave contra un cliente con
     * limite de credito lo esquivaba: el tope se evaluaba con el null crudo (no va a la cuenta
     * corriente -> no hay que controlar) y la venta se guardaba con 1 y su movimiento, por
     * encima del limite. Medido el 18/9/2026 (mision vender-lista-obligatoria, tanda 2).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int  0 o 1
     */
    static function get_save_current_acount_de_venta_nueva($request) {
        if (is_null($request->save_current_acount)) {
            return 1;
        }

        return $request->save_current_acount ? 1 : 0;
    }

    static function crear_comision($sale) {
        if (!is_null($sale->seller_id)) {
            
            $helper = new ComisionesHelper($sale);
            $helper->crear_comision();
        }
    }

    static function attachArticles($sale, $articles, $previus_articles, $se_esta_confirmando_por_primera_vez, $fecha_agregado_by_article_id = [], $se_activando_discount_stock = false) {
        
        foreach ($articles as $article) {
            if (isset($article['is_article'])) {

                if (isset($article['varios_precios']) && is_array($article['varios_precios'])) {

                    foreach ($article['varios_precios'] as $otro_precio) {

                        $otro_precio['id'] = $article['id'];
                        $otro_precio['name'] = $article['name'] ?? null;
                        $otro_precio['name_vender_personalizado'] = $article['name_vender_personalizado'] ?? null;

                        if ($otro_precio['amount'] == '') {
                            $otro_precio['amount'] = 1;
                        }
                        
                        // $fecha_agregado = Self::get_fecha_agregado_for_item($otro_precio, $fecha_agregado_map);
                        Self::attachArticle($sale, $otro_precio, null);

                    }
                } else {

                    $amount = Self::getAmount($sale, $article);
                    
                    if (($sale->to_check || $sale->checked) 
                        || (!is_null($amount) && $amount > 0) ) {

                        // Log::info('Agregando el articulos: '.$article['name']);
                        $article_id = (int)$article['id'];
                        $fecha_agregado = $fecha_agregado_by_article_id[$article_id] ?? null;

                        Self::attachArticle($sale, $article, $fecha_agregado);
                    } else {
                        // Log::info('No se agrego articulo '.$article['name'].' a la venta N° '.$sale->num.'. Amount: '.$amount);
                    }

                }


                /*
                 * Se descuenta stock solo si:
                 * - la venta no está en modo depósito (to_check / checked)
                 * - la venta tiene activado discount_stock
                 * - el artículo maneja stock
                 *
                 * Cuando se activa discount_stock por primera vez en una actualización,
                 * se combina con se_esta_confirmando_por_primera_vez para que ArticleHelper
                 * descuente la cantidad total actual (no la diferencia con artículos previos).
                 */

                Log::info('to_check: '.(bool)$sale->to_check);
                Log::info('checked: '.(bool)$sale->checked);
                Log::info('discount_stock: '.(bool)$sale->discount_stock);
                if (!(bool)$sale->to_check && !(bool)$sale->checked && (bool)$sale->discount_stock && Self::usa_stock($article)) {

                    $amount = Self::getAmount($sale, $article);

                    /*
                        Con `varios_precios` el articulo va a la venta como VARIOS renglones (uno por
                        precio, cada uno con su cantidad) pero el descuento de stock se hace una sola
                        vez, aca. Lo que sale del stock es la suma de esos renglones, no la cantidad
                        del item "padre", que es la que quedo en el formulario antes de repartir.
                    */
                    if (isset($article['varios_precios']) && is_array($article['varios_precios'])) {
                        $amount = Self::get_amount_varios_precios($article['varios_precios']);
                    }

                    if (isset($article['article_variant_id'])) {
                        $article_variant_id = $article['article_variant_id'];
                    } else {
                        $article_variant_id = null;
                    }

                    // Si se activa discount_stock por primera vez, tratar como primera confirmación
                    // para que se use la cantidad total y no la diferencia con artículos previos
                    $es_primer_descuento = $se_esta_confirmando_por_primera_vez || $se_activando_discount_stock;
                    
                    // if ($amount > 0) {
                        ArticleHelper::discountStock($article['id'], $amount, $sale, $previus_articles, $es_primer_descuento, $article_variant_id);
                    // }
                } else {
                    Log::info('No se desconto stock para article_id '.$article['id']);
                }

                Self::check_que_este_el_articulos($sale, $article);

            }
        }
    }

    /**
     * Cantidad total que sale del stock con `varios_precios`: la suma de las cantidades de cada
     * renglon de precio. Un renglon sin cantidad cuenta como 1, igual que en attachArticles().
     *
     * @param  array  $varios_precios
     * @return float
     */
    static function get_amount_varios_precios($varios_precios) {
        $total = 0;
        foreach ($varios_precios as $otro_precio) {
            if (!isset($otro_precio['amount']) || $otro_precio['amount'] === '' || is_null($otro_precio['amount'])) {
                $total += 1;
            } else {
                $total += (float)$otro_precio['amount'];
            }
        }
        return $total;
    }

    static function usa_stock($article) {
        $_article = Article::find($article['id']);
        if (!is_null($_article)) {
            // Log::info('article stock: '.$_article->stock);
            return !is_null($_article->stock);
        } else {
            // Log::info('No se encontro article id '.$article['id']);
        }
        return false;
    }

    static function attachArticle($sale, $article, $fecha_agregado = null) {
        
        $delivered_amount = Self::getDeliveredAmount($article);

        $amount = Self::getAmount($sale, $article);
        $cost = Self::getCost($sale, $article);
        $price = $article['price_vender'];
        /**
         * Precio unitario sin IVA persistido para uso posterior en PDF sin recálculo.
         */
        $price_sin_iva = Self::get_price_sin_iva($article, $price);

        $ganancia = (float)$price - (float)$cost;

        $sale->articles()->attach($article['id'], [
            'amount'                => $amount,
            'ganancia'              => $ganancia * $amount,
            'cost'                  => $cost,
            'price'                 => $price,
            'price_sin_iva'         => $price_sin_iva,
            'returned_amount'       => Self::getReturnedAmount($article),
            'delivered_amount'      => $delivered_amount,
            'discount'              => Self::getDiscount($article),
            /**
             * Alícuota de IVA al momento de la venta.
             * Se persiste para que notas de crédito y devoluciones usen el mismo IVA
             * sin verse afectadas por cambios futuros en el artículo.
             */
            'iva_percentage'        => Self::get_iva_percentage_for_pivot($article),
            'checked_amount'        => Self::getCheckedAmount($sale, $article),
            'article_variant_id'    => Self::getArticleVariantId($article),
            'variant_description'    => Self::getVariantDescription($article),
            /**
             * Nombre personalizado de la línea; null si no difiere del artículo.
             */
            'name'                  => Self::get_custom_name_for_pivot($article),
            'price_type_personalizado_id'    => Self::get_price_type_personalizado($article),

            'fecha_agregado'        => $fecha_agregado,

            'created_at'            => Carbon::now(),
        ]);

        if (!is_null($delivered_amount) && !$sale->en_acopio) {
            $sale->en_acopio = 1;
            $sale->save();
        }
    }

    /**
     * Cambia el precio de los renglones de una venta ya guardada (endpoint
     * `PUT api/sale/update-prices/{id}`, `SaleController::updatePrices()`).
     *
     * 🔴 Y recalcula la GANANCIA de la línea, que hasta el 17/9/2026 no se tocaba. El pivot quedaba
     * con la ganancia del precio VIEJO —`(price_viejo − cost) × amount`— cada vez que alguien
     * editaba el precio de una venta guardada: un dato incorrecto que el sistema seguía generando
     * todos los días.
     *
     * Y no era sólo un número feo en una columna. Esa línea desincronizada cumple la firma
     * aritmética con la que `sale:sanear-costo-de-linea` reconoce las líneas que rompió
     * `set_costo_ventas`, así que el saneo la confundía con una línea a corregir y la "arreglaba"
     * sin que estuviera rota. Ese comando terminó necesitando una guarda extra para no comérselas:
     * la guarda tapa el síntoma, esto saca la causa.
     *
     * El `cost` y el `amount` se LEEN del pivot y no se recalculan: son la foto del momento de la
     * venta y este endpoint no los toca. La convención es la misma de `attachArticle()`, la ganancia
     * TOTAL de la línea y no la unitaria: `(price − cost) × amount`.
     *
     * ⚠️ Con `cost` en NULL la ganancia se persiste en NULL, que ya significa "no se puede calcular"
     * en el resto del sistema (`sales.ganancia`), en vez de inventarle un costo de cero. Pasa, por
     * ejemplo, con las líneas que deja `OrderProductionHelper::attachSaleArticles()`. Ojo con la
     * asimetría: `attachArticle()` sí le inventa el cero en ese caso (`(float) null` da 0, o sea
     * ganancia = precio × cantidad), y eso quedó como está a propósito — es el camino de creación,
     * con su propia batería de tests, y cambiarlo es una decisión aparte.
     *
     * @param  \App\Models\Sale $sale
     * @param  array $items Renglones con el precio nuevo, tal cual los manda la SPA.
     * @return void
     */
    static function updateItemsPrices($sale, $items) {
        foreach ($items as $item) {
            if (isset($item['is_article']) && $item['price_vender'] != '') {
                /**
                 * Recalcula precio sin IVA cada vez que se actualiza precio de artículo.
                 */
                $price_sin_iva = Self::get_price_sin_iva($item, $item['price_vender']);

                $cambios = [
                    'price' => $item['price_vender'],
                    'price_sin_iva' => $price_sin_iva,
                ];

                /** Línea actual, para leerle el costo y la cantidad que ya tiene guardados. */
                $linea = $sale->articles()->find($item['id']);

                if (!is_null($linea) && !is_null($linea->pivot)) {

                    $cambios['ganancia'] = Self::ganancia_de_linea(
                        $linea->pivot->cost,
                        $linea->pivot->amount,
                        $item['price_vender']
                    );
                }

                $sale->articles()->updateExistingPivot($item['id'], $cambios);
            } else if (isset($item['is_service']) && $item['price_vender'] != '') {
                $service = Service::find($item['id']);
                $service->price = $item['price_vender'];
                $service->save();
                $sale->services()->updateExistingPivot($item['id'], [
                                                        'price' => $item['price_vender'],
                                                    ]);
            }
        }
    }

    /**
     * La ganancia de UNA línea de venta, sola y sin efectos.
     *
     * 🔴 Es la ganancia TOTAL de la línea, no la unitaria: `(price − cost) × amount`. Es la
     * convención que fija `attachArticle()` al crear la venta (`'ganancia' => $ganancia * $amount`),
     * y la que respeta todo lo que lee esa columna.
     *
     * Devuelve null cuando no hay con qué calcular —sin costo o sin cantidad—, que es lo mismo que
     * significa null en `sales.ganancia`. Inventarle un costo de cero informaría como ganancia el
     * precio entero.
     *
     * @param  mixed $cost Costo UNITARIO guardado en el pivot.
     * @param  mixed $amount Cantidad de la línea.
     * @param  mixed $price Precio unitario nuevo.
     * @return float|null
     */
    static function ganancia_de_linea($cost, $amount, $price) {
        if (is_null($cost) || is_null($amount)) {
            return null;
        }

        return ((float) $price - (float) $cost) * (float) $amount;
    }

    /**
     * Obtiene la alícuota de IVA del artículo al momento de la venta para persistirla en el pivot.
     * Usa la misma jerarquía de resolución que get_price_sin_iva para mantener coherencia.
     *
     * @param array $article_data Datos del artículo recibidos desde request/flujo interno.
     * @return float|string|int Porcentaje de IVA; 21 como valor por defecto si no se resuelve.
     */
    static function get_iva_percentage_for_pivot($article_data)
    {
        /** @var float|string|int $iva_percentage Alícuota por defecto cuando no hay IVA explícito. */
        $iva_percentage = 21;

        if (isset($article_data['iva']) && isset($article_data['iva']['percentage'])) {
            $iva_percentage = $article_data['iva']['percentage'];
        } elseif (isset($article_data['iva_id']) && !is_null($article_data['iva_id'])) {
            $iva_model = Iva::find($article_data['iva_id']);
            if (!is_null($iva_model)) {
                $iva_percentage = $iva_model->percentage;
            }
        } elseif (isset($article_data['id'])) {
            $article_model = Article::find($article_data['id']);
            if (!is_null($article_model) && !is_null($article_model->iva_id)) {
                $iva_model = Iva::find($article_model->iva_id);
                if (!is_null($iva_model)) {
                    $iva_percentage = $iva_model->percentage;
                }
            }
        }

        return Self::normalize_iva_percentage_for_pivot($iva_percentage);
    }

    /**
     * Normaliza el valor de IVA que se va a persistir en las columnas iva_percentage de los pivots
     * (article_sale y article_current_acount).
     *
     * POR QUE ESAS COLUMNAS SON DE TEXTO Y NO DECIMALES (grupo 275, 30/7/2026):
     * ivas.percentage es una columna string que guarda tanto alicuotas numericas ('21', '10.5')
     * como etiquetas fiscales ('Exento', 'No Gravado'). Los pivots nacieron decimal(8,2) y cualquier
     * venta con un articulo exento se caia entera con un error de MySQL. No se puede colapsar
     * 'Exento' y 'No Gravado' a 0: ante ARCA son alicuotas distintas de 0%, y el desglose de IVA del
     * comprobante deja de cerrar. Por eso la columna espeja el tipo de su fuente. Si alguna vez se
     * quiere volver a un tipo numerico, primero hay que separar la etiqueta fiscal del porcentaje en
     * la tabla ivas, no antes.
     *
     * @param mixed $value Valor resuelto desde ivas.percentage o el default.
     * @return string|null Texto a persistir, o null si no hay valor utilizable.
     */
    static function normalize_iva_percentage_for_pivot($value)
    {
        if (is_null($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Obtiene precio unitario sin IVA para persistir en article_sale.
     *
     * @param array $article_data Datos de artículo recibidos desde request/flujo interno.
     * @param float|int|string $price_with_iva Precio unitario con IVA.
     * @return float
     */
    static function get_price_sin_iva($article_data, $price_with_iva) {
        /**
         * Se parsea como float para normalizar entradas string provenientes de formularios.
         */
        $price_with_iva = (float) $price_with_iva;
        /**
         * Valor por defecto cuando no se logra resolver IVA del artículo.
         */
        $iva_percentage = 21;

        if (isset($article_data['iva']) && isset($article_data['iva']['percentage'])) {
            $iva_percentage = $article_data['iva']['percentage'];
        } else if (isset($article_data['iva_id']) && !is_null($article_data['iva_id'])) {
            $iva_model = Iva::find($article_data['iva_id']);
            if (!is_null($iva_model)) {
                $iva_percentage = $iva_model->percentage;
            }
        } else if (isset($article_data['id'])) {
            $article_model = Article::find($article_data['id']);
            if (!is_null($article_model) && !is_null($article_model->iva_id)) {
                $iva_model = Iva::find($article_model->iva_id);
                if (!is_null($iva_model)) {
                    $iva_percentage = $iva_model->percentage;
                }
            }
        }

        /**
         * Si la condición IVA no es numérica gravada, se conserva precio original.
         */
        if (
            $iva_percentage === 'No Gravado'
            || $iva_percentage === 'Exento'
            || (float) $iva_percentage == 0
        ) {
            return round($price_with_iva, 2);
        }

        return round($price_with_iva / (((float) $iva_percentage / 100) + 1), 2);
    }

    static function attachPromocionVinotecas($sale, $promocion_vinotecas, $previus_promos) {
        foreach ($promocion_vinotecas as $promo) {
            if (isset($promo['is_promocion_vinoteca'])) {
                $sale->promocion_vinotecas()->attach($promo['id'], [
                                                            'amount' => (float)$promo['amount'],
                                                            'price' => $promo['price_vender'],
                                                            'created_at' => Carbon::now(),
                                                        ]);
                PromocionVinotecaHelper::discount_stock_promocion_vinoteca($sale, $promo, $previus_promos);
            }
        }
    }

    static function attachCombos($sale, $combos, $previus_combos) {
        foreach ($combos as $combo) {
            if (isset($combo['is_combo'])) {
                $sale->combos()->attach($combo['id'], [
                                                            'amount' => (float)$combo['amount'],
                                                            'price' => $combo['price_vender'],
                                                            'created_at' => Carbon::now(),
                                                        ]);

                ComboHelper::discount_articles_stock($sale, $combo, $previus_combos);
            }
        }
    }

    static function attachServices($sale, $services) {
        foreach ($services as $service) {
            if (isset($service['is_service'])) {
                $sale->services()->attach($service['id'], [
                    'price' => $service['price_vender'],
                    'amount' => $service['amount'],
                    'returned_amount'   => Self::getReturnedAmount($service),
                    'discount' => Self::getDiscount($service),
                ]);
            }
        }
    }

    static function updateCurrentAcountsAndCommissions($sale) {

        /*
            Se evalua ANTES de borrar si la venta va a volver a la cuenta corriente, con las
            mismas dos condiciones que decide create_current_acount(). El comportamiento no
            cambia -una venta que el usuario paso a omitida tiene que salir de la cuenta
            corriente-, lo que se gana es el rastro: el bug de San Cayetano (mision 56) saco
            ventas de la cuenta corriente de sus clientes y no dejo una sola linea en ningun lado,
            porque el borrado y la no-recreacion son dos pasos que por separado parecen correctos.
        */
        if (!is_null($sale->client_id) && !Self::va_a_volver_a_la_cuenta_corriente($sale)) {

            $current_acount_previa = CurrentAcount::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->first();

            if (!is_null($current_acount_previa)) {

                /*
                    Los dos motivos, no uno: pueden darse a la vez, y el log de una venta que se
                    fue de la cuenta corriente se lee justamente para entender por que.
                */
                $motivos = [];

                if ($sale->omitir_en_cuenta_corriente) {
                    $motivos[] = 'omitir_en_cuenta_corriente vino en '.var_export($sale->omitir_en_cuenta_corriente, true);
                }

                if (!$sale->save_current_acount) {
                    $motivos[] = 'save_current_acount vino en '.var_export($sale->save_current_acount, true);
                }

                Log::info(
                    'SaleHelper: la venta '.$sale->id.' SALE de la cuenta corriente del cliente '
                    .$sale->client_id.'. Motivo: '.implode(' y ', $motivos)
                    .'. Se borra el movimiento '.$current_acount_previa->id.' y no se recrea.'
                );
            }
        }

        Self::deleteCurrentAcountFromSale($sale);
        Self::deleteSellerCommissionsFromSale($sale);

        Self::create_current_acount($sale);
        
        Self::crear_comision($sale);

        if (!$sale->omitir_en_cuenta_corriente) {

            // $sale->client->pagos_checkeados = 0;
            // $sale->client->save();

            $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $sale->client_id)
                                        ->where('moneda_id', $sale->moneda_id)
                                        ->first();

            if (!is_null($credit_account)) {
                $sale_current_acount = CurrentAcount::where('sale_id', $sale->id)
                                                    ->where('credit_account_id', $credit_account->id)
                                                    ->whereNull('haber')
                                                    ->first();

                $has_movimientos_posteriores = !is_null($sale_current_acount)
                    && CurrentAcount::where('credit_account_id', $credit_account->id)
                                    ->where('id', '!=', $sale_current_acount->id)
                                    ->where(function ($q) use ($sale_current_acount) {
                                        $q->where('created_at', '>', $sale_current_acount->created_at)
                                          ->orWhere(function ($q2) use ($sale_current_acount) {
                                              $q2->where('created_at', '=', $sale_current_acount->created_at)
                                                 ->where('id', '>', $sale_current_acount->id);
                                          });
                                    })
                                    ->exists();

                if ($has_movimientos_posteriores) {
                    Log::info('Recalculando saldos');
                    CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
                } else {
                    Log::info('No se van a recalcular saldos');
                }
            }
        }

    }

    static function deleteCurrentAcountFromSale($sale) {
        $current_acount = CurrentAcount::where('sale_id', $sale->id)
                                        ->whereNull('haber')
                                        ->first();
        if (!is_null($current_acount)) {



            /*
                Chequeo si habia algun pago especifico (con to_pay_id) para esta venta.
                Si lo habia, lo libero para que aporte a otras ventas.

                Van TODOS, no el primero: desde el grupo 327 la nota de credito de una
                devolucion tambien apunta con to_pay_id a la venta que la origino, asi que
                una misma venta puede tener a la vez esa NC y un pago imputado a mano. Si se
                libera solo uno, el otro queda apuntando a una fila borrada y
                CurrentAcountPagoHelper::setSinPagar() lo resuelve en null: ese movimiento
                deja de imputarse por completo, sin error visible.
            */
            $pagos_dirigidos = CurrentAcount::where('to_pay_id', $current_acount->id)
                                            ->get();

            foreach ($pagos_dirigidos as $pago_dirigido) {
                $pago_dirigido->to_pay_id = null;
                $pago_dirigido->save();
            }


            // Elimino current_acount de la venta
            $current_acount->pagado_por()->detach();
            $current_acount->delete();
        }
    }

    static function deleteSellerCommissionsFromSale($sale) {
        $seller_commissions = SellerCommission::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->get(['id', 'seller_id', 'moneda_id']);

        // Grupo 268 · Prompt 02, bug E: antes se borraba sin recalcular los saldos posteriores.
        // Se guardan los pares seller_id + moneda_id afectados ANTES de destruir las filas.
        $pares = [];
        foreach ($seller_commissions as $seller_commission) {
            $moneda_id = !is_null($seller_commission->moneda_id) ? $seller_commission->moneda_id : 1;
            $pares[$seller_commission->seller_id.'-'.$moneda_id] = [
                'seller_id' => $seller_commission->seller_id,
                'moneda_id' => $moneda_id,
            ];
        }

        SellerCommission::destroy($seller_commissions->pluck('id'));

        foreach ($pares as $par) {
            ComisionesHelper::recalcular_saldos($par['seller_id'], $par['moneda_id']);
        }
    }

    static function getDiscount($item) {
        if (isset($item['discount'])) {
            return $item['discount'];
        }
        return null;
    }

    static function getAmount($sale, $article) {
        if ($sale->confirmed && isset($article['checked_amount']) && !is_null($article['checked_amount'])) {
            return (float)$article['checked_amount'];
        }
        return (float)$article['amount'];
    }

    static function getCheckedAmount($sale, $article) {
        if (isset($article['checked_amount']) && !is_null($article['checked_amount'])) {
            if ($sale->confirmed && isset($article['checked_amount']) && !is_null($article['checked_amount']) && (float)$article['checked_amount'] > 0) {
                return null;
            }
            return $article['checked_amount'];
        }
        return null;
    }

    static function getArticleVariantId($article) {
        if (isset($article['article_variant_id']) && $article['article_variant_id'] != 0) {
            return $article['article_variant_id'];
        }
        return null;
    }

    static function get_price_type_personalizado($article) {
        if (isset($article['price_type_personalizado_id']) && $article['price_type_personalizado_id'] != 0) {
            return $article['price_type_personalizado_id'];
        }
        return null;
    }

    static function getVariantDescription($article) {
        if (isset($article['article_variant_id']) && $article['article_variant_id'] != 0) {
            $article_variant = ArticleVariant::find($article['article_variant_id']);
            
            if (!is_null($article_variant)) {
                return $article_variant->variant_description;
            }
        }
        return null;
    }

    /**
     * Resuelve el nombre personalizado para persistir en article_sale.
     * Solo guarda valor si el operador modificó el nombre respecto al artículo.
     *
     * @param  array  $article  Datos del ítem enviados desde vender.
     * @return string|null
     */
    static function get_custom_name_for_pivot($article)
    {
        if (!isset($article['name_vender_personalizado'])) {
            return null;
        }

        $custom_name = trim((string) $article['name_vender_personalizado']);
        if ($custom_name === '') {
            return null;
        }

        $article_base_name = '';
        if (isset($article['name'])) {
            $article_base_name = trim((string) $article['name']);
        } else {
            $article_model = Article::find($article['id']);
            if (!is_null($article_model)) {
                $article_base_name = trim((string) $article_model->name);
            }
        }

        if ($custom_name === $article_base_name) {
            return null;
        }

        return $custom_name;
    }

    static function getReturnedAmount($item) {
        if (isset($item['returned_amount'])) {
            return $item['returned_amount'];
        }
        return null;
    }

    static function getDeliveredAmount($item) {
        if (isset($item['delivered_amount'])) {
            return $item['delivered_amount'];
        }
        return null;
    }

    static function getCost($sale, $item) {
        $user = $sale->user;
        Log::info('getCost');

        if (is_object($item)) {
            $item = json_decode(json_encode($item), true);
        }

        $cost = null;

        // Si se esta actualizando, se retorna el valor que estaba guardado (ya cotizado)
        if (
            isset($item['pivot'])
            && isset($item['pivot']['cost'])
        ) {
            Log::info('retornando del pivot: '.$item['pivot']['cost']);
            $cost = (float) $item['pivot']['cost'];
            return $cost;
        }


        // Solo truvari
        if (
            !$cost
            && isset($item['presentacion'])
        ) {

            $item_cost = (float)$item['cost'];
            if (isset($item['costo_real'])) {
                $item_cost = (float)$item['costo_real'];
            }
            
            $cost =  $item_cost * (float)$item['presentacion'];
        }


        if (!$cost) {
            if (isset($item['costo_real'])) {
                $cost = (float)$item['costo_real'];
            } else if (isset($item['cost'])) {
                $cost = (float)$item['cost'];
            }
        }

        Log::info('cost: '.$cost);

        if ($cost > 0) {

            
            if ($sale->moneda_id == 1) {
                // Pesos
                if (
                    isset($item['cost_in_dollars']) 
                    && $item['cost_in_dollars'] == 1
                    // && (
                    //     $user
                    //     && $user->cotizar_precios_en_dolares == 0
                    // )
                ) {
                    $cost *= (float)$sale->valor_dolar;
                }

            } else if ($sale->moneda_id == 2) {

                if (
                    $item['cost_in_dollars'] == 0
                    || $item['cost_in_dollars'] == '0'
                    || is_null($item['cost_in_dollars'])
                ) {
                    $cost /= (float)$sale->valor_dolar;
                }
            } 
        }

        if (!is_null($user) && $user->aplicar_descuentos_de_venta_a_costos) {
            
            foreach ($sale->discounts as $discount) {
                $cost -= $cost * $discount->pivot->percentage / 100;
            }
            foreach ($sale->surchages as $surchage) {
                $cost += $cost * $surchage->pivot->percentage / 100;
            }
        }

        if (
            isset($item['unidades_individuales'])
            && $item['unidades_individuales']
            && (float)$item['unidades_individuales'] > 0
        ) {
            $cost /= (float)$item['unidades_individuales'];
        }

        return $cost;
    }

    /**
     * Calcula el factor multiplicativo que en `getCost` se aplica al costo unitario
     * cuando el usuario tiene `aplicar_descuentos_de_venta_a_costos`: por cada descuento
     * de la venta se multiplica por (1 − porcentaje/100) y por cada recargo por (1 + porcentaje/100).
     *
     * Equivale al producto de los pasos de los bucles sobre `discounts` y `surchages` en `getCost`.
     *
     * @param \App\Models\Sale $sale Venta con relaciones `discounts` y `surchages` cargadas (pivote con `percentage`).
     * @return float|null Factor (> 0). `null` si el factor sería 0 (p. ej. descuento 100 %), no se puede revertir dividiendo.
     */
    static function sale_cost_factor_from_sale_discounts_and_surchages($sale) {
        /**
         * Acumulador del factor aplicado al costo antes de persistir en `article_sale.cost`.
         */
        $factor = 1.0;

        foreach ($sale->discounts as $discount) {
            /**
             * Porcentaje de descuento de venta asociado por pivote.
             */
            $percentage = (float) $discount->pivot->percentage;
            $factor *= (1 - $percentage / 100);
        }

        foreach ($sale->surchages as $surchage) {
            /**
             * Porcentaje de recargo de venta asociado por pivote.
             */
            $percentage = (float) $surchage->pivot->percentage;
            $factor *= (1 + $percentage / 100);
        }

        if ($factor <= 0) {
            return null;
        }

        return $factor;
    }

    /**
     * Revierte en la tabla pivote `article_sale` el efecto de descuentos y recargos a nivel venta sobre
     * el costo unitario guardado (inverso de la rama de `getCost` que aplica porcentajes de la venta).
     * Recalcula `ganancia` en pivote como (precio unitario − costo unitario) × cantidad, coherente con `attachArticle`.
     *
     * No es idempotente: ejecutar dos veces sobre la misma venta volvería a dividir costos ya corregidos.
     *
     * @param \App\Models\Sale $sale Venta con `articles`, `discounts`, `surchages` y `user` cargados.
     * @return array{articles_updated: int, reason_skipped: string|null} Conteo de filas pivote actualizadas o motivo de omisión.
     */
    static function restore_article_pivot_costs_without_sale_discounts($sale) {
        /**
         * Usuario dueño de la venta; misma referencia que usa `getCost` para el flag de costos.
         */
        $user = $sale->user;

        // if (is_null($user) || !(bool) $user->aplicar_descuentos_de_venta_a_costos) {
        //     return [
        //         'articles_updated' => 0,
        //         'reason_skipped' => 'user_missing_or_flag_off',
        //     ];
        // }

        /**
         * Sin descuentos ni recargos de venta, el factor es 1 y no hay nada que revertir a nivel venta.
         */
        if ($sale->discounts->isEmpty() && $sale->surchages->isEmpty()) {
            return [
                'articles_updated' => 0,
                'reason_skipped' => 'no_sale_discounts_or_surchages',
            ];
        }

        /**
         * Factor por el que hubo que dividir el costo persistido para obtener el costo “sin” dto/rec de venta.
         */
        $factor = Self::sale_cost_factor_from_sale_discounts_and_surchages($sale);

        if (is_null($factor)) {
            return [
                'articles_updated' => 0,
                'reason_skipped' => 'invalid_zero_factor',
            ];
        }

        /**
         * Contador de filas en pivote actualizadas.
         */
        $articles_updated = 0;

        foreach ($sale->articles as $article) {
            /**
             * Costo unitario actualmente guardado (con dto/rec de venta ya aplicados al crearse, si correspondía).
             */
            $stored_unit_cost = (float) $article->pivot->cost;

            /**
             * Costo unitario restaurado (antes de aplicar porcentajes de la venta al costo).
             */
            $restored_unit_cost = $stored_unit_cost / $factor;

            /**
             * Precio unitario en pivote y cantidad para recalcular ganancia como en `attachArticle`.
             */
            $unit_price = (float) $article->pivot->price;
            $amount = (float) $article->pivot->amount;

            /**
             * Ganancia total en línea: (precio − costo unitario restaurado) × cantidad.
             */
            $ganancia_line = ($unit_price - $restored_unit_cost) * $amount;

            $sale->articles()->updateExistingPivot($article->id, [
                'cost' => $restored_unit_cost,
                'ganancia' => $ganancia_line,
            ]);

            $articles_updated++;
        }

        return [
            'articles_updated' => $articles_updated,
            'reason_skipped' => null,
        ];
    }

    static function getDolar($article, $dolar_blue) {
        if (isset($article['with_dolar']) && $article['with_dolar']) {
            return $dolar_blue;
        }
        return null;
    }

    static function detachItems($sale, $sale_modification) {

        SaleModificationsHelper::attach_articulos_antes_de_actualizar($sale, $sale_modification);

        $sale->articles()->detach();
        $sale->combos()->detach();
        $sale->services()->detach();
        $sale->promocion_vinotecas()->detach();
    }

    static function restaurar_stock($sale) {
        foreach ($sale->articles as $article) {
            if (count($article->addresses) >= 1 && !is_null($sale->address_id)) {
                foreach ($article->addresses as $article_address) {
                    if ($article_address->pivot->address_id == $sale->address_id) {
                        $new_amount = $article_address->pivot->amount + $article->pivot->amount;
                        $article->addresses()->updateExistingPivot($article_address->id, [
                            'amount'    => $new_amount,
                        ]);
                    }
                }
            } else if (!is_null($article->stock)) {
                $stock = 0;
                $stock = (int)$article->pivot->amount;
                $article->stock += $stock;
                $article->save();
            }
            // Self::deleteStockMovement($sale, $article);
        }
    }

    static function deleteStockMovement($sale, $article) {
        $stock_movement = StockMovement::where('sale_id', $sale->id)
                                        ->where('article_id', $article->id)
                                        ->first();
        if (!is_null($stock_movement)) {
            $stock_movement->delete();
        }
    }

    static function get_sub_total($sale) {
        $total_articles = 0;
        $total_combos = 0;
        $total_services = 0;
        $total_promocion_vinotecas = 0;

        $sale->load('articles');
        $sale->load('combos');
        $sale->load('promocion_vinotecas');
        $sale->load('services');
        

        foreach ($sale->articles as $article) {
            $total_articles += Self::getTotalItem($article);
        }
        foreach ($sale->combos as $combo) {
            $total_combos += Self::getTotalItem($combo);
        }
        foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {
            $total_promocion_vinotecas += Self::getTotalItem($promocion_vinoteca);
        }
        foreach ($sale->services as $service) {
            $total_services += Self::getTotalItem($service);
        }

        $sub_total = $total_articles + $total_combos + $total_promocion_vinotecas + $total_services;

        
        return $sub_total;
    }

    static function getTotalSale($sale, $with_discount = true, $with_surchages = true, $with_seller_commissions = false, $load_info = false) {
        $total_articles = 0;
        $total_combos = 0;
        $total_services = 0;
        $total_promocion_vinotecas = 0;

        if ($load_info) {
            $sale->load('articles');
            $sale->load('combos');
            $sale->load('promocion_vinotecas');
            $sale->load('services');
            $sale->load('discounts');
            $sale->load('surchages');
        }
        

        foreach ($sale->articles as $article) {
            $total_articles += Self::getTotalItem($article);
        }
        foreach ($sale->combos as $combo) {
            $total_combos += Self::getTotalItem($combo);
        }
        foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {
            $total_promocion_vinotecas += Self::getTotalItem($promocion_vinoteca);
        }
        foreach ($sale->services as $service) {
            $total_services += Self::getTotalItem($service);
        }

        $sub_total = $total_articles + $total_combos + $total_promocion_vinotecas + $total_services;

        /*
            `sales.descuento` ES PORCENTAJE (confirmado por Lucas, 24/8/2026) y se aplica SOLO
            al total de los articulos, igual que la SPA (`vender_set_total.js::aplicar_descuento()`:
            "Aplicando descuento del X% solo al total de los articulos"). Hasta esta tanda aca se
            restaba como MONTO fijo, y toda venta con descuento que pasara por este metodo (la
            confirmacion de una venta chequeada, set_total_sales) quedaba con un total distinto
            del que calculo el front y del que factura AfipItemCalculator (que siempre lo aplico
            como porcentaje, renglon por renglon).

            La guarda truthy (y no `> 0`) es a proposito: un descuento NEGATIVO es como el
            sistema representa un recargo global, y la SPA lo aplica igual (`if (this.descuento)`).
            Mismo criterio que PuntosBaseHelper::factor_descuentos_de_venta().
        */
        if ($sale->descuento) {
            $total_articles -= $total_articles * $sale->descuento / 100;
        }

        if ($with_discount) {
            foreach ($sale->discounts as $discount) {
                $total_articles -= $total_articles * $discount->pivot->percentage / 100;
                $total_combos -= $total_combos * $discount->pivot->percentage / 100;
                $total_promocion_vinotecas -= $total_promocion_vinotecas * $discount->pivot->percentage / 100;

                if ($sale->discounts_in_services) {
                    $total_services -= $total_services * $discount->pivot->percentage / 100;
                }
            }
        }

        if (
            $with_surchages
            && !$sale->aplicar_recargos_directo_a_items
        ) {
            foreach ($sale->surchages as $surchage) {
                $total_articles += $total_articles * $surchage->pivot->percentage / 100;
                $total_combos += $total_combos * $surchage->pivot->percentage / 100;
                $total_promocion_vinotecas += $total_promocion_vinotecas * $surchage->pivot->percentage / 100;

                if ($sale->surchages_in_services) {
                    $total_services += $total_services * $surchage->pivot->percentage / 100;
                }
            }
        }

        $total = $total_articles + $total_services + $total_combos + $total_promocion_vinotecas;
        

        if ($sale->cuota_id) {
            
        }

        foreach ($sale->current_acount_payment_methods as $payment_method) {
            // if ($payment_method->pivot->cuota_id)
        }

       

        if ($with_seller_commissions) {
            foreach ($sale->seller_commissions as $seller_commission) {
                $total -= $seller_commission->debe;
            }
        }

        /*
            EL TOTAL FORZADO VA ULTIMO, DESPUES DE TODO (mision forzar-total-por-monto, 17/9/2026).
            Ver `aplicar_forzar_total_monto()` justo abajo para el porque del lugar y de la guarda.

            🔴 POR QUE AL FINAL Y NO EN EL MEDIO, que es donde se estaria tentado de ponerlo al
            lado de `$sale->descuento`. El monto es la diferencia contra EL TOTAL QUE VIO EL
            VENDEDOR EN PANTALLA: la venta daba 4.012 y el cliente pago 4.000, entonces el monto
            es -12 sobre el total completo. Si se aplicara antes de los descuentos y recargos, esos
            porcentajes caerian tambien sobre el monto forzado y el total dejaria de dar 4.000
            exacto, que es lo unico que el forzado promete.

            Y por eso mismo se aplica al `$total` ya armado y no a `$total_articles`: ESE es el
            defecto que esta mision viene a cerrar. La extension `forzar_total` vieja mandaba un
            porcentaje que se restaba solo a los articulos, y despues el total se rearmaba sumando
            articulos + servicios + combos + promociones. En una venta con servicios o combos el
            numero forzado no aparecia por ningun lado.

            La guarda de null va adentro de `get_forzar_total_monto()`: sin forzado esto es una
            suma de cero y el camino comun no cambia en nada.
        */
        $total = Self::aplicar_forzar_total_monto($sale, $total);

        return $total;
    }

    /**
     * Le aplica a un total el monto del forzado, con la guarda del total negativo.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 POR QUE ESTA GUARDA EXISTE DEL LADO DEL BACK Y NO ALCANZA CON LA DE VENDER
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  El monto queda FIJO una vez aplicado, igual que cualquier descuento de venta. El caso borde
     *  es que los renglones de la venta cambien despues de forzar hasta que el total base quede por
     *  debajo del monto: ahi el forzado ya no describe nada y aplicarlo deja un total negativo.
     *
     *  En VENDER eso lo cubre el vendedor, que ve el numero. Pero hay un camino donde el total se
     *  recalcula SIN QUE LA SPA PARTICIPE: `attachProperies()` llama a `update_total_sale()` cuando
     *  una venta `to_check` se confirma por primera vez, justamente porque el total que mando
     *  VENDER no contempla las unidades chequeadas por el deposito. Medido: comercio con
     *  `check_sales`, venta forzada a $4.000 (monto -12), el deposito chequea un solo item de $10
     *  -> el total daria **-2**, y ese numero sigue derecho a la cuenta corriente y al importe del
     *  medio de pago. Nadie lo mira en el camino.
     *
     *  🔴 Y NO SE CLAMPEA A CERO EN SILENCIO. Un total pisado a 0 sin que nadie lo diga es plata que
     *  desaparece sin rastro: la venta queda cobrada en cero y no hay nada en la fila ni en el log
     *  que explique por que. Se devuelve el total SIN forzar —que es un numero real, el de los
     *  renglones que quedaron— y se deja el warning con los dos numeros para poder reconstruirlo.
     *  Es el mismo criterio que toma `AfipItemCalculator::get_factor_total_forzado()` cuando su
     *  base no da, y el mismo que pide la SPA en `aplicar_forzar_total_monto()`.
     *
     * @param  \Illuminate\Database\Eloquent\Model|object  $sale   Venta.
     * @param  float                                       $total  Total ya calculado, sin el forzado.
     * @return float
     */
    static function aplicar_forzar_total_monto($sale, $total) {

        /** Monto con signo. 0 = la venta no se forzo y no hay nada que hacer. */
        $monto = Self::get_forzar_total_monto($sale);

        if ($monto == 0) {
            return $total;
        }

        /** El total que quedaria al aplicar el monto. */
        $forzado = $total + $monto;

        if ($forzado < 0) {

            Log::warning(
                'SaleHelper: la venta '.(isset($sale->id) ? $sale->id : '?').' tiene forzar_total_monto ('.$monto.
                ') pero sus renglones suman '.$total.', asi que el total forzado daria '.$forzado.
                '. Se descarta el forzado y se deja el total sin forzar: los items cambiaron despues de forzar.'
            );

            return $total;
        }

        return $forzado;
    }

    /**
     * El monto del total forzado de una venta o un presupuesto, normalizado a float.
     *
     * Es el unico lector de `forzar_total_monto` del repo: cualquier otro lugar que necesite el
     * monto pasa por aca, para que la guarda de null y el casteo no se repitan (ni se olviden) en
     * cada llamador. `BudgetHelper` tambien lo usa, por eso recibe un modelo generico y no una
     * `Sale`.
     *
     * NEGATIVO = descuento, POSITIVO = recargo, NULL = no se forzo nada. La semantica completa
     * esta en la migracion `2026_09_17_100000_add_forzar_total_monto_to_sales_and_budgets_tables`.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  ⚠️ POR QUE `isset()` Y NO `is_null($model->forzar_total_monto)`
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  NO es para tolerar una base sin migrar. Esa garantia seria FALSA y conviene decirlo, porque
     *  es lo primero que uno piensa: contra una base sin la columna, el alta de ventas ya revento
     *  mucho antes de llegar aca —`SaleController` manda la clave en el INSERT sin ninguna guarda—,
     *  asi que defender la lectura no salva nada.
     *
     *  La razon real es que este helper recibe modelos que NO SON `Sale` NI `Budget` y que nunca
     *  van a tener la columna, ni aunque todas las migraciones esten corridas:
     *
     *    - `OrderProductionPdf.php:148` le pasa un `OrderProduction` a `BudgetHelper::getTotal()`,
     *      que termina llamando acá. Ese modelo no tiene nada que ver con presupuestos.
     *    - `AfipWsfeHelper.php:664` le pasa un `AfipTicket` a `getTotalSale()` (hoy detras de un
     *      `return null` incondicional, o sea inalcanzable — pero la forma de la llamada existe y
     *      el dia que se destrabe va a entrar por acá).
     *
     *  Sobre esos modelos, `is_null($model->forzar_total_monto)` tira un warning por cada renglon
     *  del comprobante. `isset()` los cubre y de paso cubre el null, con la misma linea.
     *
     * @param  \Illuminate\Database\Eloquent\Model|object  $model  Venta o presupuesto.
     * @return float  0 si no hay forzado.
     */
    static function get_forzar_total_monto($model) {

        if (is_null($model) || !isset($model->forzar_total_monto)) {
            return 0.0;
        }

        return (float) $model->forzar_total_monto;
    }

    /**
     * Normaliza el `forzar_total_monto` que llega en un request antes de persistirlo.
     *
     * Devuelve null (= no hubo forzado) cuando el campo no viene, viene null, viene no numerico o
     * vale cero; y el monto redondeado a dos decimales en cualquier otro caso.
     *
     * 🔴 EL CERO SE GUARDA COMO NULL A PROPOSITO, y no como 0.00. Un cero seria "se forzo el total
     * y dio justo lo mismo", que es indistinguible de no haber forzado nada pero hace que TODOS
     * los lectores tengan que preguntar por las dos cosas: el renglon del comprobante, la caja de
     * totales del PDF y el prorrateo de AFIP pasarian a mostrar y a calcular un ajuste de $0. Con
     * null, la unica pregunta que hay que hacerse en todo el repo es "hay monto o no hay".
     *
     * ⚠️ `is_numeric()` y no un cast pelado: un `(float) 'cuatro mil'` da 0.0 en silencio, y ese
     * cero terminaria guardado como "no se forzo nada" sobre una venta que el vendedor SI forzo.
     * Preferimos que un payload roto no escriba la columna a que escriba un numero inventado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return float|null
     */
    static function normalized_forzar_total_monto($request) {

        if (!$request->exists('forzar_total_monto')) {
            return null;
        }

        $monto = $request->forzar_total_monto;

        if (is_null($monto) || !is_numeric($monto)) {
            return null;
        }

        $monto = round((float) $monto, 2, PHP_ROUND_HALF_UP);

        if ($monto == 0) {
            return null;
        }

        return $monto;
    }

    static function total_menos_comisiones($sale) {
        $total = $sale->total;
        foreach ($sale->seller_commissions as $seller_commission) {
            $total -= $seller_commission->debe;
        }
        return $total;
    }

    static function getTotalItem($item) {
        $amount = $item->pivot->amount;
        // if (!is_null($item->pivot->returned_amount)) {
        //     $amount -= $item->pivot->returned_amount;
        // }
        // Log::info('getTotalItem:');
        // Log::info('amount: '.$amount);
        // Log::info('price: '.$item->pivot->price);
        $total = $item->pivot->price * $amount;
        // Log::info('total: '.$total);
        if (!is_null($item->pivot->discount)) {
            $total -= $total * ($item->pivot->discount / 100);
        }
        return $total;
    }

    static function getTotalSaleFromArticles($sale, $articles) {
        $total = 0;
        foreach ($articles as $article) {
            if (!is_null($sale->percentage_card)) {
                $total += ($article->pivot->price * Numbers::percentage($sale->percentage_card)) * $article->pivot->amount;
            } else {
                $total += $article->pivot->price * $article->pivot->amount;
            }
        }
        return $total;
    }

    static function getTotalCostSale($sale) {
        $total = 0;
        foreach ($sale->articles as $article) {
            if (!is_null($article->pivot->cost)) {
                $total += $article->pivot->cost * $article->pivot->amount;
            }
        }
        return $total;
    }

    static function isSaleType($sale_type_name, $sale) {
        $sale_type = SaleType::where('user_id', UserHelper::userId())
                                    ->where('name', $sale_type_name)
                                    ->first();
        if (!is_null($sale_type) && $sale->sale_type_id == $sale_type->id) {
            return true;
        } 
        return false;
    }

    static function getPrecioConDescuento($sale) {
        // $discount = DiscountHelper::getTotalDiscountsPercentage($sale->discounts, true);
        $total = Self::getTotalSale($sale);
        foreach ($sale->discounts as $discount) {
            $total -= $total * Numbers::percentage($discount->pivot->percentage); 
        }
        return $total;
        // return Self::getTotalSale($sale) - (Self::getTotalSale($sale) * Numbers::percentage($discount));
    }

    static function getPrecioConDescuentoFromArticles($sale, $articles) {
        $discount = DiscountHelper::getTotalDiscountsPercentage($sale->discounts, true);
        $total = 0;
        foreach ($articles as $article) {
            if (!is_null($sale->percentage_card)) {
                $total += ($article->pivot->price * Numbers::percentage($sale->percentage_card)) * $article->pivot->amount;
            } else {
                $total += $article->pivot->price * $article->pivot->amount;
            }
        }
        return $total - ($total * Numbers::percentage($discount));
    }

    static function getTotalWithDiscountsAndSurchages($sale, $total_articles, $total_combos, $total_services) {
        foreach ($sale->discounts as $discount) {
            // Log::info('total_services: '.$total_services);
            if ($sale->discounts_in_services) {
                // Log::info('restando '.$total_services * Numbers::percentage($discount->pivot->percentage).' a los servicios');
                $total_services -= $total_services * Numbers::percentage($discount->pivot->percentage);
            } else {
                // Log::info('No se resto a los servicios');
            }
            // Log::info('total_services quedo en: '.$total_services);

            // Log::info('------------------------------------');
            // Log::info('total_articles: '.$total_articles);
            $total_articles -= $total_articles * Numbers::percentage($discount->pivot->percentage);
            // Log::info('total_articles quedo en: '.$total_articles);

            // Log::info('------------------------------------');
            // Log::info('total_combos: '.$total_combos);
            $total_combos -= $total_combos * Numbers::percentage($discount->pivot->percentage);
            // Log::info('total_combos quedo en: '.$total_combos);
        }
        foreach ($sale->surchages as $surchage) {
            if ($sale->surchages_in_services) {
                $total_services += $total_services * Numbers::percentage($surchage->pivot->percentage);
            }
            $total_articles += $total_articles * Numbers::percentage($surchage->pivot->percentage);
            $total_combos += $total_combos * Numbers::percentage($surchage->pivot->percentage);
        }
        if (!is_null($sale->order) && !is_null($sale->order->cupon)) {
            if (!is_null($sale->order->cupon->percentage)) {
                $total -= $total * $sale->order->cupon->percentage / 100;
            } else if (!is_null($sale->order->cupon->amount)) {
                $total -= $sale->order->cupon->amount;
            }
        }
        $total = $total_articles + $total_combos + $total_services;
        Log::info('------------------------------------');
        Log::info('retornando '.$total);

        return $total;
    }

    static function getTotalMenosDescuentos($sale, $total) {
        foreach ($sale->discounts as $discount) {
            $total -= $total * Numbers::percentage($discount->pivot->percentage);
        }
        return $total;
    }

    static function get_fecha_agregado_map_for_normal_articles($request_items, $previus_articles)
    {
        $now = Carbon::now();

        // Mapa: article_id => fecha_agregado previa (para preservarla si ya existía)
        $previus_fecha_agregado_by_id = [];
        $previus_ids = [];

        foreach ($previus_articles as $article) {
            $id = (int)$article->id;
            $previus_ids[$id] = true;

            // preserva si ya tenía fecha (por un update anterior); si no, queda null
            $previus_fecha_agregado_by_id[$id] = $article->pivot->fecha_agregado ?? null;
        }

        // ids "normales" que vienen en el request (sin varios_precios)
        $new_normal_ids = [];
        foreach ($request_items as $item) {
            if (!isset($item['is_article'])) {
                continue;
            }
            if (isset($item['varios_precios']) && is_array($item['varios_precios'])) {
                // NO nos interesa para fecha_agregado
                continue;
            }
            $new_normal_ids[(int)$item['id']] = true;
        }

        // Armar map final: article_id => fecha_agregado a guardar
        $result = [];

        foreach (array_keys($new_normal_ids) as $article_id) {

            $existed_before = isset($previus_ids[$article_id]);

            if (!$existed_before) {
                // NUEVO artículo normal agregado en este update
                $result[$article_id] = $now;
            } else {
                // Ya existía: preservar (probablemente null si era de creación)
                $result[$article_id] = $previus_fecha_agregado_by_id[$article_id] ?? null;
            }
        }

        return $result;
    }

    // static function build_article_sale_key_from_item($item)
    // {
    //     $article_id = (int) $item['id'];

    //     $article_variant_id = $item['article_variant_id'] ?? null;
    //     $price_type_personalizado_id = $item['price_type_personalizado_id'] ?? null;

    //     $price = $item['price_vender'] ?? null;

    //     return implode('|', [
    //         (string)$article_id,
    //         (string)($article_variant_id ?? ''),
    //         // (string)($price_type_personalizado_id ?? ''),
    //         // (string)($price ?? ''),
    //     ]);
    // }

    // static function get_fecha_agregado_for_item($item, $fecha_agregado_map)
    // {
    //     $key = Self::build_article_sale_key_from_item($item);
    //     return $fecha_agregado_map[$key] ?? null;
    // }
}
