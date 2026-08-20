<?php

namespace App\Http\Controllers;

use App\Exceptions\SaleWhatsappSendException;
use App\Exports\SalesBreakdownExport;
use App\Exports\SalesFullExport;
use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CajaHelper;
use App\Http\Controllers\Helpers\ComercioCityMailHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeleteSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleChartHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SaleModificationsHelper;
use App\Http\Controllers\Helpers\SaleProviderOrderHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\ventasTerminadas\VentaTerminadaComisionesHelper;
use App\Http\Controllers\Helpers\sale\AcopioHelper;
use App\Http\Controllers\Helpers\sale\SaleArticlesEagerLoadHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Http\Controllers\Helpers\sale\SaleNotaCreditoAfipHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Jobs\SendSaleWhatsappJob;
use App\Services\SaleWhatsappSenderService;
use App\Http\Controllers\Pdf\EtiquetaEnvioPdf;
use App\Http\Controllers\Pdf\NewSalePdf;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
use App\Http\Controllers\Pdf\SaleDeliveredArticlesPdf;
use App\Http\Controllers\Pdf\SalePdf;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Http\Controllers\Pdf\SaleTicketRaw;
use App\Http\Controllers\SellerCommissionController;
use App\Models\AfipTicket;
use App\Models\SaleDeliveryInfo;
use App\Models\SaleSenderInfo;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\SaleModification;
use App\Models\User;
use Carbon\Carbon;
use App\Services\DemoEventoEmitter;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class SaleController extends Controller
{


    public function index($modulo, $from_date = null, $until_date = null) {
        $models = Sale::where('user_id', $this->userId())
                        /** Excluye ventas contenedoras de facturación del listado general de ventas. */
                        // ->soloVentasReales()
                        ->orderBy('created_at', 'DESC')
                        ->withAll();

        SaleArticlesEagerLoadHelper::apply_images_if_preferred($models, $this->userId());

        if ($modulo == 'por_entregar') {

            $models = $models->where(function($query) {
                                    $query->where('to_check', 1)
                                          ->orWhere('checked', 1)
                                          ->orWhere('confirmed', 1);
                                })->where('terminada', 0);

        } else if ($modulo == 'por_estado') {
            
            $models = $models->whereNotNull('sale_status_id')
                                ->where('sale_status_id', '!=', 0);
                                
        } else if ($modulo == 'ventas') {

            $models = $models->where('terminada', 1)
                            ->where(function ($q) {
                                $q->whereNull('sale_status_id')
                                    ->orWhere('sale_status_id', 0);
                            });
        }

        if (!is_null($from_date)) {

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        } 
        // else {

        //     // Si entra aca es porque se esta llamando desde DEPOSITO
        //     // Porque solo de esa seccion se puede llamar sin que sea from_date

        //     $models = $models->where(function($query) {
        //                             $query->where('to_check', 1)
        //                                   ->orWhere('checked', 1)
        //                                   ->orWhere('confirmed', 1);
        //                         })->where('terminada', 0);
            
        // }
        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function por_entregar($from_depositos, $from_date = null) {
        $models = Sale::where('user_id', $this->userId())
                        /** Las consolidadas nunca tienen pendientes de entrega: siempre terminadas. */
                        ->soloVentasReales()
                        ->orderBy('created_at', 'DESC')
                        ->withAll();

        SaleArticlesEagerLoadHelper::apply_images_if_preferred($models, $this->userId());

        $models = $models->where('terminada', 0)
                        ->whereBetween('fecha_entrega', [$from_depositos, $from_date])
                        ->get();

        return response()->json(['models' => $models], 200);
    }

    function show($id) {
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    function venta_ya_cread($request) {
        $sale_ya_creada = Sale::where('user_id', $this->userId())
                                ->where('client_id', $request->client_id)
                                ->where('employee_id', SaleHelper::getEmployeeId($request))
                                ->where('total', $request->total)
                                ->where('created_at', '>=', Carbon::now()->subSeconds(5))
                                ->orderBy('created_at', 'DESC')
                                ->first();
        if (!is_null($sale_ya_creada)) {
            Log::info('Casi se vuelve a crear venta N° '.$sale_ya_creada->num.'. Total: '.$sale_ya_creada->total.'. Hora: '.$sale_ya_creada->created_at->format('H:i:s'));
            return true;
        }
        return false;
    }

    public function store(Request $request) {

        DB::beginTransaction();

        Log::info($this->user(false)->name.' va a crear venta');

        if ($this->venta_ya_cread($request)) {
            Log::info('No se volvio a crear la venta');
            return;
        }

        try {

            /** Checkbox "Enviar correo" en vender: sin extensión no se persiste ni se encola mail. */
            $can_enviar_mail_a_clientes = UserHelper::hasExtencion('enviar_mail_a_clientes');

            $model = Sale::create([
                'num'                               => $this->num('sales'),
                'client_id'                         => $request->client_id,
                'sale_type_id'                      => $request->sale_type_id,
                'observations'                      => $request->observations,
                'observations_ocultas'              => $request->observations_ocultas,
                'address_id'                        => $request->address_id,
                'current_acount_payment_method_id'  => SaleHelper::getCurrentAcountPaymentMethodId($request),
                'afip_information_id'               => $request->afip_information_id,
                'save_current_acount'               => $request->save_current_acount,
                'omitir_en_cuenta_corriente'        => $request->omitir_en_cuenta_corriente,
                'price_type_id'                     => $request->price_type_id,
                'discounts_in_services'             => $request->discounts_in_services,
                'surchages_in_services'             => $request->surchages_in_services,
                'employee_id'                       => SaleHelper::getEmployeeId($request),
                'to_check'                          => $request->to_check,
                'confirmed'                         => SaleHelper::get_confirmed($request->to_check),
                'numero_orden_de_compra'            => $request->numero_orden_de_compra,
                'sub_total'                         => $request->sub_total,
                'total'                             => $request->total,
                'terminada'                         => SaleHelper::get_terminada($request->to_check, $request->fecha_entrega),
                'terminada_at'                      => SaleHelper::get_terminada_at($request->to_check, $request->fecha_entrega),
                'seller_id'                         => SaleHelper::get_seller_id($request),
                'cantidad_cuotas'                   => $request->cantidad_cuotas,
                'cuota_descuento'                   => $request->cuota_descuento,
                'cuota_recargo'                     => $request->cuota_recargo,
                'caja_id'                           => $request->caja_id,
                'afip_tipo_comprobante_id'          => $request->afip_tipo_comprobante_id,
                'fecha_entrega'                     => $request->fecha_entrega,
                'moneda_id'                         => !is_null($request->moneda_id) ? $request->moneda_id : 1,
                'valor_dolar'                       => $request->valor_dolar,
                'incoterms'                         => $request->incoterms,
                'aplicar_recargos_directo_a_items'  => $request->aplicar_recargos_directo_a_items,
                'sale_status_id'                    => $request->sale_status_id,
                // Si no se envía el campo, se asume true (comportamiento por defecto: descontar stock)
                'discount_stock'                    => !is_null($request->discount_stock) ? $request->discount_stock : 1,
                // Si no se envía el campo, se asume true (comportamiento por defecto: precios con IVA).
                'iva_aplicado'                      => !is_null($request->iva_aplicado) ? $request->iva_aplicado : 1,
                'descuento'                         => round($request->descuento, 2, PHP_ROUND_HALF_UP),
                'user_id'                           => $this->userId(),
                // Array de descripciones del cálculo del precio final, serializado como JSON desde el frontend
                'price_description'                 => $request->price_description,
                'send_mail'                         => $can_enviar_mail_a_clientes && !is_null($request->send_mail) ? (bool) $request->send_mail : false,
                // Log detallado de acciones en vender serializado desde frontend.
                'log'                               => $request->log,
                // Umbral opcional de días para alertas de cobro (null => reglas globales de usuario).
                'dias_alerta_venta_no_cobrada_personalizado' => $this->normalized_dias_alerta_venta_no_cobrada_personalizado($request),
            ]);

            if (is_null($model->price_type_id)) {
                if (!is_null($model->client) && !is_null($model->client->price_type_id)) {
                    $model->price_type_id = $model->client->price_type_id;
                    $model->save();
                }
            }

            SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar($model, $this);

            SaleHelper::attachProperies($model, $request);

            SaleHelper::set_total_a_facturar($model, $request);

            SaleProviderOrderHelper::createProviderOrder($model, $this);

            // Por el error de Pack
            // SaleHelper::check_que_esten_todos_los_articulos($model);

            $this->sendAddModelNotification('Sale', $model->id);

            SaleHelper::sendUpdateClient($this, $model);

            Log::info('Se creo sale n°: '.$model->num.'. Total: '.$model->total);

            // $total_helper = (int)SaleHelper::getTotalSale($model, true, true, false, true);
            // $total_sale = (int)$model->total;

            // // Calcula la diferencia absoluta
            // $diferencia = abs($total_helper - $total_sale);

            // if ($diferencia > 3) {
            //     Log::info('Total mal para la venta '.$model->id);
            //     Log::info('total_sale '.$total_sale);
            //     Log::info('total_helper '.$total_helper);

            //     $message = 'El total de la venta no corresponde con los productos ingresados';
                
            //     throw new Exception($message);
            // }

            DB::commit();

            ComercioCityMailHelper::new_sale($model);

            /**
             * Envío automático opcional del comprobante por WhatsApp (grupo 137, Prompt 05).
             * Se despacha DESPUÉS del commit, nunca dentro de la transacción de guardado: si
             * el envío falla, la venta ya guardada no se ve afectada. El job revalida config
             * activa, opt-in (auto_send_sale_pdf) y cliente con teléfono por su cuenta.
             */
            SendSaleWhatsappJob::dispatch($model->id);

            /**
             * Evento de la demo (mision 50). Igual que el WhatsApp de arriba, va DESPUES del
             * commit y nunca adentro de la transaccion: un evento emitido dentro de una
             * transaccion que despues revierte le reporta al roadmap una venta que no existe.
             *
             * En una instancia de cliente real no cuesta ni una query: la primera guarda del
             * emisor mira el marcador de sesion de demo y sale.
             */
            DemoEventoEmitter::emitir('venta.creada', null, ['id' => $model->id]);

            return response()->json(['model' => $this->fullModel('Sale', $model->id)], 201);

        } catch(\Throwable $e) {

            DB::rollBack();

            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }


    }  

    function update(Request $request, $id) {

        $sale_a_actualizar = Sale::find($id);

        if (is_null($sale_a_actualizar)) {

            return response()->json(['message' => 'La venta no existe o ya fue eliminada.'], 404);
        }

        $motivo = SaleHelper::motivo_por_el_que_no_se_puede_editar($sale_a_actualizar);

        if (!is_null($motivo)) {

            Log::info('update sale id '.$id.': rechazado. '.$motivo);

            return response()->json(['message' => $motivo], 409);
        }

        DB::beginTransaction();

        Log::info('Se va a actualizar venta id: '.$id);
        try {

            /** Misma regla que en store: send_mail solo si el comercio tiene la extensión. */
            $can_enviar_mail_a_clientes = UserHelper::hasExtencion('enviar_mail_a_clientes');

            $model = Sale::where('id', $id)
                            ->with('articles')
                            ->first();

            $previus_articles = $model->articles;
            $previus_combos = $model->combos;
            $previus_promos = $model->promocion_vinotecas;

            $request->items = array_reverse($request->items);

            $sale_modification = SaleModificationsHelper::create($model, $this);

            $se_esta_confirmando = SaleHelper::get_se_esta_confirmando($request, $model);

            SaleHelper::detachItems($model, $sale_modification);
            
            $previus_client_id                          = $model->client_id;

            // Guardamos el valor anterior de discount_stock antes de modificar el modelo
            $old_discount_stock = $model->discount_stock;
            
            $model->actualizandose_por_id               = null;
           
            $model->discounts_in_services               = $request->discounts_in_services;
            
            $model->surchages_in_services               = $request->surchages_in_services;
            
            /*
                Solo si el request manda la clave. A secas, un payload que no la incluye la deja
                en null y la venta cambia de comportamiento sin que nadie la haya tocado. Mismo
                patron que dias_alerta_venta_no_cobrada_personalizado, mas abajo en esta funcion.
                Un null explicito enviado por el front sigue siendo un valor valido y se asigna.
            */
            if ($request->exists('current_acount_payment_method_id')) {
                $model->current_acount_payment_method_id = $request->current_acount_payment_method_id;
            }

            $model->afip_information_id                 = $request->afip_information_id;
            
            $model->address_id                          = $request->address_id;
            
            $model->sale_type_id                        = $request->sale_type_id;
            
            $model->observations                        = $request->observations;
            $model->observations_ocultas                = $request->observations_ocultas;

            $model->to_check                            = $request->to_check;
            
            $model->checked                             = $request->checked;
            
            $model->confirmed                           = $request->confirmed;
            
            $model->client_id                           = $request->client_id;
            
            /*
                Idem: sin la guarda, un request que no manda la clave deja el campo en null, la
                venta pasa a "no omitida" y SaleHelper la mete en la cuenta corriente del cliente
                sin que nadie lo haya pedido. Es el campo del bug de San Cayetano.
            */
            if ($request->exists('omitir_en_cuenta_corriente')) {
                $model->omitir_en_cuenta_corriente = $request->omitir_en_cuenta_corriente;
            }

            $model->numero_orden_de_compra              = $request->numero_orden_de_compra;
            
            $model->seller_id                           = $request->seller_id;

            $model->sub_total                           = $request->sub_total;
            
            $model->total                               = $request->total;

            $model->fecha_entrega                       = $request->fecha_entrega;
            
            $model->aplicar_recargos_directo_a_items    = $request->aplicar_recargos_directo_a_items;
            $model->sale_status_id                      = $request->sale_status_id;

            /*
             * discount_stock solo puede activarse, nunca desactivarse una vez que ya fue activado.
             * Si ya estaba en 1 (ya se descontó stock), ignoramos el valor enviado por el front.
             */
            if (!$old_discount_stock) {
                $model->discount_stock = !is_null($request->discount_stock) ? $request->discount_stock : 1;
            }
            
            /*
             * iva_aplicado puede activarse y desactivarse libremente en una actualización.
             * Si no viene en el request, se preserva el comportamiento por defecto (1).
             */
            $model->iva_aplicado = !is_null($request->iva_aplicado) ? $request->iva_aplicado : 1;

            /*
             * Flag para indicar que discount_stock se activa por primera vez en esta actualización.
             * En ese caso el stock debe descontarse por la cantidad total actual (no la diferencia),
             * ya que antes no se había descontado stock para esta venta.
             */
            $se_activando_discount_stock = !$old_discount_stock && $model->discount_stock;

            // Array de descripciones del cálculo del precio final, serializado como JSON desde el frontend
            $model->price_description                   = $request->price_description;

            /** Sin extensión no se altera send_mail (no borrar histórico en ventas ya marcadas). */
            if ($can_enviar_mail_a_clientes) {
                $model->send_mail = !is_null($request->send_mail) ? (bool) $request->send_mail : false;
            }
            // Log detallado de acciones en vender serializado desde frontend.
            $model->log                                 = $request->log;

            // Umbral opcional de días para alertas de cobro (solo si el cliente envía la clave; permite limpiar con null).
            if ($request->exists('dias_alerta_venta_no_cobrada_personalizado')) {
                $model->dias_alerta_venta_no_cobrada_personalizado = $this->normalized_dias_alerta_venta_no_cobrada_personalizado($request);
            }

            // $model->valor_dolar                         = $request->valor_dolar;
            
            $model->employee_id                         = SaleHelper::getEmployeeId($request);
            
            $model->updated_at                          = Carbon::now();
            
            $model->save();

            SaleHelper::attachProperies($model, $request, false, $previus_articles, $previus_combos, $previus_promos, $sale_modification, $se_esta_confirmando, $se_activando_discount_stock);

            $model->updated_at = Carbon::now();
            $model->save();

            $model = Sale::find($model->id);
            
            if ($model->client_id && !$model->to_check && !$model->checked) {
                SaleHelper::updateCurrentAcountsAndCommissions($model);
            }


            $sale_modification->estado_despues_de_actualizar = SaleModificationsHelper::get_estado($model);
            $sale_modification->save();

            DB::commit();

            $this->sendAddModelNotification('Sale', $model->id);
            SaleHelper::sendUpdateClient($this, $model);

            /** Misma regla que el checkbox en vender: sin extensión no se encola correo aunque send_mail siga en true. */
            if ($can_enviar_mail_a_clientes) {
                ComercioCityMailHelper::new_sale($model, true);
            }

            /**
             * Envío automático opcional del comprobante por WhatsApp (grupo 137, Prompt 05).
             * Mismo punto que el mail de arriba: después del commit, nunca dentro de la
             * transacción. El job revalida config activa, opt-in (auto_send_sale_pdf) y
             * cliente con teléfono por su cuenta.
             */
            SendSaleWhatsappJob::dispatch($model->id);

            return response()->json(['model' => $this->fullModel('Sale', $model->id)], 200);
        
        } catch(\Throwable $e) {
            DB::rollBack();
            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);
            return response()->json(['error' => true], 500);
        } 
    }

    public function destroy(Request $request, $id) {
        // Obtiene la venta por ID
        $model = Sale::find($id);

        // Verifica que la venta existe antes de continuar
        if (is_null($model)) {
            Log::info('destroy sale: no existe la venta id '.$id.'. Se responde 404 sin tocar nada.');
            return response()->json(['message' => 'La venta no existe o ya fue eliminada.'], 404);
        }

        /** Si el cliente pidió compensar caja, se valida que todas las cajas involucradas estén abiertas antes de tocar la venta. */
        $compensar_caja = $request->boolean('compensar_caja');
        /** Helper reutilizable para verificación y movimientos compensatorios al borrar. */
        $helper_caja_compensacion = new DeleteCajaCompensacionHelper();
        if ($compensar_caja) {
            $model->loadMissing('current_acount_payment_methods');
            $cajas_cerradas = $helper_caja_compensacion->verificar_cajas_abiertas($model->current_acount_payment_methods);
            if (count($cajas_cerradas)) {
                return response()->json([
                    'message' => 'Las siguientes cajas están cerradas: '.implode(', ', $cajas_cerradas).'. Debe abrirlas para poder eliminar la venta y compensar caja.',
                ], 422);
            }
        }

        /** Copia en memoria de métodos de pago para compensar luego del soft delete sin depender de reconsultas. */
        $payment_methods_para_compensacion = null;
        if ($compensar_caja) {
            $payment_methods_para_compensacion = $model->current_acount_payment_methods;
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
                $this->sendAddModelNotification('client', $model->client_id, false);
            }
        }
        $model->delete();

        if ($compensar_caja && ! is_null($payment_methods_para_compensacion) && $payment_methods_para_compensacion->count()) {
            $helper_caja_compensacion->crear_movimientos_compensacion(
                $payment_methods_para_compensacion,
                DeleteCajaCompensacionHelper::MODEL_TYPE_SALE,
                null,
                'Eliminación de venta N° '.$model->num,
                $model->id
            );
        }

        $this->sendDeleteModelNotification('sale', $model->id);

        DeleteSaleHelper::regresar_stock($model);

        return response(null);
    }

    function makeAfipTicket(Request $request) {

        $sale = Sale::find($request->sale_id);

        if (!is_null($sale)) {

            /** @var mixed $monto Importe personalizado que pidio el usuario, en pesos. */
            $monto = $request->monto_a_facturar;

            /**
             * Tope del importe personalizado. La validacion del front es una guarda de UX;
             * la autoridad es este 422, porque un POST directo llega igual hasta aca.
             * Se valida ANTES de instanciar MakeAfipTicket, asi que un rechazo no toca ARCA.
             */
            if (!is_null($monto) && $monto !== '' && (float) $monto > 0) {

                /** @var float $tope Total en pesos que se facturaria sin importe personalizado. */
                $tope = MakeAfipTicket::get_tope_en_pesos(
                    $sale,
                    (int) $request->ventas_afip_information_id,
                    (int) $request->afip_tipo_comprobante_id
                );

                /**
                 * El + 0.01 de tolerancia es a proposito: el total que muestra el front
                 * (sale.total x valor_dolar) y el del AfipHelper (suma de items con back-out)
                 * pueden diferir en centavos. Sin tolerancia se rechazaria el caso mas comun,
                 * que es "facturar exactamente el total".
                 */
                if ((float) $monto > $tope + 0.01) {

                    return response()->json([
                        'message' => 'El importe a facturar ($'.number_format((float) $monto, 2, ',', '.').') no puede superar el total de la venta en pesos ($'.number_format($tope, 2, ',', '.').').',
                    ], 422);
                }

                /** @var mixed $filas Reparto por alicuota, opcional. */
                $filas = $request->importe_personalizado_ivas;

                if (is_array($filas) && count($filas) >= 1) {

                    /** @var float $suma Suma de los importes del reparto. */
                    $suma = 0;
                    foreach ($filas as $fila) {
                        $suma += isset($fila['importe']) ? (float) $fila['importe'] : 0;
                    }

                    if (abs($suma - (float) $monto) > 0.01) {

                        return response()->json([
                            'message' => 'La suma del reparto por alicuota no coincide con el importe a facturar.',
                        ], 422);
                    }
                }
            }

            $afip = new MakeAfipTicket();

            $afip->make_afip_ticket([
                'sale_id'                           => $request->sale_id,
                'afip_information_id'               => $request->ventas_afip_information_id,
                'afip_tipo_comprobante_id'          => $request->afip_tipo_comprobante_id,
                'afip_fecha_emision'                => $request->afip_fecha_emision,
                'facturar_importe_personalizado'    => $request->monto_a_facturar,
                'importe_personalizado_ivas'        => $request->importe_personalizado_ivas,
                'forma_de_pago'                     => $request->forma_de_pago,
                'permiso_existente'                 => $request->permiso_existente,
                'incoterms'                         => $request->incoterms,
            ]);


            return response()->json(['sale' => $this->fullModel('Sale', $request->sale_id)], 201);
        }
        return response(null, 200);
    }

    function updatePrices(Request $request, $id) {
        $model = Sale::find($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La venta no existe o ya fue eliminada.'], 404);
        }

        $motivo = SaleHelper::motivo_por_el_que_no_se_puede_editar($model);

        if (!is_null($motivo)) {

            Log::info('updatePrices sale id '.$id.': rechazado. '.$motivo);

            return response()->json(['message' => $motivo], 409);
        }

        SaleHelper::updateItemsPrices($model, $request->items);
        if ($model->client_id) {
            SaleHelper::updateCurrentAcountsAndCommissions($model);
        }
        // $this->sendAddModelNotification('Sale', $id);
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    /**
     * Envía el comprobante de la venta al cliente por el agente de WhatsApp (grupo 137,
     * Prompt 05). Camino manual: lo llama el botón del modal de Ventas (Prompt 06). Con la
     * ventana de 24 h abierta manda el PDF directo por `send_document`; cerrada, usa la
     * plantilla `cc_cli_comprobante`. Ante cualquier condición esperable (sin teléfono, sin
     * configuración, plantilla no aprobada) responde 422 con un código controlado para que
     * el front lo muestre, en vez de un 500 genérico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    function send_whatsapp_agent($id) {
        $sale = Sale::where('id', $id)
            ->where('user_id', $this->userId())
            ->first();

        if (is_null($sale)) {
            return response()->json(['message' => 'Venta no encontrada.'], 404);
        }

        $sender_service = new SaleWhatsappSenderService();

        try {
            // Empleado autenticado (sin resolver al owner) que efectivamente disparó el envío manual.
            $message = $sender_service->send_sale($sale, $this->userId(false));

            return response()->json(['model' => $this->fullModel('WhatsappChatMessage', $message->id)], 201);
        } catch (SaleWhatsappSendException $e) {
            return response()->json(['code' => $e->error_code(), 'message' => $e->getMessage()], 422);
        }
    }

    function pdf(Request $request, $id) {
        $sale = Sale::find($id);

        /**
         * find() devuelve null si el id no existe o si la venta se borró entre que se abrió la
         * pantalla y se apretó imprimir. Sin esta guarda el null viaja hasta el constructor del
         * PDF, que lo desreferencia en su primera línea (User::find($sale->user_id)) y el cliente
         * recibe un 500 con stack trace en vez de un 404. Paso en produccion el 1/8/2026 en unicas.
         */
        if (is_null($sale)) {
            abort(404);
        }

        // SaleHelper::setPrinted($this, $sale, $confirmed, $user);
        $origin = $request->query('origin');
        $profile_id = $request->query('pdf_column_profile_id');
        $afip_ticket_id = $request->query('afip_ticket_id');

        /**
         * Capa de seguridad: si el request viene de la tienda, exige un token de un solo uso válido
         * emitido por tienda-api tras validar sesión y propiedad de la venta.
         */
        if ($origin === 'tienda') {
            $token = $request->query('token');
            $access = null;
            if (!empty($token)) {
                $access = \App\Models\SalePdfAccessToken::where('token', $token)
                    ->where('sale_id', $id)
                    ->whereNull('used_at')
                    ->where('expires_at', '>', now())
                    ->first();
            }
            if (!$access) {
                abort(403);
            }
            $access->used_at = now();
            $access->save();

            /**
             * Detección automática de factura: si la venta tiene un afip_ticket con CAE, se imprime la factura.
             */
            if (empty($afip_ticket_id)) {
                $afip_ticket = null;
                if ($sale) {
                    $afip_ticket = $sale->afip_tickets()
                        ->whereNotNull('cae')
                        ->orderBy('id', 'asc')
                        ->first();
                }
                if ($afip_ticket) {
                    $afip_ticket_id = $afip_ticket->id;
                }
            }
        }

        $pdf = new NewSalePdf($sale, $profile_id, $afip_ticket_id, $origin);
    }

    function afipTicketA4Pdf(Request $request, $id) {
        $afip_ticket = AfipTicket::find($id);

        if (is_null($afip_ticket)) {
            abort(404);
        }

        $profile_id = $request->query('pdf_column_profile_id');
        $pdf = new SaleAfipTicketPdf($afip_ticket, $profile_id);
    }

    function deliveredArticlesPdf($id) {
        $sale = Sale::find($id);

        if (is_null($sale)) {
            abort(404);
        }

        $pdf = new SaleDeliveredArticlesPdf($sale);
    }

    function saleTicketPdf($sale_id) {
        $sale = Sale::find($sale_id);

        if (is_null($sale)) {
            abort(404);
        }

        $pdf = new SaleTicketPdf($sale);
    }

    function afipTicketPdf($afip_ticket_id) {
        $afip_ticket = AfipTicket::find($afip_ticket_id);

        if (is_null($afip_ticket)) {
            abort(404);
        }

        $pdf = new SaleTicketPdf($afip_ticket->sale, $afip_ticket);
    }

    function ticketRaw($id) {
        $sale = Sale::find($id);
        new SaleTicketRaw($sale);
    }

    function caja() {
        $caja = CajaHelper::getCaja($this);
        return response()->json(['caja' => $caja], 200);
    }

    function charts($from, $until) {
        $charts = SaleChartHelper::getCharts($this, $from, $until);
        return response()->json(['charts' => $charts], 200);
    }

    function ventas_sin_cobrar() {

        $owner = $this->user();

        $user = $this->user(false);

        $dias = $owner->dias_alertar_empleados_ventas_no_cobradas;

        if ($this->is_owner()) {
            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;
        } else if ($this->is_admin() && is_null($user->dias_alertar_empleados_ventas_no_cobradas)) {
            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;
        } else if (!is_null($user->dias_alertar_empleados_ventas_no_cobradas)) {
            $dias = $user->dias_alertar_empleados_ventas_no_cobradas;
        }

        $ver_solo_las_ventas_suyas = true;

        if ($this->is_owner()) {
            $ver_solo_las_ventas_suyas = false;
        } else if ($user->ver_alertas_de_todos_los_empleados) {
            $ver_solo_las_ventas_suyas = false;
        }

        $sales = Sale::where('user_id', $this->userId())
                        ->whereHas('current_acount', function($q) {
                            return $q->where('debe', '>', 0)
                                        ->where('status', 'sin_pagar')
                                        ->orWhere('status', 'pagandose')
                                        ->where(function ($query) {
                                            $query->whereNull('pagandose')
                                            ->orWhereRaw('debe - pagandose > 300');
                                        });
                        })
                        // ->whereHas('client', function ($query) {
                        //     $query->whereHas(function ($q) {
                        //         $q->whereHas('credit_account', function($q_c_a) {
                        //             $q_c_a->where('saldo', '>', 300);
                        //         })
                        //     });
                        //     // $query->where('saldo', '>', 300);
                        // })
                        ->whereRaw(
                            'DATE(`sales`.`created_at`) <= DATE_SUB(CURDATE(), INTERVAL COALESCE(`sales`.`dias_alerta_venta_no_cobrada_personalizado`, ?) DAY)',
                            [$dias]
                        );

        if ($ver_solo_las_ventas_suyas) {
            $sales = $sales->where('employee_id', $user->id);
        }

        // Log::info('ventas_sin_cobrar de hace '.$dias.' dias');
        // Log::info('ver_solo_las_ventas_suyas: '.$ver_solo_las_ventas_suyas);

        $sales = $sales->with('client.credit_accounts', 'employee', 'current_acount')
                        ->orderBy('created_at', 'DESC')
                        ->get();

        $clients = VentasSinCobrarHelper::ordenar_por_clientes($sales);

        return response()->json(['models' => $clients], 200);
    }

    function set_terminada($sale_id) {
        $sale = Sale::find($sale_id);
        if (!is_null($sale)) {
            $sale->terminada = 1;
            $sale->terminada_at = Carbon::now();
            $sale->save();

            new VentaTerminadaComisionesHelper($sale, $this->userId(false));
        }
        return response()->json(['sale' => $this->fullModel('Sale', $sale_id)], 201);
    }

    function nota_credito_afip($sale_id) {
        $sale = Sale::find($sale_id);

        SaleNotaCreditoAfipHelper::crear_nota_de_credito_afip($sale);
        return response(null, 201);
    }

    function clear_actualizandose_por($sale_id) {
        $sale = Sale::find($sale_id);
        $sale->actualizandose_por_id = null;
        $sale->timestamps = false;
        $sale->save();
        return response(null, 200);

    }

    function excel_export($from_date, $until_date = null) {

        $models = Sale::where('user_id', $this->userId())
                        /** Excluye ventas contenedoras de facturación del export Excel. */
                        ->soloVentasReales()
                        ->orderBy('created_at', 'DESC');

        if (!is_null($from_date)) {

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        } 
        $models = $models->get();

        return Excel::download(new SalesFullExport($models), 'ventas_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');

    }

    function excel_breakdown_export($from_date, $until_date = null) {

        $models = Sale::where('user_id', $this->userId())
                        /** Excluye ventas contenedoras de facturación del export desglosado. */
                        ->soloVentasReales()
                        ->with(['articles', 'client', 'employee'])
                        ->orderBy('created_at', 'DESC');

        if (!is_null($from_date)) {

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        }
        $models = $models->get();

        return Excel::download(new SalesBreakdownExport($models), 'ventas_desglosado_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');

    }

    function etiqueta_envio(Request $request, $sale_id) {
        $sender_id = $request->query('sale_sender_info_id');
        if ($sender_id === null || $sender_id === '') {
            abort(404, 'Falta sale_sender_info_id');
        }

        $sale = Sale::where('user_id', $this->userId())
            ->where('id', $sale_id)
            ->with(['client.location.provincia', 'sale_delivery_info'])
            ->first();

        if (is_null($sale)) {
            abort(404);
        }

        $sender = SaleSenderInfo::where('user_id', $this->userId())
            ->where('id', $sender_id)
            ->first();

        if (is_null($sender)) {
            abort(404);
        }

        new EtiquetaEnvioPdf($sale, $sender);
    }

    /**
     * Guarda el remitente elegido para recordarlo en la próxima etiqueta.
     *
     * @param Request $request Body opcional: sale_sender_info_id (nullable para limpiar).
     * @param int|string $sale_id Id de venta.
     * @return \Illuminate\Http\JsonResponse
     */
    function update_etiqueta_sender(Request $request, $sale_id)
    {
        $sale = Sale::where('user_id', $this->userId())
            ->where('id', $sale_id)
            ->first();

        if (is_null($sale)) {
            return response()->json(['error' => true, 'message' => 'Venta no encontrada'], 404);
        }

        $sale->sale_sender_info_id = $request->input('sale_sender_info_id');
        $sale->save();

        return response()->json(['model' => $this->fullModel('Sale', $sale_id)], 200);
    }

    /**
     * Upsert de datos de envío para la etiqueta (SaleDeliveryInfo 1:1).
     * Solo ventas del usuario actual (owner).
     *
     * @param Request $request Campos: first_name, last_name, phone, dni, cuit, locality, province, postal_code, email (opcionales).
     * @param int|string $sale_id Id de la venta.
     * @return \Illuminate\Http\JsonResponse Venta completa con sale_delivery_info.
     */
    function update_delivery_info(Request $request, $sale_id)
    {
        $sale = Sale::where('user_id', $this->userId())
            ->where('id', $sale_id)
            ->first();

        if (is_null($sale)) {
            return response()->json(['error' => true, 'message' => 'Venta no encontrada'], 404);
        }

        SaleDeliveryInfo::updateOrCreate(
            ['sale_id' => $sale->id],
            [
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'phone' => $request->input('phone'),
                'dni' => $request->input('dni'),
                'cuit' => $request->input('cuit'),
                'locality' => $request->input('locality'),
                'province' => $request->input('province'),
                'postal_code' => $request->input('postal_code'),
                'email' => $request->input('email'),
            ]
        );

        return response()->json(['model' => $this->fullModel('Sale', $sale_id)], 200);
    }

    /**
     * Encola el mismo correo de notificación de venta que al crear el registro (ComercioCityMailHelper::new_sale).
     * Requiere cliente con email válido. Marca send_mail en la venta para alinear el listado con el distintivo de correo.
     *
     * @param int|string $sale_id Id de la venta del usuario autenticado.
     * @return \Illuminate\Http\JsonResponse Venta completa vía fullModel o error 404/422.
     */
    function send_client_mail($sale_id)
    {
        if (!UserHelper::hasExtencion('enviar_mail_a_clientes')) {
            return response()->json(['error' => true, 'message' => 'No autorizado'], 403);
        }

        /** Venta propia del usuario actual (mismo criterio que update_delivery_info). */
        $sale = Sale::where('user_id', $this->userId())
            ->where('id', $sale_id)
            ->first();

        if (is_null($sale)) {
            return response()->json(['error' => true, 'message' => 'Venta no encontrada'], 404);
        }

        /** Validación + encolado + persistencia send_mail (misma lógica que el envío masivo por id). */
        $error_message = $this->try_queue_sale_client_mail($sale);
        if ($error_message !== null) {
            return response()->json(['error' => true, 'message' => $error_message], 422);
        }

        return response()->json(['model' => $this->fullModel('Sale', $sale_id)], 200);
    }

    /**
     * Encola el correo de notificación para cada venta indicada (ids únicos del usuario actual).
     * Las que fallen (sin cliente, mail inválido, etc.) se listan en failures sin abortar el resto.
     *
     * Request body: sale_ids (array de enteros).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse models: fullModel por cada éxito; failures: { sale_id, message }[]
     */
    function send_client_mail_bulk(Request $request)
    {
        if (!UserHelper::hasExtencion('enviar_mail_a_clientes')) {
            return response()->json(['error' => true, 'message' => 'No autorizado'], 403);
        }

        /** Lista de ids enviada desde el SPA (selección múltiple en listado de ventas). */
        $ids = $request->input('sale_ids');
        if (!is_array($ids)) {
            return response()->json(['error' => true, 'message' => 'sale_ids debe ser un array'], 422);
        }

        /** Normaliza a enteros positivos y elimina duplicados. */
        $sale_ids = [];
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $sale_ids[] = $id;
            }
        }
        $sale_ids = array_values(array_unique($sale_ids));

        if (count($sale_ids) === 0) {
            return response()->json(['error' => true, 'message' => 'Indique al menos una venta'], 422);
        }

        /** Ventas del usuario: una query por id (volumen típico de selección manual es bajo). */
        $user_id = $this->userId();
        $models = [];
        $failures = [];

        foreach ($sale_ids as $sale_id) {
            $sale = Sale::where('user_id', $user_id)
                ->where('id', $sale_id)
                ->first();

            if (is_null($sale)) {
                $failures[] = [
                    'sale_id' => $sale_id,
                    'message' => 'Venta no encontrada',
                ];
                continue;
            }

            $error_message = $this->try_queue_sale_client_mail($sale);
            if ($error_message !== null) {
                $failures[] = [
                    'sale_id' => $sale_id,
                    'message' => $error_message,
                ];
                continue;
            }

            $models[] = $this->fullModel('Sale', $sale_id);
        }

        return response()->json([
            'models' => $models,
            'failures' => $failures,
        ], 200);
    }

    /**
     * Encola ComercioCityMailHelper::new_sale para una venta ya resuelta al usuario.
     *
     * @param Sale $sale Instancia persistida (user_id ya verificado en el llamador).
     * @return string|null Mensaje de error para API/toast, o null si se encoló el mail y se guardó send_mail.
     */
    protected function try_queue_sale_client_mail(Sale $sale): ?string
    {
        $sale->loadMissing('client', 'user', 'moneda');

        /** Cliente obligatorio para destinatario del correo. */
        $client = $sale->client;
        if (!$client) {
            return 'La venta no tiene cliente asociado';
        }

        /** Email del cliente: mismo criterio que el helper (vacío o inválido → no envío). */
        $email_raw = $client->email;
        if ($email_raw === null || trim((string) $email_raw) === '') {
            return 'El cliente no tiene correo electrónico';
        }

        $email = trim((string) $email_raw);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Correo del cliente no válido';
        }

        /** Igual que en store() tras commit: notificación estándar de nueva venta (no modo "actualizada"). */
        ComercioCityMailHelper::new_sale($sale, false, true);

        /** Persiste el flag para que el listado muestre el mismo estado que el checkbox / badge de envío. */
        $sale->send_mail = true;
        $sale->save();

        return null;
    }

    function unidades_entregadas(Request $request, $sale_id) {
        $sale = Sale::find($sale_id);

        AcopioHelper::set_delivered_amount($sale, $request->articles);

        return response()->json(['model' => $this->fullModel('Sale', $sale_id)], 200);
    }

    function cerrar_venta($id) {
        $sale = Sale::find($id);
        $sale->is_cerrada = 1;
        $sale->save();
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    /**
     * Lista las ventas de un cliente que son elegibles para ser consolidadas.
     * Respeta los mismos filtros que el usuario aplicaría manualmente.
     *
     * Query params:
     *   - client_id (requerido)
     *   - from (opcional, Y-m-d)
     *   - until (opcional, Y-m-d)
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function ventasPorConsolidar(Request $request) {
        /** Ventas elegibles: terminadas, sin CAE, sin consolidación previa, del cliente indicado. */
        $ventas = ConsolidarFacturacionHelper::ventas_por_consolidar(
            (int) $request->client_id,
            $this->userId(),
            $request->from,
            $request->until
        );

        return response()->json(['models' => $ventas], 200);
    }

    /**
     * Crea la venta consolidada para facturación agrupando las ventas indicadas
     * y dispara el comprobante AFIP sobre ella.
     *
     * Request body esperado:
     *   - client_id                  (int, requerido)
     *   - sale_ids                   (array de ints, requerido)
     *   - afip_information_id        (int, requerido)
     *   - afip_tipo_comprobante_id   (int, requerido)
     *   - agrupar_items              (bool, opcional, default false)
     *   - afip_fecha_emision         (string Y-m-d, opcional)
     *   - forma_de_pago              (string, opcional)
     *   - permiso_existente          (string, opcional)
     *   - incoterms                  (string, opcional)
     *
     * NO se acepta `monto_a_facturar`: por decision de Lucas del 20/8/2026, si se consolidan
     * varias ventas en una sola factura no se puede informar ningun importe personalizado.
     * `ConsolidarFacturacionHelper::build_afip_ticket_data()` lo fuerza a null y lo loguea.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function consolidarFacturacion(Request $request) {
        try {
            /** Construye el array de datos AFIP extra a partir del request. */
            $afip_data = [
                'afip_fecha_emision'             => $request->afip_fecha_emision,
                'forma_de_pago'                  => $request->forma_de_pago,
                'permiso_existente'              => $request->permiso_existente,
                'incoterms'                      => $request->incoterms,
            ];

            /** Llamada con argumentos posicionales (compatible con PHP 7.3; los nombres solo existen desde PHP 8.0). */
            $venta_consolidada = ConsolidarFacturacionHelper::consolidar(
                (array) $request->sale_ids,
                (int) $request->client_id,
                $this->userId(),
                (int) $request->afip_information_id,
                (int) $request->afip_tipo_comprobante_id,
                (bool) ($request->agrupar_items ?? false),
                $afip_data,
                true
            );

            return response()->json(['model' => $this->fullModel('Sale', $venta_consolidada->id)], 201);

        } catch (\Throwable $e) {
            Log::error('consolidarFacturacion error: ' . $e->getMessage());
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Normaliza el campo opcional de días hasta alertar cobro sin pagar (vender).
     * Si la clave no viene en el request, devuelve null (solo tiene sentido en store cuando no existe la clave).
     * Valores vacíos o null explícito => sin umbral personalizado (usa reglas globales en alertas).
     *
     * @param Request $request Request con posible `dias_alerta_venta_no_cobrada_personalizado`.
     * @return int|null Entero >= 0 o null.
     */
    protected function normalized_dias_alerta_venta_no_cobrada_personalizado(Request $request)
    {
        if (!$request->exists('dias_alerta_venta_no_cobrada_personalizado')) {
            return null;
        }
        $raw_value = $request->input('dias_alerta_venta_no_cobrada_personalizado');
        if ($raw_value === '' || $raw_value === null) {
            return null;
        }

        return max(0, (int) $raw_value);
    }

    /**
     * Export Excel de ventas fidedigno a la pantalla (botón "Excel").
     * Recibe el estado completo del filtro por POST y reconstruye el set completo.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    function excel_export_view(Request $request)
    {
        $models = $this->resolve_sales_for_export($request);

        return Excel::download(
            new SalesFullExport($models),
            'ventas_'.date_format(Carbon::now(), 'd-m-y').'.xlsx'
        );
    }

    /**
     * Export Excel desglosado por artículo, fidedigno a la pantalla (botón "Excel full").
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    function excel_breakdown_export_view(Request $request)
    {
        $models = $this->resolve_sales_for_export($request);

        return Excel::download(
            new SalesBreakdownExport($models),
            'ventas_desglosado_'.date_format(Carbon::now(), 'd-m-y').'.xlsx'
        );
    }

    /**
     * Reconstruye el conjunto COMPLETO de ventas que corresponde a lo que el usuario ve en pantalla.
     * - Si hay filtro de columnas activo: reusa el mismo motor de búsqueda que /api/search/sale,
     *   completo (sin paginar) y con withAll(), pasando por SearchController.
     * - Si no: arma el rango de fechas igual que excel_export/excel_breakdown_export.
     * Después aplica en PHP las show options y la pestaña sucursal/empleado (ver apply_view_show_option_filters).
     *
     * @param Request $request
     * @return \Illuminate\Support\Collection
     */
    private function resolve_sales_for_export(Request $request)
    {
        /** Indica si la pantalla tiene el filtro de columnas activo (mismo motor que /api/search/sale). */
        $is_filtered = (boolean) $request->input('is_filtered', false);

        if ($is_filtered) {
            /* Mismo motor de columnas que la pantalla, pero completo (sin paginar) y devolviendo modelos crudos. */
            $search = new SearchController();
            $models = $search->search($request, 'sale', null, 0, false, true);
        } else {
            /** Rango de fechas equivalente al que usan los endpoints GET viejos. */
            $from_date = $request->input('from_date');
            $until_date = $request->input('until_date');

            $query = Sale::where('user_id', $this->userId())
                        ->withAll()
                        ->orderBy('created_at', 'DESC');

            if (!is_null($from_date) && $from_date !== '') {
                if (!is_null($until_date) && $until_date !== '') {
                    $query = $query->whereDate('created_at', '>=', $from_date)
                                    ->whereDate('created_at', '<=', $until_date);
                } else {
                    $query = $query->whereDate('created_at', $from_date);
                }
            }

            $models = $query->get();
        }

        return $this->apply_view_show_option_filters($models, $request);
    }

    /**
     * Espeja el computed sales_to_show del front: aplica sobre la colección las show options
     * (cobradas/sin cobrar, con/sin factura, método de pago) y la pestaña sucursal/empleado.
     * Las ventas consolidadas (contenedoras de facturación) SIEMPRE se excluyen del Excel.
     *
     * @param \Illuminate\Support\Collection $models
     * @param Request $request
     * @return \Illuminate\Support\Collection
     */
    private function apply_view_show_option_filters($models, Request $request)
    {
        /** Pestaña de sucursal (nullable: sin filtro por address). */
        $address_id  = $request->input('address_id');
        /** Pestaña "solo dueño" (ventas sin employee_id asignado). */
        $only_owner  = (boolean) $request->input('only_owner', false);
        /** Pestaña de empleado puntual (nullable). */
        $employee_id = $request->input('employee_id');
        /** Show option cobradas / sin cobrar. */
        $cobradas    = $request->input('ventas_cobradas_show_option', 'cobradas-y-no-cobradas');
        /** Show option con / sin factura AFIP. */
        $afip        = $request->input('afip_ticket_show_option', 'con-y-sin-factura');
        /** Show option método de pago (id de CurrentAcountPaymentMethod o 'todos'). */
        $payment     = $request->input('payment_method_show_option', 'todos');

        $self = $this;

        return $models->filter(function ($sale) use ($self, $address_id, $only_owner, $employee_id, $cobradas, $afip, $payment) {

            /* Consolidadas: siempre excluidas del Excel (decisión fija). */
            if ((int) $sale->is_consolidacion_facturacion === 1) {
                return false;
            }

            /* Pestaña de sucursal. */
            if (!is_null($address_id) && $address_id !== '') {
                if ((int) $sale->address_id !== (int) $address_id) {
                    return false;
                }
            }

            /* Pestaña de empleado (dueño = sin employee_id). */
            if ($only_owner) {
                if (!empty($sale->employee_id)) {
                    return false;
                }
            } else if (!is_null($employee_id) && $employee_id !== '') {
                if ((int) $sale->employee_id !== (int) $employee_id) {
                    return false;
                }
            }

            /* Show option cobradas / no cobradas. */
            if ($cobradas === 'solo-cobradas') {
                if (!$self->venta_cobrada_export($sale)) {
                    return false;
                }
            } else if ($cobradas === 'solo-sin-cobrar') {
                $sin_cobrar = $sale->client_id
                    && $sale->current_acount
                    && $sale->current_acount->status !== 'pagado';
                if (!$sin_cobrar) {
                    return false;
                }
            }

            /* Show option con / sin factura. */
            if ($afip === 'solo-con-factura') {
                if ($sale->afip_tickets->count() === 0) {
                    return false;
                }
            } else if ($afip === 'solo-sin-factura') {
                if ($sale->afip_tickets->count() > 0) {
                    return false;
                }
            }

            /* Show option método de pago. */
            if ($payment !== 'todos') {
                $match = $sale->current_acount_payment_methods->first(function ($pm) use ($payment) {
                    return (string) $pm->id === (string) $payment;
                });
                if (is_null($match)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Espeja venta_cobrada del front (mixins/generals.js): venta cobrada si no tiene cliente,
     * si está omitida de cuenta corriente, o si su cuenta corriente está 'pagado'.
     *
     * @param Sale $sale
     * @return boolean
     */
    private function venta_cobrada_export($sale)
    {
        if (!$sale->client_id) {
            return true;
        }
        if ($sale->omitir_en_cuenta_corriente) {
            return true;
        }
        if ($sale->current_acount && $sale->current_acount->status === 'pagado') {
            return true;
        }
        return false;
    }
}
