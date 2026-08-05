<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\RecipeHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\VinotecaPriceHelper;
use App\Http\Controllers\PriceChangeController;
use App\Http\Controllers\Stock\StockMovementController;
use App\Jobs\ProcessSendAdviseMail;
use App\Mail\ArticleAdvise;
use App\Models\Address;
use App\Models\Advise;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Description;
use App\Models\PriceType;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\SpecialPrice;
use App\Models\User;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ArticleHelper {

    static function checkRecipesForSetPirces($article, $instance) {
        $recipes = Self::get_recipes_que_tienen_este_articulo_como_insumo($article);
        foreach ($recipes as $recipe) {
            RecipeHelper::checkCostFromRecipe($recipe, $instance);
        }
    }

    static function check_recipes_despues_de_eliminar_articulo($recipes, $instance) {
        foreach ($recipes as $recipe) {
            RecipeHelper::checkCostFromRecipe($recipe, $instance);
        }
    }

    static function check_article_recipe_to_delete($article) {
        if (!is_null($article->recipe)) {
            $article->recipe->delete();
        }
    }

    static function get_recipes_que_tienen_este_articulo_como_insumo($article) {
        $article_id = $article->id;
        $recipes = Recipe::whereHas('articles', function(Builder $query) use ($article_id) {
                                $query->where('article_id', $article_id);
                            })
                            ->get();
        return $recipes;
    }

    static function setArticlesFinalPrice($company_name = null, $user_id = null) {

        if (is_null($user_id)) {

            if (!is_null($company_name)) {
                // echo ('company_name: '.$company_name);
                $user_id = User::where('company_name', $company_name)
                                ->first()->id;
            } else {
                $user_id = UserHelper::userId();
            }
        }
        
        $articles = Article::where('user_id', $user_id)
                            ->get();
        $index = 1;
        foreach ($articles as $article) {
            // echo('articulo '.$index.'</br>');
            Self::setFinalPrice($article, $user_id);
            $index++;
        }
    }

    /**
     * Calcula (y opcionalmente persiste) el costo real y el precio final de un articulo.
     *
     * @param $guardar_cambios bool Si es false, se pide una simulacion: no se debe escribir
     *        en la base. OJO: esto NO convierte a la funcion en pura. Aguas abajo,
     *        ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() sigue haciendo
     *        syncWithoutDetaching()/updateExistingPivot() sobre los pivots de tipos de precio
     *        sin mirar esta bandera, asi que esos pivots se escriben igual aunque se pida
     *        $guardar_cambios = false. Quien necesite una simulacion realmente sin efectos
     *        secundarios debe envolver el llamado en una transaccion con rollback.
     * @param $price_type_id_para_descripcion int|null Con un id de lista de precio, ademas del
     *        desglose del costo real y del precio final unico se concatenan las lineas del calculo
     *        de ESA lista (prompt 357/01), asi el front recibe un solo array que cuenta la cadena
     *        completa. Con null el desglose sale identico al historico: no toca ningun calculo.
     */
    static function setFinalPrice($article, $user_id = null, $user = null, $auth_user_id = null, $guardar_cambios = true, $price_types = null, $return_description = false, $price_type_id_para_descripcion = null) {

        // Log::info('setFinalPrice para '.$article->name.' ,id: '.$article->id.' con costo de '.$article->cost.' y precio de '.$article->price);

        $costo_real = null;

        $des = [];

        // Desglose de la lista de precio pedida (prompt 357/01). Se acumula aparte y se pega al
        // final de $des, no en el lugar donde se calcula: los renglones que vienen despues de las
        // listas (margen del proveedor, de la categoria, del articulo) son del precio final unico y
        // NO participan del precio de la lista. Intercalarlos haria creer que si.
        $des_lista = [];

        if (
            is_null($article->cost)
            && is_null($article->price)
            && (
                !UserHelper::uses_listas_de_precio($user)
                && !UserHelper::hasExtencion('ventas_en_dolares', $user)
            )
        ) {

            if ($guardar_cambios) {
                return $article;
            } 
            return [
                'costo_real'            => $costo_real,
                'final_price'           => null,
                'current_final_price'   => null,
            ];
        }
        
        if (is_null($user)) {
            if (is_null($user_id)) {
                $user = UserHelper::user();
            } else {
                $user = User::find($user_id);
            }
        }


        if ($article->cost) {

            $des[] = 'CALCULO DEL COSTO REAL';
            $des[] = 'Comienza con costo de '.Numbers::price($article->cost, true);

            $res = Self::aplicar_descuentos_e_iva($article, $article->cost, $user, $des);

            $costo_real = $res['price'];
            $des        = $res['des'];

            // La asignacion en memoria queda siempre afuera del if: los callers que piden
            // simulacion ($guardar_cambios = false) necesitan el valor en el objeto en memoria,
            // y ActualizarBBDD lo toma del array de retorno que se arma mas abajo con esta misma
            // variable. Antes de este fix el save() era incondicional y pisaba en la base el
            // costo real de cuentas que solo habian pedido una simulacion, sin que nada lo avisara.
            $article->costo_real = $costo_real;

            if ($guardar_cambios) {
                $article->save();
            }

            $des[] = 'Costo Real queda en = '.Numbers::price($costo_real, true);

        }


        $current_final_price = $article->final_price;


        // Pongo el precio en blanco si corresponde
        if (
            (
                !is_null($article->percentage_gain)
                && (float)$article->percentage_gain > 0
            ) 
            || (
                    !is_null($article->cost) 
                    && $article->apply_provider_percentage_gain 
                    && !is_null($article->provider) 
                    && !is_null($article->provider->percentage_gain)
                )
            ) {

            $article->price = null;
            $article->save();
            // Log::info('Se puso null el price');
        }


            Log::info('entro');

        if (
            (
                is_null($article->price) 
                || $article->price == ''
            )
            || (
                UserHelper::uses_listas_de_precio($user)
                // && UserHelper::hasExtencion('ventas_en_dolares', $user)
            )
        ) {

            Log::info('entro 1');


            $usar_lista_mas_iva = false;

            if (!is_null($article->provider) && (bool)$article->provider->price_from_cost_mas_iva) {
                $usar_lista_mas_iva = true;
            }

            if ($usar_lista_mas_iva && !is_null($article->cost)) {

                // PRECIO DE VENTA = PRECIO LISTA (cost) + IVA
                $cost = (float) $article->cost;

                $des[] = 'CALCULO DEL PRECIO FINAL';
                $des[] = 'Comienza con el costo de lista = '.Numbers::price($cost, true);

                if ($article->unidades_individuales) {
                    $cost = $cost / $article->unidades_individuales;
                }

                $res = ArticlePricesHelper::aplicar_iva($article, $cost, $user, $des);
                $final_price = $res['price'];
                $des         = $res['des'];

                // Respeta la lógica de cotización si aplica (dólares)
                if (
                    !UserHelper::uses_listas_de_precio($user)
                    || !UserHelper::hasExtencion('ventas_en_dolares', $user)
                ) {
                    $res = Self::cotizar($article, $user, $final_price, $des);
                    $final_price = $res['price'];
                    $des        = $res['des'];
                }

            } else {

                // MODO NORMAL (actual): PRECIO = costo_real + margen + etc

                $des[] = 'CALCULO DEL PRECIO FINAL';

                $cost = $article->costo_real;

                $des[] = 'Comienza con costo real en = '.Numbers::price($cost, true);

                if (!is_null($user->percentage_gain)) {
                    $cost += $cost * $user->percentage_gain / 100;
                    $des[] = 'Mas ganancia del usuario del '.$user->percentage_gain.'% = '.Numbers::price($cost, true);
                }

                if ($article->unidades_individuales) {
                    $cost = $cost / $article->unidades_individuales;
                    $des[] = 'Dividido en '.$article->unidades_individuales.' unidades individuales = '.Numbers::price($cost, true);
                }

                $final_price = $cost;

                if (
                    UserHelper::uses_listas_de_precio($user)
                    && UserHelper::hasExtencion('ventas_en_dolares', $user)
                ) {

                } else {
                    $res = Self::cotizar($article, $user, $final_price, $des);
                    $final_price = $res['price'];
                    $des = $res['des'];
                }
                // if (
                //     $article->cost_in_dollars
                //     && $user->cotizar_precios_en_dolares
                // ) {
                //     if (!is_null($article->provider) && !is_null($article->provider->dolar) && (float)$article->provider->dolar > 0) {
                //         $final_price = $final_price * $article->provider->dolar;
                //     } else if ($article->cost_in_dollars > 0) {
                //         $final_price = $final_price * $user->dollar;
                //     }
                //     Log::info('Costo cotizado: '.$final_price);
                // }


                if (UserHelper::uses_listas_de_precio($user)) {

                    Log::info('uses_listas_de_precio');
                    
                    // ArticlePricesHelper::aplicar_precios_segun_listas_de_precios($article, $final_price, $user, $price_types);

                    if (UserHelper::hasExtencion('ventas_en_dolares', $user)) {
                        // Calculamos por tipo de precio y por moneda
                        ArticlePriceTypeMonedaHelper::aplicar_precios_por_price_type_y_moneda($article, $final_price, $user);
                        
                    } else {
                        // El desglose de la lista pedida se concatena al $des que se viene armando,
                        // para que el front reciba un solo array con la cadena entera. Sin
                        // $price_type_id_para_descripcion esto devuelve [] y no cambia nada.
                        $des_lista = ArticlePricesHelper::aplicar_precios_segun_listas_de_precios($article, $final_price, $user, $price_types, $price_type_id_para_descripcion);
                    }

                } else if (UserHelper::hasExtencion('lista_de_precios_por_categoria', $user)) {

                    ArticlePricesHelper::aplicar_precios_segun_listas_de_precios_y_categorias($article, $final_price, $user);

                } 

                if ($article->apply_provider_percentage_gain) {


                    if (!is_null($article->provider_price_list)) {
                        $final_price = $final_price + ($final_price * $article->provider_price_list->percentage / 100);

                    } else if ((!is_null($article->provider) && $article->provider->percentage_gain)) {
                        $final_price = $final_price + ($final_price * $article->provider->percentage_gain / 100);
                        $des[] = 'Mas margen del proveedor del '.$article->provider->percentage_gain.'% = '.Numbers::price($final_price, true);
                        
                        // Log::info('Aplicando margen del proveedor de '.$article->provider->percentage_gain.', quedo en '.$final_price);

                    } 
                }

                $res = ArticlePricesHelper::aplicar_category_percentage_gain($article, $final_price, $des);
                $final_price = $res['price'];
                $des         = $res['des'];


                if (!is_null($article->percentage_gain)) {
                    // Log::info('Sumando percentage_gain, va en '.$final_price);
                    
                    $final_price += $final_price * $article->percentage_gain / 100;
                    $des[] = 'Mas margen del articulo del '.$article->percentage_gain.'% = '.Numbers::price($final_price, true);
                    // Log::info('Y quedo en '.$final_price);
                }

                if (UserHelper::hasExtencion('vinoteca', $user)) {

                    $final_price = VinotecaPriceHelper::calcular_presentacion($article, $final_price);
                }
            }



            // Log::info('final_price: '.$final_price);
        } else {

            $des[] = 'CALCULO DEL PRECIO FINAL';
            $final_price = $article->price;
            $des[] = 'Usando el precio manual de '.Numbers::price($final_price, true);
        }

        // $final_price = ArticlePricesHelper::aplicar_iva($article, $final_price, $user);

        $res = ArticlePricesHelper::aplicar_recargos($article, $final_price, true, $des);
        $final_price = $res['price'];
        $des = $res['des'];
        
        // Log::info('aplico iva y final_price: '.$final_price);


        if (!$user->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia) {

            $res = ArticlePricesHelper::aplicar_descuentos($article, $final_price, $des);
            $final_price = $res['price'];
            $des = $res['des'];
            
            $res = ArticlePricesHelper::aplicar_recargos($article, $final_price, $des);
            $final_price = $res['price'];
            $des = $res['des'];

            // Log::info('Aplicando recargos despues del margen de ganancia');
        }

        // Capa 2 (Prompt 261): sale_taxes (IIBB y afines) se aplican con fórmula de división,
        // después del margen/price_type_surchages y ANTES del IVA de venta. Aplica siempre
        // (Responsable Inscripto o Monotributista), ya que es un impuesto distinto del IVA.
        $res = ArticlePricesHelper::aplicar_sale_taxes($article, $final_price, $user, $des);
        $final_price = $res['price'];
        $des   = $res['des'];

        if (!ArticlePricesHelper::iva_va_al_costo($user)) {

            $res = ArticlePricesHelper::aplicar_iva($article, $final_price, $user, $des);
            $final_price = $res['price'];
            $des   = $res['des'];
        }

        $res = Self::redondear($final_price, $user, $des);
        $final_price = $res['price'];
        $des = $res['des'];

        $article->final_price = $final_price;



        // if (
        //     !is_null($current_final_price)
        //     && $current_final_price != $article->final_price
        // ) {
        if (
            $current_final_price != $article->final_price
        ) {

            $article->previus_final_price = $current_final_price; 
            $article->final_price_updated_at = Carbon::now();
            PriceChangeController::store($article, $auth_user_id);
        }


        if (UserHelper::hasExtencion('articulos_precios_en_blanco', $user)) {

            // Se pasa $user para poder aplicar sale_taxes tambien en el precio en blanco (Prompt 261)
            $article = ArticlePricesHelper::set_precios_en_blanco($article, $user);
        }

        ProductService::add_article_to_sync($article);
        TiendaNubeSyncArticleService::add_article_to_sync($article);
        
        if ($guardar_cambios) {
            $article->timestamps = false;
            $article->save();

            if ($return_description) {

                if (count($des_lista)) {
                    $des = array_merge($des, $des_lista);
                }

                return $des;
            }

            // Capa 3 (Prompt 263, hotfix Prompt 313): `precios_por_metodo_pago` ahora es un accessor
            // del modelo Article (getPreciosPorMetodoPagoAttribute), no se asigna aca para evitar que
            // save() posteriores en la misma request intenten persistir una columna inexistente.

            return $article;
        } else {
            return [
                'costo_real'            => $costo_real,
                'final_price'           => $final_price,
                'current_final_price'   => $current_final_price,
                // Capa 3 (Prompt 263): desglose de precio equivalente por metodo de pago (null si el
                // flag `precio_base_incluye_tarjeta` esta apagado o no hay reglas de recargo configuradas).
                'precios_por_metodo_pago' => ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida($final_price, $user->id),
            ];
        }

    }

    static function cotizar($article, $user, $price, $des) {

        if (
            $article->cost_in_dollars
            && $user->cotizar_precios_en_dolares
        ) {
            if (!is_null($article->provider) && !is_null($article->provider->dolar) && (float)$article->provider->dolar > 0) {
                $price = $price * $article->provider->dolar;
                $des[] = 'Cotizando al dolar del proveedor '.Numbers::price($article->provider->dolar, true).' = '.Numbers::price($price, true);
            } else if ($article->cost_in_dollars > 0) {
                $price = $price * $user->dollar;
                $des[] = 'Cotizando al dolar global '.Numbers::price($user->dollar, true).' = '.Numbers::price($price, true);
            }
            Log::info('Costo cotizado: '.$price);
        }
        return [
            'price' => $price,
            'des'   => $des,
        ];
    }

    static function aplicar_descuentos_e_iva($article, $price, $user, $des) {

        $res = ArticlePricesHelper::aplicar_descuentos($article, $price, $des);
        $price = $res['price'];
        $des   = $res['des'];

        $res = ArticlePricesHelper::aplicar_recargos($article, $price, false, $des);
        $price = $res['price'];
        $des   = $res['des'];

        // Prompt 261: se elimina aplicar_provider_discounts() de este flujo. Las bonificaciones
        // del proveedor ya no pisan el costo real "de catálogo" del artículo (Capa 1). Pasan a
        // pre-completar campos editables en la orden de compra (prompt 262), fuera de este cálculo.

        if (ArticlePricesHelper::iva_va_al_costo($user)) {

            $res = ArticlePricesHelper::aplicar_iva($article, $price, $user, $des);
            $price = $res['price'];
            $des   = $res['des'];
        }

        return [
            'price' => $price,
            'des'   => $des,
        ];
    }

    /**
     * Aplica reglas de redondeo del usuario sobre un precio.
     *
     * @param float|int $price Precio base a redondear.
     * @param \App\Models\User $user Usuario autenticado con flags de redondeo.
     * @param array $des Descripciones acumuladas de cada transformación.
     * @return array{price:float|int,des:array}
     */
    static function redondear($price, $user, $des = []) {

        if ($user->redondear_miles_en_vender) {
            $price = round($price / 1000) * 1000;
            $des[] = 'Redondeando por mil = '.Numbers::price($price, true);
        }

        if ($user->redondear_centenas_en_vender) {

            if ($price > 100) {
                
                $price = round($price, -2);
                $des[] = 'Redondeando por centenas = '.Numbers::price($price, true);
            }
        }

        if ($user->redondear_precios_en_decenas) {
            $price = round($price, -1);
            $des[] = 'Redondeando por decenas = '.Numbers::price($price, true);
        }

        if ($user->redondear_de_a_50) {
            $price = ceil($price / 50) * 50;
            $des[] = 'Redondeando de a 50 = '.Numbers::price($price, true);
        }

        if ($user->redondear_precios_en_centavos) {
            $price = round($price);
            $des[] = 'Redondeando centavos = '.Numbers::price($price, true);
        }
        return [
            'price' => $price,
            'des'   => $des,
        ];
    }

    static function setStockFromStockMovement($article) {
        Log::info($article->name. ' stock_movements: ');
        Log::info($article->stock_movements);
        if (count($article->stock_movements) == 1) {
            $fisrt_stock_movement = $article->stock_movements[0];
            if (is_null($fisrt_stock_movement->to_address_id)) {
                $article->stock = $fisrt_stock_movement->amount;
                $article->save();
            } else {
                $article->addresses()->attach($fisrt_stock_movement->to_address_id, [
                    'amount'    => $fisrt_stock_movement->amount,
                ]);
                Log::info('se agrego address '.$fisrt_stock_movement->to_address->street.' a '.$article->name);
                Self::setArticleStockFromAddresses($article);
            }
        }
    }

    static function clearCost($article) {
        $cost = substr($article->cost, 0, strpos($article->cost, '.'));
        $decimals = substr($article->cost, strpos($article->cost, '.')+1);
        if (substr($decimals, 2) == '0000') {
            $decimals = substr($decimals, 0, 2);
        }
        $article->cost = floatval($cost.'.'.$decimals);
    }

    static function getById($articles_ids) {
        $models = [];
        foreach ($articles_ids as $id) {
            $models[] = ArticleHelper::getFullArticle($id);
        }
        return $models;
    }

    static function getChartsFromArticle($id, $from_date, $until_date) {
        $result = [];
        $index = 0;
        $start = Carbon::parse($from_date);
        $end = Carbon::parse($until_date);
        while ($start <= $end) {
            $from_date = $start->format('Y-m-d H:i:s');
            $until_date = $start->addDay()->format('Y-m-d H:i:s');
            $sales = Sale::where('user_id', UserHelper::userId())
                            ->whereHas('articles', function(Builder $query) use ($id) {
                                $query->where('article_id', $id);
                            })
                            ->whereBetween('created_at', [$from_date, $until_date])
                            ->get();
            if (count($sales) >= 1) {
                $unidades_vendidas = 0;
                foreach ($sales as $sale) {
                    foreach ($sale->articles as $article) {
                        if ($article->id == $id) {
                            $unidades_vendidas += $article->pivot->amount;
                        }
                    }
                }
                $result[$index]['date'] = $from_date;
                $result[$index]['unidades_vendidas'] = $unidades_vendidas;
                $index++;
            }
        }
        return $result;
    }

    static function getSalesFromArticle($article_id, $from_date, $until_date) {
        $inicio = Carbon::parse($from_date)->startOfDay();
        $fin    = Carbon::parse($until_date)->endOfDay();

        $sales = Sale::query()
                ->where('user_id', UserHelper::userId())
                ->whereHas('articles', function (Builder $query) use ($article_id) {
                    $query->where('articles.id', $article_id);
                })
                ->whereBetween('sales.created_at', [$inicio, $fin])
                ->orderBy('created_at', 'DESC')
                ->withAll()
                ->get();


        return $sales;
    }

    static function lastProviderPercentageGain($article) {
        if (!is_null($article->provider) && $article->provider->percentage_gain) {
            return $article->provider->percentage_gain;
        } 
        return null;
        // $last_provider = Self::lastProvider($article);
        // if (!is_null($last_provider) && !is_null($last_provider->percentage_gain)) {
        //     return $last_provider->percentage_gain;
        // }
        // return null;
    }

    static function lastProvider($article) {
        if (count($article->providers) >= 1) {
            $last_provider = $article->providers[count($article->providers)-1];
            if (!is_null($last_provider)) {
                return $last_provider;
            }
        }
        return null;
    }

    static function hasIva($article) {
        return !is_null($article->iva) && $article->iva->percentage != '0' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 'No Gravado'; 
    }

    static function setIva($articles) {
        $ct = new Controller();
        foreach ($articles as $article) {
            $article->iva_id = $ct->getModelBy('ivas', 'id', $article->iva_id, false, 'percentage'); 
        }
        return $articles;
    }

    static function attachProvider($request, $article, $actual_provider_id = null, $actual_stock = null) {
        if ($actual_provider_id != $request->provider_id || $actual_stock != $request->stock) {
            $article->providers()->attach($request->provider_id, [
                                            'amount' => $request->stock,
                                            'cost'   => $request->cost,
                                            'price'  => $article->final_price,
                                        ]);
            Log::info('Se agrego provider');
        }
    }

    static function saveProvider($article, $request) {
        if (
            // No tiene provedor y llega uno en request
            (count($article->providers) == 0 && $request->provider_id != 0) ||

            // Tiene provedores, llega provedor en request, y el ultimo proveedor que tiene es distinto del que llego
            (count($article->providers) >= 1 && $request->provider_id != 0 && $article->providers[count($article->providers)-1]->id != $request->provider_id) ||

            // Tiene proveedor, llega el mismo proveedor pero con otro costo
            (count($article->providers) >= 1 && $article->providers[count($article->providers)-1]->id == $request->provider_id && $article->cost != $request->cost) ||

            // Tiene proveedor, llega el mismo proveedor pero con otro stock
            (count($article->providers) >= 1 && $article->providers[count($article->providers)-1]->id == $request->provider_id && $article->stock != $request->stock)
        ) {
            Log::info('entro a guardar proveedor');
            $request_stock = (float)$request->stock;
            if ($request_stock > 0) {
                if (!is_null($article->stock)) {
                    $stock_actual = $article->stock;
                } else {
                    $stock_actual = 0;
                }
                $amount = $request_stock - $stock_actual;
            } else {
                $amount = null;
            }
            $article->providers()->attach($request->provider_id, [
                                    'amount'    => $amount,
                                    'cost'      => $request->cost,
                                    // 'price'     => $request->price,
                                ]);
        }
    }

    static function setDiscount($articles) {
        foreach ($articles as $article) {
            if (count($article->article_discounts) >= 1) {
                $article->slug = $article->article_discounts[0]->percentage;
            } else {
                $article->slug = 'no tinee';
            }
            // foreach ($article->article_discounts as $discount) {
            //     $article->slug .= $discount->percentage.' ';
            // }
        }
        return $articles;
    }

    /**
     * Chequea los avisos de "avisame cuando esté disponible" de un artículo y dispara el mail
     * correspondiente cuando corresponde.
     *
     * Regla de oro: notificar avisos NUNCA puede romper la operación de stock que la disparó
     * (setArticleStock corre en el mismo request con QUEUE_CONNECTION=sync). Por eso todo el
     * cuerpo va envuelto en un try/catch general que loguea y no relanza.
     *
     * @param \App\Models\Article|null $article Artículo cuyo stock se acaba de actualizar.
     * @param float|null $stock_anterior Stock que tenía el artículo ANTES del movimiento que
     *      disparó este chequeo. Se usa para detectar la transición sin-stock -> con-stock
     *      (0/negativo -> positivo). Si viene null (call sites viejos sin actualizar), se
     *      mantiene el comportamiento anterior: dispara con stock >= 1 sin mirar la transición.
     * @return void
     */
    static function checkAdvises($article, $stock_anterior = null) {
        try {
            // Sin artículo (o sin id), no hay nada que chequear.
            if (is_null($article) || is_null($article->id)) {
                return;
            }

            // Stock actual normalizado a float, contemplando null como 0.
            $stock_actual = is_null($article->stock) ? 0 : (float) $article->stock;

            // Si no hay stock, no hay nada que avisar.
            if ($stock_actual < 1) {
                return;
            }

            // Disparar solo en la transición sin-stock -> con-stock. Si ya tenía stock antes de
            // este movimiento, no es un "ingreso de stock" para el que estaba esperando (ej: una
            // venta que deja el stock en 3 no debe disparar el mail de "ingresó nuevo stock").
            if (!is_null($stock_anterior) && (float) $stock_anterior >= 1) {
                return;
            }

            // Avisos pendientes para este artículo.
            $advises = Advise::where('article_id', $article->id)->get();
            if (count($advises) < 1) {
                return;
            }

            // Gate por cliente (prompt 383, reemplaza a env('SEND_MAILS')). Se resuelve una sola vez
            // para todos los avisos de este articulo.
            // $article puede llegar como stdClass parcial desde algunos callers de stock, asi que el
            // user_id se resuelve del modelo real si el objeto no lo trae.
            $owner_id = (isset($article->user_id) && !is_null($article->user_id)) ? $article->user_id : null;

            if (is_null($owner_id)) {
                $article_model = Article::find($article->id);
                $owner_id = is_null($article_model) ? null : $article_model->user_id;
            }

            if (!MailNotificationConfigHelper::avisarIngresoStock($owner_id)) {
                // Avisos deshabilitados para este cliente: los avisos quedan PENDIENTES, no se
                // borran, para poder mandarlos si el comercio los habilita mas adelante.
                Log::info('checkAdvises: avisos de stock deshabilitados para el cliente, quedan pendientes', [
                    'article_id' => $article->id,
                    'user_id'    => $owner_id,
                    'advises'    => count($advises),
                ]);
                return;
            }

            foreach ($advises as $advise) {
                // Un advise roto (email inválido, etc.) no debe cortar a los demás.
                try {
                    // Normalizo el email antes de validarlo.
                    $email = trim((string) $advise->email);

                    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        // Fila basura (endpoint sin validar antes de prompts 354/355): se borra.
                        Log::warning('checkAdvises: advise con email invalido, se borra', [
                            'advise_id'  => $advise->id,
                            'article_id' => $article->id,
                            'email'      => $email,
                        ]);
                        $advise->delete();
                        continue;
                    }

                    ProcessSendAdviseMail::dispatch($advise, $article);
                } catch (\Exception $e) {
                    Log::error('checkAdvises: error procesando advise individual', [
                        'advise_id'  => isset($advise->id) ? $advise->id : null,
                        'article_id' => $article->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Nunca dejar que un fallo de avisos rompa la operación de stock que lo disparó.
            Log::error('checkAdvises: error general, no se afecta el stock', [
                'article_id' => isset($article->id) ? $article->id : null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    static function discountStock($id, $amount, $sale, $previus_articles, $se_esta_confirmando_por_primera_vez = false, $article_variant_id = null) {
        $article = new \stdClass();
        $article->id = $id;

        Log::info('discountStock');

        $res = Self::get_amount_for_stock_movement($sale, $article, $amount, $previus_articles, $se_esta_confirmando_por_primera_vez);
        
        $concepto = $res['concepto'];
        $amount = $res['amount'];

        Log::info('amount: '.$amount);
        if ($amount != 0) {
            Self::storeStockMovement($article, $sale->id, $amount, $sale->address_id, null, $concepto, $article_variant_id);
        }
 
    }



    /*
        Chequeo si hay previus_articles
            * Si hay, es porque se esta editando una venta
                Entonces busco la cantidad previa 
                    Si la encuentro, obtengo la direfencia entre la cantidad previa y la nueva
                    Si no la encuentro, retorno la cantidad original y el concepto de Venta

            * Si no hay, retorno la cantidad original y el concepto de Venta
    */

    static function get_amount_for_stock_movement($sale, $article, $amount, $previus_articles, $se_esta_confirmando_por_primera_vez) {
        if (!is_null($previus_articles) && !$se_esta_confirmando_por_primera_vez) {
            $previus_amount = null;
            $new_amount = null;

            foreach ($previus_articles as $previus_article) {
                if ($previus_article->id == $article->id) {
                    $previus_amount = $previus_article->pivot->amount;
                }
            }

            if (!is_null($previus_amount)) {
                $new_amount = (float)$previus_amount - (float)$amount;

                return [
                    'amount'    => $new_amount,
                    'concepto'  => 'Act Venta',
                ];
            }
        }
        return [
            'amount'    => -(float)$amount,
            'concepto'  => 'Venta',
        ];
    }

    static function storeStockMovement($article, $sale_id, $amount, $from_address_id = null, $to_address_id = null, $concepto = null, $article_variant_id = null) {

        $ct = new StockMovementController();

        $data = [

            'model_id'                      => $article->id,
            'from_address_id'               => $from_address_id,
            'to_address_id'                 => $to_address_id,
            'amount'                        => $amount,
            'sale_id'                       => $sale_id,
            'concepto_stock_movement_name'  => $concepto,
            'article_variant_id'            => $article_variant_id,
        ];

        $ct->crear($data, false);
    }

    static function setArticleStockFromAddresses($article, $check_linkage = true, $user_id = null) {

        if (is_null($user_id)) {
            $user_id = UserHelper::userId();
        }

        if (!is_object($article)) {
            $article = Article::find($article['id']);
        }

        $article->load('addresses');

        if (!is_null($article)
            && (
                    count($article->addresses) >= 1
                    || count($article->article_variants) >= 1
                )
            ) {

            $stock = 0;

            if (count($article->article_variants) >= 1) {
                
                Log::info('Se seteo stock de las direcciones con variants');

                $variants_con_addresses = false;

                $addresses = Self::get_addresses($user_id);
                
                foreach ($article->article_variants as $article_variant) {
                    
                    if (count($article_variant->addresses) >= 1) {

                        $variants_con_addresses = true;

                        // $addresses = Self::get_addresses($user_id);

                        $article_variant_stock = 0;

                        foreach ($article_variant->addresses as $variant_address) {

                            $addresses[$variant_address->pivot->address_id] += (float)$variant_address->pivot->amount;

                            $article_variant_stock += (float)$variant_address->pivot->amount;

                            $stock += (float)$variant_address->pivot->amount;
                        }

                        $article_variant->stock = $article_variant_stock;
                        $article_variant->save();

                    } else {

                        // Log::info('Sumando '.$article_variant->stock.' de la variante '.$article_variant->variant_description);

                        $stock += (float)$article_variant->stock;

                    }

                }

                if ($variants_con_addresses) {

                    Self::actualizar_article_addresses($article, $addresses);
                    Log::info('Se actualizaron las addresses en base a las direcciones de las variantes');
                }

            } else if (count($article->addresses) >= 1) {
                
                foreach ($article->addresses as $article_address) {
                    Log::info('Sumando '.$article_address->pivot->amount.' de '.$article_address->street);
                    $stock += $article_address->pivot->amount;
                }
                Log::info('Se seteo stock con direcciones = '.$stock);

            } 


            $article->stock = $stock;
            $article->timestamps = false;
            $article->save();

            if ($check_linkage) {
                $ct = new InventoryLinkageHelper();
                $ct->check_is_agotado($article);
            }
        } 
    }

    static function actualizar_article_addresses($article, $addresses) {
            
        $article->addresses()->sync([]);

        Log::info('actualizar_article_addresses:');
        Log::info($addresses);
        
        foreach ($addresses as $address_id => $amount) {

            $_address = Address::find($address_id);  
            
            // $this->info($_address->street.' = '.$amount);
            
            $article->addresses()->attach($address_id, [
                'amount'    => $amount,
            ]); 
        }
    }

    static function get_addresses($user_id) {

        $addresses = [];

        $user_addresses = Address::where('user_id', $user_id)
                                    ->get();

        foreach ($user_addresses as $address) {
            
            $addresses[$address->id] = 0;
        }

        return $addresses;
    }

    static function resetStock($article, $amount, $sale) {
        Self::storeStockMovement($article, $sale->id, $amount, null, $sale->address_id, 'Se elimino la venta', $article->pivot->article_variant_id);
    }

    static function getShortName($name, $length) {
        if (strlen($name) > $length) {
            $name = substr($name, 0, $length) . '..';
        }
        return $name;
    }

    static function setSpecialPrices($article, $request) {
        $special_prices = SpecialPrice::where('user_id', UserHelper::userId())->get();
        if ($special_prices) {
            $article->specialPrices()->sync([]);
            foreach ($special_prices as $special_price) {
                if ($request->{$special_price->name} != '') {
                    $article->specialPrices()
                    ->attach(
                        $special_price->id, 
                        ['price' => (double)$request->{$special_price->name}]
                    );
                }
            }
        }
    }

    static function setDeposits($article, $request) {
        $article->deposits()->detach();
        if (isset($request->deposits)) {
            foreach ($request->deposits as $deposit) {
                if (isset($deposit['pivot']) && $deposit['pivot']['value'] != '') {
                    $article->deposits()->attach($deposit['id'], [
                                                    'value' => $deposit['pivot']['value'],
                                                ]);
                }
            }
        }
    }

    static function setTags($article, $tags) {
        $article->tags()->sync([]);
        if (isset($tags)) {
            foreach ($tags as $tag) {
                $article->tags()->attach($tag['id']);
            }
        }
    }

    static function setDescriptions($article, $descriptions) {
        $article_descriptions = Description::where('article_id', $article->id)
                                            ->get();
        foreach ($article_descriptions as $article_description) {
            $article_description->delete();
        }
        if ($descriptions) {
            foreach ($descriptions as $description) {
                // $description = (array) $description;
                if (isset($description['content']) && !is_null($description['content'])) {
                    Description::create([
                        'title'      => isset($description['title']) ? StringHelper::onlyFirstWordUpperCase($description['title']) : null,
                        'content'    => $description['content'],
                        'article_id' => $article->id,
                    ]);
                }
            }
        }
    }

    static function setSizes($article, $sizes_id) {
        $article->sizes()->sync([]);
        if ($sizes_id) {
            foreach ($sizes_id as $size_id) {
                $article->sizes()->attach($size_id);
            }
        }
    }

    static function setColors($article, $colors) {
        $article->colors()->sync([]);
        if ($colors) {
            foreach ($colors as $color) {
                $article->colors()->attach($color['id']);
            }
        }
    }

    static function setCondition($article, $condition_id) {
        if ($condition_id) {
            $article->condition_id = $condition_id;
            $article->save();
        }
    }

    static function deleteVariants($article) {
        foreach ($article->variants as $variant) {
            $variant->delete();
        }
    }

    static function getStockVariantToAdd($variant) {
        if (isset($variant['stock_to_add']) && $variant['stock_to_add'] != '') {
            return $variant['stock'] + $variant['stock_to_add'];
        }
        return $variant['stock'];
    }

    static function slug($name, $ignore_id = null, $user_id = null) {
        if (is_null($user_id)) {
            $user_id = UserHelper::userId();
        }
        $index = 1;
        $slug = Str::slug($name);
        $repeated_article = Article::where('user_id', $user_id)
                                    ->where('slug', $slug);
        if (!is_null($ignore_id)) {
            $repeated_article = $repeated_article->where('id', '!=', $ignore_id);
        }
        $repeated_article = $repeated_article->first();
        
        while (!is_null($repeated_article)) {
            $slug = substr($slug, 0, strlen($name));
            $slug .= '-'.$index;
            $repeated_article = Article::where('user_id', $user_id)
                                        ->where('slug', $slug)
                                        ->first();
            $index++;
        }
        return $slug;
    }

    static function setArticlesKey($articles) {
        foreach ($articles as $article) {
            if ($article->pivot->variant_id) {
                $article->key = $article->id . '-' . $article->pivot->variant_id;
            } else {
                $article->key = $article->id;
            }
        }
        return $articles;
    }

    static function setArticlesKeyAndVariant($articles) {
        foreach ($articles as $article) {
            if (isset($article->pivot) && $article->pivot->variant_id) {
                foreach ($article->variants as $variant) {
                    if ($variant->id == $article->pivot->variant_id) {
                        $article->variant = $variant;
                    }
                }
                $article->key = $article->id . '-' . $article->pivot->variant_id;
            } else {
                $article->key = $article->id;
            }
        }
        return $articles;
    }

    static function getFullArticle($article_id) {
        $article = Article::where('id', $article_id)
                            ->withAll()
                            ->first();
        // $article = Self::setPrices([$article])[0];
        return $article;
    }

    static function price($price) {
        $pos = strpos($price, '.');
        if ($pos != false) {
            $centavos = explode('.', $price)[1];
            $new_price = explode('.', $price)[0];
            if ($centavos != '00') {
                $new_price += ".$centavos";
                return '$'.number_format($new_price, 2, ',', '.');
            } else {
                return '$'.number_format($new_price, 0, '', '.');           
            }
        } else {
            return '$'.number_format($price, 0, '', '.');
        }
    }

    static function getFirstImage($article) {
        if (count($article->images) >= 1) {
            $first_image = $article->images[0]->hosting_url;
            foreach ($article->images as $image) {
                if ($image->first != 0) {
                    $first_image = $image->hosting_url;
                }
            }
            if (config('app.APP_ENV') == 'production') {
                $position = strpos($first_image, 'storage');
                $first = substr($first_image, 0, $position);
                $end = substr($first_image, $position);
                return $first.'public/'.$end;
            }
            return $first_image;
        }
        return null;
    }
}