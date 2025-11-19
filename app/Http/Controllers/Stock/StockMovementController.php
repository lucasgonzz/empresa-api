<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\CommonLaravel\Helpers\RequestHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartArticleAmountInsificienteHelper;
use App\Http\Controllers\Helpers\GlobalHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Stock\SetArticleStock\SetArticleStock;
use App\Http\Controllers\Stock\SetConcepto;
use App\Http\Controllers\Stock\SetProvider;
use App\Http\Controllers\Stock\SetStockUpdatedAt;
use App\Jobs\ProcessSyncArticleToTiendaNube;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StockMovementController extends Controller
{

    function __construct($update_tienda_nube = true) {
        $this->update_tienda_nube = $update_tienda_nube;
    }

    function index($article_id) {
        $models = StockMovement::where('article_id', $article_id)
                                ->orderBy('id', 'DESC')
                                ->with('article_variant')
                                ->get();
                                
        return response()->json(['models' => $models], 200);
    }

    /*
        * Store solo se llama desde el modal de stock del Listado
    */

    function store(Request $request, $set_updated_at = true, $owner = null, $auth_user_id = null, $segundos_para_agregar = null) {

        $data = [
            'model_id'              => $request->model_id,
            'amount'                => $request->amount,
            'provider_id'           => $request->provider_id,
            'from_address_id'       => $request->from_address_id,
            'to_address_id'         => $request->to_address_id,
            'article_variant_id'    => $request->article_variant_id,
            'observations'          => $request->observations,
            'concepto_stock_movement_id'            => $request->concepto_stock_movement_id,
            'concepto_stock_movement_name'          => $request->concepto_stock_movement_name,
        ];

        $this->crear($data, false);

        Log::info('Se creo stock movement para article_id: '.$request->model_id);

        return response(null, 201);

    }

    function crear($data, $set_updated_at = false, $owner = null, $auth_user_id = null, $segundos_para_agregar = null) {

        if (!is_null($owner)) {
            $this->user_id = $owner->id;
        } else {
            $this->user_id = UserHelper::userId();
        }

        if (!is_null($auth_user_id)) {
            $employee_id = $auth_user_id;
        } else {
            $employee_id = UserHelper::userId(false);
        }

        $article = Article::find($data['model_id']);

        $concepto_id = SetConcepto::get_concepto($data);
        
        $amount = $this->check_unidades_individuales($article, (float)$data['amount'], $concepto_id);

        // Log::info('observations: '.$data['observations']);
        $stock_movement = StockMovement::create([
            'article_id'                => $data['model_id'],
            'amount'                    => $amount,
            'sale_id'                   => GlobalHelper::isset_dist_0($data, 'sale_id'),
            'order_id'                  => GlobalHelper::isset_dist_0($data, 'order_id'),
            'provider_order_id'         => GlobalHelper::isset_dist_0($data, 'provider_order_id'),
            'deposit_movement_id'       => GlobalHelper::isset_dist_0($data, 'deposit_movement_id'),
            'nota_credito_id'           => GlobalHelper::isset_dist_0($data, 'nota_credito_id'),
            'provider_id'               => $this->get_provider_id($data),
            'from_address_id'           => $this->getFromAddressId($data),
            'to_address_id'             => $this->getToAddressId($data),
            'article_variant_id'        => $this->get_article_variant_id($data),
            'observations'              => GlobalHelper::isset_dist_0($data, 'observations'),
            'employee_id'               => $employee_id,
            'user_id'                   => $this->user_id,
            'created_at'                => $this->get_created_at($segundos_para_agregar),
        ]);

        // Log::info('stock_movement->observations: '.$stock_movement->observations);

        $stock_movement = SetConcepto::set_concepto($stock_movement, $data);

        SetArticleStock::set_article_stock($stock_movement, $article, $set_updated_at, $this->user_id);
        
        SetStockResultante::set_stock_resultante($stock_movement, $article);

        SetProvider::set_provider($stock_movement, $article, $data);

        SetStockUpdatedAt::set_stock_updated_at($stock_movement, $article);

        ProductService::add_article_to_sync($article);
        TiendaNubeSyncArticleService::add_article_to_sync($article);

        return $stock_movement;
    }

    function check_unidades_individuales($article, $amount, $concepto_id) {

        $concepto = ConceptoStockMovement::find($concepto_id);

        if (!is_null($article->unidades_individuales)) {

            if (
                // Ingreso manual
                $concepto->name == 'Ingreso manual'
                // Compra a proveedor
                || $concepto->name == 'Compra a proveedor'
                // Act. Compra a proveedor
                || $concepto->name == 'Act Compra a proveedor'
                || $concepto->name == 'Eliminacion Compra a proveedor'
            ) {
                $amount *= $article->unidades_individuales;
            }
        }

        return $amount;
    }

    function get_provider_id($data) {
        if (
            $data['amount'] >= 1 
            && isset($data['provider_id']) 
            && $data['provider_id'] != 0
        ) {
            return $data['provider_id'];
        }
        return null;
    }

    function get_created_at($segundos_para_agregar) {

        if (!is_null($segundos_para_agregar)) {
            Log::info('sumando '.$segundos_para_agregar.' segundos, queda en'.Carbon::now()->addSeconds($segundos_para_agregar));
            return Carbon::now()->addSeconds($segundos_para_agregar);
        }

        return Carbon::now();
    }

    function get_article_variant_id($data) {
        if (
            isset($data['article_variant_id']) 
            && $data['article_variant_id'] != 0
        ) {

            return $data['article_variant_id'];
        }
        return null;
    }

    // function setStockResultante() {


    //     // Si el movimiento es porque se esta repartiendo el stock en depositos
    //     // Se pone de stock actual el mismo que el stock del articulo
    //     if (
    //             isset($this->request->from_create_article_addresses)
    //             || isset($this->request->is_movimiento_de_depositos)
    //             || isset($this->request->from_excel_import)
    //         ) {
            
    //         $this->stock_movement->stock_resultante = $this->article->stock;
    //         $this->stock_movement->save();

    //         Log::info('Se esta repartiendo stock, se puso stock_resultante con el stock actual de: '.$this->article->stock);

    //         $this->set_stock_actual_in_observations();
    //         return;
    //     }

    //     if (!is_null($this->article)) {

    //         $stock_movement_anterior = StockMovement::where('article_id', $this->article->id)
    //                                                 ->orderBy('id', 'DESC')
    //                                                 ->where('id', '<', $this->stock_movement->id)
    //                                                 ->first();

    //         if (!is_null($stock_movement_anterior)) {

    //             $stock_resultante = (float)$stock_movement_anterior->stock_resultante + (float)$this->stock_movement->amount;

    //             $this->stock_movement->stock_resultante = $stock_resultante;

    //         } else {
    //             $this->stock_movement->stock_resultante = $this->stock_movement->amount;
    //         }

    //         $this->stock_movement->save();
    //     } else {
    //         $this->stock_movement->stock_resultante = $this->stock_movement->amount;
    //         $this->stock_movement->save();
    //     }

    //     $this->set_stock_actual_in_observations();

    // }

    // function set_stock_resultante_por_creacion_de_depositos() {

    //     $last_stock_movement = StockMovement::where('article_id', $this->article_id)
    //                                         ->where('concepto', '!=', 'Creacion de deposito')
    //                                         ->orderBy('created_at', 'DESC')
    //                                         ->first();

    //     if (!is_null($last_stock_movement)) {

    //         $this->stock_movement->stock_resultante = $last_stock_movement->stock_resultante;
    //         $this->stock_movement->save();

    //     }

    // }

    // function set_stock_actual_in_observations() {

    //     if (!is_null($this->article)) {
    //         if (!is_null($this->stock_movement->observations)) {
    //             $this->stock_movement->observations .= ' - '.$this->article->stock;
    //         } else {
    //             $this->stock_movement->observations = $this->article->stock;
    //         }
    //         $this->stock_movement->save();
    //     }

    // }

    function getFromAddressId($data) {
        // Log::info('getFromAddressId: '.$data['from_address_id']);
        // Log::info(gettype($data['from_address_id']));
        if (
            isset($data['from_address_id'])
            && !is_null($data['from_address_id'])
            && $data['from_address_id'] != 0
        ) {

             return $data['from_address_id'];
        }
        return null;
    }

    function getToAddressId($data) {
        if (
            isset($data['to_address_id'])
            && !is_null($data['to_address_id'])
            && $data['to_address_id'] != 0
        ) {

             return $data['to_address_id'];
        }
        return null;
    }

    // function getFromAddressId() {
    //     if (isset($this->request->from_address_id) 
    //         && $this->request->from_address_id != 0 
    //         && (
    //                 $this->articleHasAddresses()
    //                 || $this->request->article_variant_id 
    //             )) 
    //     {
    //         return $this->request->from_address_id;
    //     }
    //     return null;
    // }

    // function getToAddressId() {
    //     if (isset($this->request->to_address_id) 
    //             && $this->request->to_address_id != 0 
    //             && (
    //                     $this->articleHasAddresses()
    //                     || $this->request->article_variant_id 
    //                     || (
    //                         is_null($this->article->stock)
    //                         || $this->article->stock == 0
    //                     )
    //                 )
    //         ) {
    //         return $this->request->to_address_id;
    //     }
    //     return null;
    // }

    function articleHasAddresses() {
        if (is_null($this->article) || count($this->article->addresses) >= 1 || isset($this->request->from_create_article_addresses)) {
            // Log::info('Tiene articleHasAddresses');
            return true;
        }
        // Log::info('isset '.isset($this->request->from_create_article_addresses));
        return false;
    }

    function sendUpdateNotification() {
        if (!is_null($this->article) && !is_null(Auth()->user()) && (!isset($this->request->from_excel_import))) {
            $this->sendAddModelNotification('Article', $this->article->id, false, null, true);
        }
    }

    // function setConcepto() {
    //     if (is_null($this->stock_movement->concepto)) {
    //         if (isset($this->request->from_excel_import)) {
    //             $this->stock_movement->concepto = 'Importacion Excel';
    //         } else if (!is_null($this->stock_movement->provider_id)) {
    //             $this->stock_movement->concepto = 'Compra a proveedor';
    //         } else if (!is_null($this->stock_movement->sale_id)) {
    //             if (!is_null($this->stock_movement->to_address_id)) {
    //                 $this->stock_movement->concepto = 'Eliminacion de Venta N° '.$this->stock_movement->sale->num;
    //             } else {
    //                 $this->stock_movement->concepto = 'Venta N° '.$this->stock_movement->sale->num;
    //             }
    //         } else if (!is_null($this->stock_movement->from_address_id)) {
    //             $this->stock_movement->concepto = 'Movimiento de depositos';
    //         } else if (!is_null($this->stock_movement->amount) && $this->stock_movement->amount < 0) {
    //             $this->stock_movement->concepto = 'Resta de Stock';
    //         }
    //         $this->stock_movement->save();
    //     }
    // }

    function setArticleStock() {
        if (!is_null($this->article)) {
            $this->checkFromAddress();

            $this->checkToAddress();

            $this->checkArticleVariant();

            $this->checkGlobalStock();
    
            ArticleHelper::checkAdvises($this->article);

            ArticleHelper::setArticleStockFromAddresses($this->article, false);

            if ($this->stock_movement->concepto != 'Movimiento de depositos'
                && !isset($this->request->from_excel_import) 
                && (
                    is_null($this->stock_movement->observations)
                    || substr($this->stock_movement->observations, 0, 1) != '.'
                )
                ) {
                CartArticleAmountInsificienteHelper::checkCartsAmounts($this->article);
            }
        } 
    }

    function setArticleProvider() {
        if (!is_null($this->article) && !is_null($this->stock_movement->provider)
            && !isset($this->request->not_save_provider)) {
            $this->article->provider_id = $this->stock_movement->provider_id;
            $this->article->save();
        }
    }

    function checkGlobalStock() {

        if (is_null($this->article->stock)) {
            $this->article->stock = 0;
            $this->article->save();
        }

        if (!is_null($this->article->stock) 
            && !count($this->article->addresses) >= 1
            && !count($this->article->article_variants) >= 1) {

            /*
                Se aumenta el stock del articulo con la amount del stock_movement
                Ya que, si es una venta, amount va a ser negativo
            */
                
            $this->article->stock += (float)$this->stock_movement->amount;

            if (!$this->set_updated_at) {
                $this->article->timestamps = false;
            }
            
            $this->article->save();

            if (!isset($this->request->from_excel_import) || !$this->request->from_excel_import) {
                $ct = new InventoryLinkageHelper(null, $this->user_id);
                $ct->check_is_agotado($this->article);
            }

        }
    }


    function checkArticleVariant() {

        if (!is_null($this->stock_movement->article_variant_id)) {

            Log::info('entro en checkArticleVariant para '.$this->stock_movement->article->name);

            
            $article_variant = ArticleVariant::find($this->stock_movement->article_variant_id);
            
            // Log::info('addresses de variant'. $article_variant->variant_description .': ');
            // Log::info($article_variant->addresses);

            if (!is_null($this->stock_movement->from_address_id)
                && count($article_variant->addresses) >= 1) {

                Log::info('Va con from_address_id: '.$this->stock_movement->from_address_id);
                
                $article_variant_address = null;

                foreach ($article_variant->addresses as $address) {

                    if ($address->id == $this->stock_movement->from_address_id) {
                        $article_variant_address = $address;
                    }
                }
                
                $amount = $this->get_amount_for_from_address();
                
                if (is_null($article_variant_address)) {


                    Log::info('No tenia nada en la direccion, se le va a agregar con la cantidad de '.$amount);

                    /*
                        Si la direccion destino es null  
                        se le attach esa direccion y se le pone como cantidad inicial la cantidad del movimineto 
                        de deposito
                        Ya que siempre va a ser positiva
                    */
                    $article_variant->addresses()->attach($this->stock_movement->from_address_id, [
                        'amount'    => $amount,
                    ]);

                } else {

                    $new_amount = $article_variant_address->pivot->amount + $amount;

                    $article_variant->addresses()->updateExistingPivot($this->stock_movement->from_address_id, [
                        'amount'    => $new_amount,
                    ]);
                    Log::info('Ya tenia en la direccion, se va a actualizar la cantidad con '.$new_amount);
                }
            } 

            if (!is_null($this->stock_movement->to_address_id)) {

                Log::info('Va con to_address_id: '.$this->stock_movement->to_address_id);
                
                $article_variant_address = null;

                foreach ($article_variant->addresses as $address) {

                    if ($address->id == $this->stock_movement->to_address_id) {
                        $article_variant_address = $address;
                    }
                }
                
                if (is_null($article_variant_address)) {

                    Log::info('No tenia nada en la direccion, se le va a agregar con la cantidad de '.$this->stock_movement->amount);

                    /*
                        Si la direccion destino es null  
                        se le attach esa direccion y se le pone como cantidad inicial la cantidad del movimineto 
                        de deposito
                        Ya que siempre va a ser positiva
                    */
                    $article_variant->addresses()->attach($this->stock_movement->to_address_id, [
                        'amount'    => $this->stock_movement->amount,
                    ]);

                } else {

                    $new_amount = $article_variant_address->pivot->amount + $this->stock_movement->amount;

                    $article_variant->addresses()->updateExistingPivot($this->stock_movement->to_address_id, [
                        'amount'    => $new_amount,
                    ]);
                    Log::info('Ya tenia en la direccion, se va a actualizar la cantidad con '.$new_amount);
                }
            } 

            if (
                (
                    is_null($this->stock_movement->from_address_id)
                    && is_null($this->stock_movement->to_address_id)
                )
                || !count($article_variant->addresses) >= 1
            ) {

                Log::info('No entro en address: ');

                $article_variant->stock += $this->stock_movement->amount;
                $article_variant->save();
            }

        }
    }



    /*
        *   Aca se aumenta el stock del deposito destino
            En caso de que sea:

            * Modificacion de stock (desde el modal de article)
            * Un pedido de proveedor
            * Movimiento de depositos
            * Importacion de excel
    */
    function checkToAddress() {

        Log::info('checkToAddress para '.$this->article->name);
        Log::info('stock actual: '.$this->article->stock);
        Log::info('tiene 0: '.$this->article->stock == 0);

        if (
                !is_null($this->stock_movement->to_address_id)
                && is_null($this->stock_movement->article_variant_id)
                && (
                    $this->articleHasAddresses()
                    || (
                        is_null($this->article->stock)
                        || $this->article->stock == 0
                    )
                )
            ) {

            Log::info('entro en las direcciones');
            
            $this->article->load('addresses');
            
            $to_address = null;
            foreach ($this->article->addresses as $address) {
                if ($address->id == $this->stock_movement->to_address_id) {
                    $to_address = $address;
                }
            }
            
            if (is_null($to_address)) {

                /*
                    Si la direccion destino es null  
                    se le attach esa direccion y se le pone como cantidad inicial la cantidad del movimineto 
                    de deposito
                    Ya que siempre va a ser positiva
                */
                $this->article->addresses()->attach($this->stock_movement->to_address_id, [
                    'amount'    => $this->stock_movement->amount,
                ]);

            } else {

                $new_amount = $to_address->pivot->amount + $this->stock_movement->amount;
                Log::info('se puso new_amount de '.$new_amount);
                $this->article->addresses()->updateExistingPivot($this->stock_movement->to_address_id, [
                    'amount'    => $new_amount,
                ]);
            }
        }
    }


    /*
        *   Aca se descuenta el stock del deposito de origen
            En caso de que sea:

            * Una venta
            * Un movimiento de deposito
            * Movimiento de produccion
    */
    function checkFromAddress() {
        if (!is_null($this->stock_movement->from_address_id) && count($this->article->addresses) >= 1) {
            
            $this->article->load('addresses');

            Log::info('checkFromAddress addresses: ');
            
            $from_address = null;

            foreach ($this->article->addresses as $address) {

                if ($address->id == $this->stock_movement->from_address_id) {
                    $from_address = $address;
                }
            }

            if (!is_null($from_address)) {

                /* 
                    Ahora se va a sumar la cantidad
                    Porque si es una venta, va a ser un valor negativo
                */
                $new_amount = (float)$from_address->pivot->amount + $this->get_amount_for_from_address();

                Log::info('from_address: '.$from_address->pivot->amount);
                Log::info('+ : '.$this->stock_movement->amount);

                $this->article->addresses()->updateExistingPivot($from_address->id, [
                    'amount'    => $new_amount,
                ]);

            } else {

                $this->article->addresses()->attach($this->stock_movement->from_address_id, [
                    'amount'    => $this->get_amount_for_from_address(),
                ]);
            }
        }
    }

    function get_amount_for_from_address() {

        if (isset($this->request->is_movimiento_de_depositos)) {
            return (float)-$this->stock_movement->amount;
        }

        return $this->stock_movement->amount;
    }
}
