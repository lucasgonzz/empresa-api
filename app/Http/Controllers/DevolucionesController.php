<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Devoluciones\RegresarStockHelper;
use App\Http\Controllers\Helpers\Devoluciones\UpdateSaleHelper;
use App\Http\Controllers\Helpers\Devoluciones\ValidarDevolucionHelper;
use App\Models\AfipTicket;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DevolucionesController extends Controller
{
    function search_sale($num) {
        $sale = Sale::where('user_id', $this->userId())
                    ->where('num', $num)
                    ->withAll()
                    ->first();

        return response()->json(['sale' => $sale], 200);
    }

    function store(Request $request) {

        /*
            🔴 No se puede devolver más de lo que la venta tiene sin devolver (auditoría de stock,
            5/9/2026). Es el freno contra la NC DUPLICADA por doble clic: la segunda intenta devolver
            unidades que la primera ya devolvió, y se rechaza con el detalle. Va ANTES de abrir la
            transacción porque no escribe nada. Ver ValidarDevolucionHelper.
        */
        if ($request->regresar_stock || $request->update_unidades_devueltas) {

            $motivo = ValidarDevolucionHelper::motivo_por_el_que_no_se_puede_devolver($request->sale_id, $request->items);

            if (!is_null($motivo)) {
                return response()->json(['message' => $motivo, 'devolucion_excedida' => true], 422);
            }
        }

        DB::beginTransaction();

        try {

            $model_id = null;
            $credit_account_id = null;

            // Validar que el client_id recibido corresponda al cliente real de la venta.
            // Evita que un client_id residual del frontend (de una búsqueda anterior)
            // quede asignado a una nota de crédito de una venta sin cliente.
            // Esta validación SOLO aplica cuando la devolución está atada a una venta
            // (request->sale_id presente). Cuando la devolución se crea desde cero (módulo
            // de Devoluciones sin venta de origen) no hay sale_client_id contra el cual
            // comparar, y el client_id elegido a mano por el usuario es el válido.
            // FIX 17/7/2026 (Lucas): antes, sin sale_id, $sale_client_id quedaba en null y
            // "$request->client_id == null" daba siempre false -- se saltaba la carga de
            // model_id/credit_account_id y la NC se creaba sin cliente asignado y sin
            // movimiento en cuenta corriente, en silencio, aunque el checkbox estuviera activo.
            $sale_client_id = null;
            $hay_venta_asociada = (bool) $request->sale_id;
            if ($hay_venta_asociada) {
                $sale_for_validation = Sale::find($request->sale_id);
                if ($sale_for_validation) {
                    $sale_client_id = $sale_for_validation->client_id;
                }
            }

            $client_id_valido = $hay_venta_asociada
                ? $request->client_id == $sale_client_id
                : true;

            if ($request->generar_current_acount && !is_null($request->client_id)) {

                if (!$client_id_valido) {
                    // Antes esto se ignoraba en silencio (ver comentario arriba): se
                    // guardaba una NC sin cliente ni movimiento en CC y se devolvía 201
                    // como si hubiese salido bien. Ahora corta explícito -- si hay venta
                    // asociada y el cliente no coincide es un dato inconsistente del
                    // frontend (client_id residual) y no debe generar una NC a medias sin
                    // avisar. Cae en el catch(\Throwable $e) de más abajo -> rollback + 500.
                    throw new \Exception('El cliente seleccionado no corresponde al cliente de la venta indicada. No se generó el movimiento en cuenta corriente.');
                }

                $model_id = $request->client_id;

                $moneda_id = $this->get_moneda_id($request);

                $credit_account_id = CreditAccount::where('model_name', 'client')
                                                    ->where('model_id', $model_id)
                                                    ->where('moneda_id', $moneda_id)
                                                    ->first()
                                                    ->id;
            }


            $nota_credito = CurrentAcountHelper::notaCredito(
                $credit_account_id,
                $request->total_devolucion, 
                $request->observaciones, 
                'client', 
                $model_id, 
                $request->sale_id, 
                $request->items,
                $request->descriptions,
            );

            $discounts = $this->set_pivot_percentage($request, 'discounts');
            $surchages = $this->set_pivot_percentage($request, 'surchages');
            GeneralHelper::attachModels($nota_credito, 'discounts', $discounts, ['percentage']);
            GeneralHelper::attachModels($nota_credito, 'surchages', $surchages, ['percentage']);

            if (
                $request->sale_id
                && $request->update_unidades_devueltas
            ) {
                UpdateSaleHelper::update_sale_returned_items($request);
            }

            if ($request->regresar_stock) {
                RegresarStockHelper::regresar_stock($request, $nota_credito);
            }

            if ($request->facturar_nota_credito) {
                
                $afip_ticket = AfipTicket::find($request->facturar_nota_credito);
                $afip_helper = new AfipNotaCreditoHelper($afip_ticket, $nota_credito);
                $afip_helper->init();
            }
            
            DB::commit();

            return response(null, 201);

        } catch(\Throwable $e) {

            DB::rollBack();

            Log::info('Error enn nota de credito');
            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);

            return response(null, 500);
        }


    }

    function set_pivot_percentage($request, $relation) {
        $models = [];
        foreach ($request->{$relation} as $model) {
            $models[] = [
                'id'    => $model['id'],
                'pivot' => [
                    'percentage'    => $model['percentage'],
                ]
            ];
        }
        return $models;
    }

    function get_moneda_id($request) {
        $moneda_id = 1;
        if ($request->sale_id) {
            $sale = Sale::find($request->sale_id);
            $moneda_id = $sale->moneda_id;
        }
        return $moneda_id;
    }
}
