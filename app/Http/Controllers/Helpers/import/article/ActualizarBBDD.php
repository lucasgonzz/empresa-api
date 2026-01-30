<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\ArticleUbicationsHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Stock\StockMovementController;
use App\Jobs\ProcessSyncArticleToTiendaNube;
use App\Models\Article;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use App\Models\ArticleUbication;
use App\Models\ArticleVariant;
use App\Models\PriceType;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ActualizarBBDD {

    function __construct($articulos_para_crear_CACHE, $articulos_para_actualizar_CACHE, $user, $auth_user_id) {
        
        Log::info('');
        Log::info('********* ActualizarBBDD ************');
        Log::info('');

        Log::info('articulos_para_crear_CACHE:');
        Log::info('Cantidad: '.count($articulos_para_crear_CACHE));
        // Log::info($articulos_para_crear_CACHE);

        Log::info('articulos_para_actualizar_CACHE:');
        Log::info('Cantidad: '.count($articulos_para_actualizar_CACHE));
        // Log::info($articulos_para_actualizar_CACHE);

        $this->user                                 = $user;
        $this->auth_user_id                         = $auth_user_id;

        $this->articulos_para_crear_CACHE           = $articulos_para_crear_CACHE;
        $this->articulos_para_actualizar_CACHE      = $articulos_para_actualizar_CACHE;

        $this->articulos_creados_models = [];
        $this->articulos_actualizados_models = [];

        $this->stock_movement_ct = new StockMovementController(false);

        $this->now = Carbon::now()->toDateTimeString();

        $this->observations = '';


        $this->set_price_types();

        $this->guardar_articulos();
    }

    function get_observations() {
        return $this->observations;
    }

    function add_observation($text) {
        $this->observations .= $text .' - ';
    }

    function guardar_articulos() {

        $inicio_global = microtime(true);

        // Crear los artículos nuevos en la bbdd
        if (!empty($this->articulos_para_crear_CACHE)) {
            
            $inicio = microtime(true);
            Log::info('Se van a crear ' . count($this->articulos_para_crear_CACHE) . ' articulos');
            // if (app()->environment('local')) { Log::info($this->articulos_para_crear_CACHE); }

            // Log::info('sql:');
            $sql = array_map(function ($art) {
                return collect($art)->except([
                    'price_types_data',
                    'discounts',
                    'surchages',
                    'stock_global',
                    'stock_addresses',
                    'variants_data',
                    'id',
                    'fake_id',
                ])->merge([
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ])->toArray();
            }, $this->articulos_para_crear_CACHE);

            // Log::info($sql);
            
            Article::insert($sql);

            $fin = microtime(true);
            $dur = $fin - $inicio;

            $this->add_observation('Art insertados en bbdd en '.number_format($dur, 2, '.', '').' seg');

            $this->set_articulos_creados_models();
        }

        // Actualizar artículos existentes por lote con SQL crudo
        if (!empty($this->articulos_para_actualizar_CACHE)) {

            $this->set_articulos_actualizados_models();

            
            if (count($this->articulos_actualizados_models) > 0) {
                
                $inicio = microtime(true);

                Log::info('Se van a actualizar ' . count($this->articulos_actualizados_models) . ' articulos');

                $table = (new Article)->getTable();
                $casesByColumn = [];
                $ids = [];

                $casesByColumn['updated_at'] = "`updated_at` = CASE `id`";

                foreach ($this->articulos_para_actualizar_CACHE as $row) {

                    $id = DB::getPdo()->quote($row['id']);
                    $ids[] = $row['id'];

                    // Log::info($row);

                    foreach ($row as $column => $value) {

                        if (
                            $column === 'id'
                            || $column === 'price_types_data'
                            || $column === 'discounts'
                            || $column === 'surchages'
                            || $column === 'stock_global'
                            || $column === 'stock_addresses'
                            || $column === 'variants_data'
                            || str_contains($column, '__diff__') 

                            || is_null($value)
                            || $value === ''
                        ) continue;

                            
                        $quotedValue = DB::getPdo()->quote($value);

                        if (!isset($casesByColumn[$column])) {
                            $casesByColumn[$column] = "`$column` = CASE `id`";
                        }

                        $casesByColumn[$column] .= " WHEN $id THEN $quotedValue";

                    }

                    $casesByColumn['updated_at'] .= " WHEN $id THEN " . DB::getPdo()->quote($this->now);
                }

                foreach ($casesByColumn as $column => $case) {
                    $casesByColumn[$column] .= " ELSE `$column` END";
                }

                $updateSql = "UPDATE `{$table}` SET ";
                $sets = [];
                foreach ($casesByColumn as $column => $case) {
                    $sets[] = $case;
                }
                $updateSql .= implode(', ', $sets);
                $updateSql .= " WHERE `id` IN (" . implode(',', $ids) . ")";

                DB::statement($updateSql);

                $fin = microtime(true);
                $dur = $fin - $inicio;
                $this->add_observation('Art actualizados en bbdd en '.number_format($dur, 2, '.', '').' seg'); 

            }

        }

        Log::info('');
        Log::info('');
        
        // 🔁 Actualizar Stock
        $this->actualizar_stock(true);
        $this->actualizar_stock(false);
        Log::info('Se actualizo stock');


        // 🔁 Asignar price_types (a nuevos y actualizados)
        $this->asignar_price_types();
        Log::info('Se asignaron price_types');

 
        // 🔁 Asignar descuentos (a nuevos y actualizados)
        $this->asignar_discounts_percentages();
        $this->asignar_discounts_amounts();
        Log::info('Se asignaron discounts');

 
        // 🔁 Asignar recargos (a nuevos y actualizados)
        $this->asignar_surchages_percentages();
        $this->asignar_surchages_amounts();
        Log::info('Se asignaron surchages');

        // 👉 Calcular precios finales para todos los artículos
        // $this->calcular_precios_finales(true);
        // $this->calcular_precios_finales(false);


        /* 
            Vuelvo a llamar para que se vuelvan a cargar los articulos creados y actualizados
            y se setee al precio final con los datos actualizados como el iva_id y los price_types
        */
        $this->set_articulos_actualizados_models();
        // $this->set_articulos_creados_models();

        if (app()->environment('local')) { Log::info('Calculando precios finales'); }
        $this->set_precios_finales();


        $this->set_article_ubications();



        // Relaciono los articulos credos con el proveedor, el provider_code y el precio de este proveedor
        $this->set_articles_providers();



        $this->guardar_variantes_desde_cache_simple();


        // $this->actualizar_cache();

        $this->actualizar_tienda_nube();


        $fin_global = microtime(true);
        $dur = $fin_global - $inicio_global;

        $this->add_observation('ActualizarBBDD en '.number_format($dur, 2, '.', '').' seg'); 

    }

    function set_articles_providers() {

        $inicio = microtime(true);

        foreach ($this->articulos_creados_models as $article) {

            if (!$article->provider_id) {
                continue;
            }

            $pivot_data = [
                'provider_code' => $article->provider_code,
                'cost'          => $article->cost,
            ];

            $article->providers()->attach($article->provider_id, $pivot_data);
        }

        $fin = microtime(true);

        $dur = $fin - $inicio;

        $this->add_observation('set articles_providers en '.number_format($dur, 2, '.', '').' seg'); 

    }

    function set_article_ubications() {

        $ubications = ArticleUbication::where('user_id', $this->user->id)
                                        ->get();

        if (count($ubications) == 0) {
            return;
        }

        foreach ($this->articulos_creados_models as $article) {
            ArticleUbicationsHelper::init_ubications($article, $ubications);
        }

    }

    function actualizar_tienda_nube() {
        
        if (config('app.USA_TIENDA_NUBE')) {

            Log::info('Entra a actualizar tienda nube');

            foreach ($this->articulos_creados_models as $article) {
                TiendaNubeSyncArticleService::add_article_to_sync($article);
                // TiendaNubeSyncArticleService::add_article_to_sync($model);
                // dispatch(new ProcessSyncArticleToTiendaNube($article));
            }

            foreach ($this->articulos_actualizados_models as $article) {
                TiendaNubeSyncArticleService::add_article_to_sync($article);
                // dispatch(new ProcessSyncArticleToTiendaNube($article));
            }
        }
    }

    function asignar_discounts_percentages() {

        // Log::info('asignar_discounts_percentages');

        $insertData = [];

        foreach ($this->articulos_para_crear_CACHE as $article_cache) {

            if (
                !isset($article_cache['discounts'])
                || empty($article_cache['discounts'])
            ) continue;

            // Log::info('Descuentos del articulo para crear:');
            // Log::info($article_cache['discounts']);

            $article_model = $this->get_article_model_from_cache($article_cache);

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $insertData = $this->get_discounts_surchages_insert_data('discounts', $article_id, $article_cache, $insertData, '%');
            
        }



        $articles_id_para_eliminarles_descuentos = [];

        foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {

            if (
                !isset($article_cache['discounts'])
                || empty($article_cache['discounts'])
            ) continue;

            // Log::info('Descuentos del articulo para actualizar id '.$article_cache['id'].':');
            // Log::info($article_cache['discounts']);

            $article_model = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $articles_id_para_eliminarles_descuentos[] = $article_id;

            $insertData = $this->get_discounts_surchages_insert_data('discounts', $article_id, $article_cache, $insertData, '%');

        }


        DB::table('article_discounts')
            ->whereIn('article_id', $articles_id_para_eliminarles_descuentos)
            ->whereNotNull('percentage')
            ->delete();

        // Log::info('Se eliminaron descuentos con percentage de '.count($articles_id_para_eliminarles_descuentos).' articulos');

        if (!empty($insertData)) {
            DB::table('article_discounts')->insert($insertData);
        }
    }

    function asignar_discounts_amounts() {

        $insertData = [];

        foreach ($this->articulos_para_crear_CACHE as $article_cache) {

            if (
                !isset($article_cache['discounts'])
                || empty($article_cache['discounts'])
            ) continue;

            $article_model = $this->get_article_model_from_cache($article_cache);

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $insertData = $this->get_discounts_surchages_insert_data('discounts', $article_id, $article_cache, $insertData, 'amount');
            
        }



        // $articles_id_para_eliminarles_descuentos = [];

        foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {

            if (
                !isset($article_cache['discounts'])
                || empty($article_cache['discounts'])
            ) continue;

            $article_model = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

            if (!$article_model) continue;

            $article_id = $article_model->id;

            // $articles_id_para_eliminarles_descuentos[] = $article_id;

            $insertData = $this->get_discounts_surchages_insert_data('discounts', $article_id, $article_cache, $insertData, 'amount');

        }


        // DB::table('article_discounts')
        //     ->whereIn('article_id', $articles_id_para_eliminarles_descuentos)
        //     ->whereNotNull('amount')
        //     ->delete();

        // Log::info('Se eliminaron descuentos con amount de '.count($articles_id_para_eliminarles_descuentos).' articulos');

        if (!empty($insertData)) {
            DB::table('article_discounts')->insert($insertData);
        }
    }

    function asignar_surchages_percentages() {

        $insertData = [];

        foreach ($this->articulos_para_crear_CACHE as $article_cache) {

            if (
                !isset($article_cache['surchages'])
                || empty($article_cache['surchages'])
            ) continue;

            // Log::info('Descuentos del articulo para crear:');
            // Log::info($article_cache['surchages']);

            $article_model = $this->get_article_model_from_cache($article_cache);

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $insertData = $this->get_discounts_surchages_insert_data('surchages', $article_id, $article_cache, $insertData, '%');
            
        }



        $articles_id_para_eliminarles_descuentos = [];

        foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {

            if (
                !isset($article_cache['surchages'])
                || empty($article_cache['surchages'])
            ) continue;

            // Log::info('Descuentos del articulo para actualizar id '.$article_cache['id'].':');
            // Log::info($article_cache['surchages']);

            $article_model = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $articles_id_para_eliminarles_descuentos[] = $article_id;

            $insertData = $this->get_discounts_surchages_insert_data('surchages', $article_id, $article_cache, $insertData, '%');

        }


        DB::table('article_surchages')
            ->whereIn('article_id', $articles_id_para_eliminarles_descuentos)
            ->whereNotNull('percentage')
            ->delete();

        // Log::info('Se eliminaron recargos con percentage de '.count($articles_id_para_eliminarles_descuentos).' articulos');

        if (!empty($insertData)) {
            DB::table('article_surchages')->insert($insertData);
        }
    }

    function asignar_surchages_amounts() {

        $insertData = [];

        foreach ($this->articulos_para_crear_CACHE as $article_cache) {

            if (
                !isset($article_cache['surchages'])
                || empty($article_cache['surchages'])
            ) continue;

            $article_model = $this->get_article_model_from_cache($article_cache);

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $insertData = $this->get_discounts_surchages_insert_data('surchages', $article_id, $article_cache, $insertData, 'amount');
            
        }



        // $articles_id_para_eliminarles_descuentos = [];

        foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {

            if (
                !isset($article_cache['surchages'])
                || empty($article_cache['surchages'])
            ) continue;

            $article_model = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

            if (!$article_model) continue;

            $article_id = $article_model->id;

            // $articles_id_para_eliminarles_descuentos[] = $article_id;

            $insertData = $this->get_discounts_surchages_insert_data('surchages', $article_id, $article_cache, $insertData, 'amount');

        }


        // DB::table('article_surchages')
        //     ->whereIn('article_id', $articles_id_para_eliminarles_descuentos)
        //     ->whereNotNull('amount')
        //     ->delete();

        // Log::info('Se eliminaron recargos con amount de '.count($articles_id_para_eliminarles_descuentos).' articulos');

        if (!empty($insertData)) {
            DB::table('article_surchages')->insert($insertData);
        }
    }

    function get_discounts_surchages_insert_data($relation = 'discounts', $article_id, $article_cache, $insertData, $discount_type = '%') {

        $check_final_flag = $relation == 'surchages' ? true : false;

        foreach ($article_cache[$relation] as $discount) {

            if (

                $discount_type == '%'
                && $discount['type'] == '%'
                && is_array($discount['__diff__'.$relation.'_percent'])

            ) {

                foreach ($discount['__diff__'.$relation.'_percent']['new'] as $new_percentage) {
                    
                    // Log::info('Argegando recargo de '.$new_percentage.'%  para article id: '.$article_id);
                   
                    $insertData[] = $this->get_insert_data($article_id, $new_percentage, 'percentage', $check_final_flag);
                }

            } else if (

                $discount_type == 'amount'
                && $discount['type'] == 'amount'
                && is_array($discount['__diff__'.$relation.'_amount'])

            ) {

                foreach ($discount['__diff__'.$relation.'_amount']['new'] as $new_amount) {
                    
                    // Log::info('Argegando recargo de '.$new_amount.'  para article id: '.$article_id);
                    $insertData[] = $this->get_insert_data($article_id, $new_amount, 'amount', $check_final_flag);

                    // $insertData[] = [
                    //     'article_id' => $article_id,
                    //     'amount'     => $new_amount,
                    //     'created_at' => $this->now,
                    //     'updated_at' => $this->now,
                    // ];
                }

            }

        }

        return $insertData;
    }

    function get_insert_data($article_id, $value, $key = 'percentage', $check_final_flag) {

        // Log::info('get_insert_data:');
        // Log::info('check_final_flag: '.$check_final_flag);

        if ($check_final_flag) {

            // Log::info('value: ');
            // Log::info($value);

            // Log::info('is_array: '.is_array($value));

            $final_flag = $value['final'];
            $value = trim($value['value']);
        } else {

            $value = trim($value);
        }

        if ($value === '') {
            return;
        }


        $insertData = [
            'article_id' => $article_id,
            'percentage' => $key == 'percentage' ? (float)$value : null,
            'amount'     => $key == 'amount' ? (float)$value : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($check_final_flag) {
            $insertData['luego_del_precio_final'] = $final_flag ? 1 : 0;
        }

        return $insertData;
        
    }


    function actualizar_stock(bool $creados) {
        if ($creados) {

            foreach ($this->articulos_para_crear_CACHE as $article_cache) {

                if (
                    empty($article_cache['stock_global'])
                    && empty($article_cache['stock_addresses'])
                ) continue;

                $article_model = $this->get_article_model_from_cache($article_cache);

                if (!$article_model) continue;

                if (!empty($article_cache['stock_global'])) {
                    // Log::info('Act stock global de '.$article_model->name);
                    $this->guardar_stock_movement_global($article_model, $article_cache['stock_global']);
                } else {
                    // Log::info('Act stock por direcciones de '.$article_model->name);
                    $this->guardar_stock_movement_addresses($article_model, $article_cache['stock_addresses']);
                }

            }
        } else {

            foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {

                if (
                    empty($article_cache['stock_global'])
                    && empty($article_cache['stock_addresses'])
                ) continue;


                $article = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

                if (!$article) continue;

                if (!empty($article_cache['stock_global'])) {
                    // Log::info('Act stock global de '.$article->name);
                    $this->guardar_stock_movement_global($article, $article_cache['stock_global']);
                } else {
                    // Log::info('Act stock por direcciones de '.$article->name);
                    $this->guardar_stock_movement_addresses($article, $article_cache['stock_addresses']);
                }
                
            }
        }
    }

    function guardar_stock_movement_variant($article, $variant) {

        $data = [];

        $data['concepto_stock_movement_name'] = 'Importacion de excel';

        $data['model_id'] = $article->id;
        $data['amount'] = $variant->stock;
        $data['article_variant_id'] = $variant->id;

        // Log::info('Stock para variante '.$variant->variant_description.' de '.$variant->stock);

        $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);
    }

    function guardar_stock_movement_global($article, $amount) {

        $data = [];

        $data['concepto_stock_movement_name'] = 'Importacion de excel';

        $data['model_id'] = $article->id;
        $data['amount'] = $amount;

        $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);
    }

    function guardar_stock_movement_addresses($article, $addresses) {

        $data = [];

        $data['concepto_stock_movement_name'] = 'Importacion de excel';

        $data['model_id'] = $article->id;

        foreach ($addresses as $address) {

            if (!is_null($address['amount'])) {
                Log::info('Se van a agregar '.$address['amount'].' a address_id '.$address['address_id']);
                $data['to_address_id'] = $address['address_id'];
                $data['amount'] = $address['amount'];
                $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);
            }


            if (
                !is_null($address['stock_min'])
                || !is_null($address['stock_max'])
            ) {


                if ($article->addresses()->where('address_id', $address['address_id'])->exists()) {
                    // Log::info('actualizando pivot stock_min '.$address['stock_min']);
                    // Log::info('actualizando pivot stock_max '.$address['stock_max']);
                    $article->addresses()->updateExistingPivot($address['address_id'], [
                        'stock_min' => $address['stock_min'],
                        'stock_max' => $address['stock_max'],
                    ]);
                } else {
                    // Log::info('creando pivot stock_min '.$address['stock_min']);
                    // Log::info('creando pivot stock_max '.$address['stock_max']);
                    $article->addresses()->attach($address['address_id'], [
                        'stock_min' => $address['stock_min'],
                        'stock_max' => $address['stock_max'],
                    ]);
                }
            }
        }

    }

    function asignar_price_types() {
        // Preparar los datos
        $rows_create = [];
        $updates = [];

        if (app()->environment('local')) { Log::info('asignar_price_types:'); }

        // Recorrer todos los artículos
        // foreach ($articles_data as $article_data) {
        foreach ($this->articulos_para_crear_CACHE as $article_cache) {

            if (empty($article_cache['price_types_data'])) continue;

            $article_model = $this->get_article_model_from_cache($article_cache);

            if (!$article_model) continue;
            
            $article_id = $article_model->id;

            $price_types_data = $article_cache['price_types_data'];

            // if (app()->environment('local')) { Log::info('price_types_data de article num: '.$article_model->id); }
            // if (app()->environment('local')) { Log::info($price_types_data); }

            foreach ($price_types_data as $price_type) {

                $percentage = $this->get_price_type_percetange($price_type);

                $final_price = $this->get_price_type_final_price($price_type);

                $incluir = $this->get_incluir_en_excel_para_clientes($price_type);

                $setear_precio_final = $this->get_setear_precio_final($price_type);

                $percentage = ($percentage === '' || is_null($percentage)) ? 'NULL' : $percentage;
                $final_price = ($final_price === '' || is_null($final_price)) ? 'NULL' : $final_price;
                $incluir = $incluir ? 1 : 0;
                $setear_precio_final = $setear_precio_final ? 1 : 0;

                // Almacenamos los valores para construir el SQL
                $rows_create[] = "({$article_id}, {$price_type['id']}, {$percentage}, {$final_price}, {$incluir}, {$setear_precio_final})";

            }
        }

        if (!empty($rows_create)) {
            $values = implode(",\n", $rows_create);

            $sql = "
                INSERT IGNORE INTO article_price_type (
                    article_id, price_type_id, percentage, final_price, incluir_en_excel_para_clientes, setear_precio_final
                )
                VALUES
                {$values}
            ";

            
            if (app()->environment('local')) { Log::info('Consulta sql para crear relaciones price_types:'); }
            // if (app()->environment('local')) { Log::info($sql); }

            DB::statement($sql);
            if (app()->environment('local')) { Log::info('Se ejecuto consulta'); }
        }


        foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {

            if (empty($article_cache['price_types_data'])) continue;

            $article_model = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

            if (!$article_model) continue;

            $article_id = $article_model->id;

            $price_types_data = $article_cache['price_types_data'];

            foreach ($price_types_data as $price_type) {

                $percentage = $this->get_price_type_percetange($price_type);

                $final_price = $this->get_price_type_final_price($price_type);

                $incluir = $this->get_incluir_en_excel_para_clientes($price_type);

                $setear_precio_final = $this->get_setear_precio_final($price_type);

                $percentage = ($percentage === '' || is_null($percentage)) ? 'NULL' : $percentage;
                $final_price = ($final_price === '' || is_null($final_price)) ? 'NULL' : $final_price;
                $incluir = $incluir ? 1 : 0;
                $setear_precio_final = $setear_precio_final ? 1 : 0;

                // Almacenamos los valores para construir el SQL
                $updates[] = [
                    'article_id'    => $article_id,
                    'price_type_id' => $price_type['id'],
                    'percentage'    => $percentage,
                    'final_price'   => $final_price,
                    'incluir_en_excel_para_clientes' => $incluir,
                    'setear_precio_final' => $setear_precio_final,
                ];
            }
        }

        if (!empty($updates)) {

            Log::info('Se van a setear masivamente price_types de '.count($updates).' articulos');
            if (app()->environment('local')) { Log::info(''); }


            // Construir la consulta SQL

           $sql = "UPDATE article_price_type
                SET percentage = CASE
                    " . implode("\n", array_map(function($update) {
                        Log::info('percentage: '.$update['percentage']);
                        return "WHEN article_id = {$update['article_id']} AND price_type_id = {$update['price_type_id']} THEN " . 
                               ($update['percentage'] === 'NULL' ? "NULL" : floatval($update['percentage']));
                    }, $updates)) . "
                END,
                incluir_en_excel_para_clientes = CASE
                    " . implode("\n", array_map(function($update) {
                        return "WHEN article_id = {$update['article_id']} AND price_type_id = {$update['price_type_id']} THEN {$update['incluir_en_excel_para_clientes']}";
                    }, $updates)) . "
                END,
                final_price = CASE
                    " . implode("\n", array_map(function($update) {
                        return "WHEN article_id = {$update['article_id']} AND price_type_id = {$update['price_type_id']} THEN " . 
                               ($update['final_price'] === 'NULL' ? "NULL" : floatval($update['final_price']));
                    }, $updates)) . "
                END,
                setear_precio_final = CASE
                    " . implode("\n", array_map(function($update) {
                        return "WHEN article_id = {$update['article_id']} AND price_type_id = {$update['price_type_id']} THEN {$update['setear_precio_final']}";
                    }, $updates)) . "
                END
                WHERE (article_id, price_type_id) IN (" . implode(',', array_map(function($update) {
                    return "({$update['article_id']}, {$update['price_type_id']})";
                }, $updates)) . ")";


            // Ejecutar la consulta SQL
            // if (app()->environment('local')) { Log::info(''); }
            // if (app()->environment('local')) { Log::info('sql para setear price_types:'); }
            // if (app()->environment('local')) { Log::info($sql); }
            // if (app()->environment('local')) { Log::info(''); }
            DB::statement($sql);
            // if (app()->environment('local')) { Log::info(''); }
            // if (app()->environment('local')) { Log::info('Price_types actualizados'); }
            // if (app()->environment('local')) { Log::info(''); }
        }


    }


    function get_price_type_percetange($price_type) {

        $percentage = 'NULL';

        if (
            isset($price_type['pivot']['percentage'])
            && !is_null($price_type['pivot']['percentage'])
        ) {

            $percentage = $price_type['pivot']['percentage'];

        } else if (is_null($price_type['pivot']['final_price'])) {

            $price_type_model = $this->get_price_type($price_type['id']);

            $percentage = $price_type_model->percentage;
        }

        return $percentage;
    }


    function get_price_type_final_price($price_type) {

        $final_price = 'NULL';

        if (
            isset($price_type['pivot']['final_price'])
            && !is_null($price_type['pivot']['final_price'])
        ) {

            $final_price = $price_type['pivot']['final_price'];

        } 

        return $final_price;
    }


    function get_setear_precio_final($price_type) {

        // Log::info('get_setear_precio_final:');
        // Log::info($price_type);

        if ($price_type['pivot']['setear_precio_final']) {
            return $price_type['pivot']['setear_precio_final'];
        }

        $price_type_model = $this->get_price_type($price_type['id']);

        if ($price_type_model->setear_precio_final) {
            return 1;
        }
        return 0;
    }


    function get_incluir_en_excel_para_clientes($price_type) {

        $price_type_model = $this->get_price_type($price_type['id']);

        if ($price_type_model->incluir_en_lista_de_precios_de_excel) {
            return 1;
        }
        return 0;
    }

    function get_price_type($id) {
        return $this->price_types->firstWhere('id', $id);
    }

    function set_price_types() {
        $this->price_types = PriceType::where('user_id', $this->user->id)
                                        ->orderBy('position', 'ASC')
                                        ->get();
    }



    function set_precios_finales() {

        $inicio = microtime(true);

        $updates = [];

        foreach ($this->articulos_creados_models as $article) {

            $res = ArticleHelper::setFinalPrice($article, $this->user->id, $this->user, $this->auth_user_id, false, $this->price_types);

            $update = [
                'id'                        => $article->id,
                'costo_real'                => $res['costo_real'],
                'final_price'               => $res['final_price'],
                'current_final_price'       => $res['current_final_price'],
                'final_price_updated_at'    => $this->now,
            ];

            // Log::info('Se calculo precio de article id: '.$article->id);

            $updates[] = $update;
        }

        foreach ($this->articulos_actualizados_models as $article) {

            $res = ArticleHelper::setFinalPrice($article, $this->user->id, $this->user, $this->auth_user_id, false, $this->price_types);

            $update = [
                'id'                        => $article->id,
                'costo_real'                => $res['costo_real'],
                'final_price'               => $res['final_price'],
                'current_final_price'       => $res['current_final_price'],
                'final_price_updated_at'    => $this->now,
            ];

            $updates[] = $update;
            // Log::info('Se calculo precio de article id: '.$article->id);
        }

        Log::info('Se van a setear precios finales de '.count($updates).' articulos');
        if (app()->environment('local')) { Log::info(''); }

        $this->updateMasivo($updates);

        $fin = microtime(true);
        $dur = $fin - $inicio;
        $this->add_observation('Precios act en '.number_format($dur, 2, '.', '').' seg'); 

    }

    function updateMasivo(array $updates) {
        if (empty($updates)) return;

        $ids = array_column($updates, 'id');

        // Armamos cada CASE completo
        $finalPriceCases = "final_price = CASE id\n";
        foreach ($updates as $u) {
            $valor = is_null($u['final_price']) ? 'NULL' : $u['final_price'];
            $finalPriceCases .= "WHEN {$u['id']} THEN {$valor}\n";
           
        }
        $finalPriceCases .= "ELSE final_price END";


        $costo_real_cases = "costo_real = CASE id\n";
        foreach ($updates as $u) {
            $valor = is_null($u['costo_real']) ? 'NULL' : $u['costo_real'];
            $costo_real_cases .= "WHEN {$u['id']} THEN {$valor}\n";
           
        }
        $costo_real_cases .= "ELSE costo_real END";



        $previusFinalPriceCases = "previus_final_price = CASE id\n";
        foreach ($updates as $u) {
            $valor = is_null($u['current_final_price']) ? 'NULL' : $u['current_final_price'];
            $previusFinalPriceCases .= "WHEN {$u['id']} THEN $valor\n";
        }
        $previusFinalPriceCases .= "ELSE previus_final_price END";

        $finalPriceUpdatedAtCases = "final_price_updated_at = CASE id\n";
        foreach ($updates as $u) {
            $fecha = DB::getPdo()->quote($u['final_price_updated_at']); // evita errores de comillas
            $finalPriceUpdatedAtCases .= "WHEN {$u['id']} THEN $fecha\n";
        }
        $finalPriceUpdatedAtCases .= "ELSE final_price_updated_at END";

        // Unimos todo con comas entre asignaciones
        $sql = "
            UPDATE articles SET
                $finalPriceCases,
                $costo_real_cases,
                $previusFinalPriceCases,
                $finalPriceUpdatedAtCases
            WHERE id IN (" . implode(',', $ids) . ")
        ";

        Log::info('Lanzando consulta SQL para setear precios finales');
        DB::statement($sql);
        Log::info('Precios finales actualizados');
    }


    function get_article_model_from_cache($articulo_cache) {

        $article = $this->articulos_creados_models->first(function ($a) use ($articulo_cache) {
            return
                (!empty($articulo_cache['provider_code']) && $a->provider_code === $articulo_cache['provider_code']) ||
                (!empty($articulo_cache['bar_code']) && $a->bar_code === $articulo_cache['bar_code']) ||
                (!empty($articulo_cache['name']) && $a->name === $articulo_cache['name']);
        });

        return $article;
    }

    function get_articles_models_from_cache($articulos_cache) {
        $byProviderCode = [];
        $byBarCode = [];
        $byName = [];

        foreach ($articulos_cache as $art) {
            if (!empty($art['provider_code'])) {
                $byProviderCode[] = $art['provider_code'];
            } elseif (!empty($art['bar_code'])) {
                $byBarCode[] = $art['bar_code'];
            } elseif (!empty($art['name'])) {
                $byName[] = $art['name'];
            }
        }

        $articles = Article::whereIn('provider_code', $byProviderCode)
                            ->orWhereIn('bar_code', $byBarCode)
                            ->orWhereIn('name', $byName)
                            ->get();

        return $articles;
    }

    function get_articulos_creados_models() {
        return $this->articulos_creados_models;
    }

    function set_articulos_creados_models() {

        $inicio = microtime(true);
        
        $byProviderCode = [];
        $byBarCode = [];
        $byName = [];

        foreach ($this->articulos_para_crear_CACHE as $art) {
            if (!empty($art['provider_code'])) {
                $byProviderCode[] = $art['provider_code'];
            } elseif (!empty($art['bar_code'])) {
                $byBarCode[] = $art['bar_code'];
            } elseif (!empty($art['name'])) {
                $byName[] = $art['name'];
            }
        }

        $articles = Article::whereIn('provider_code', $byProviderCode)
                            ->orWhereIn('bar_code', $byBarCode)
                            ->orWhereIn('name', $byName)
                            ->get();

        $this->articulos_creados_models = $articles;

        $fin = microtime(true);
        $dur = $fin - $inicio;

        $this->add_observation('Set articulos_creados en '.number_format($dur, 2, '.', '').' seg'); 
        Log::info('se seteo articulos_creados_models con '.count($this->articulos_creados_models).' articulos');
    }

    function set_articulos_actualizados_models() {

        $inicio = microtime(true);

        $ids = array_column($this->articulos_para_actualizar_CACHE, 'id');

        $this->articulos_actualizados_models = Article::whereIn('id', $ids)->get()->keyBy('id');

        Log::info('Se seteo articulos_actualizados_models con '.count($this->articulos_actualizados_models).' articulos');

        $fin = microtime(true);

        $dur = $fin - $inicio;

        $this->add_observation('Set articulos_actualizados en '.number_format($dur, 2, '.', '').' seg'); 

        // Log::info('$ids:');
        // Log::info($ids);
    }




    // Variantes:



    protected function guardar_variantes_desde_cache_simple(): void
    {
        $inicio = microtime(true);
        // 1) Artículos a CREAR
        if (isset($this->articulos_para_crear_CACHE) && is_array($this->articulos_para_crear_CACHE)) {
            foreach ($this->articulos_para_crear_CACHE as $art_cache) {
                $this->persistir_variantes_de_articulo_cache($art_cache);
            }
        }

        // 2) Artículos a ACTUALIZAR
        if (isset($this->articulos_para_actualizar_CACHE) && is_array($this->articulos_para_actualizar_CACHE)) {
            foreach ($this->articulos_para_actualizar_CACHE as $art_cache) {
                $this->persistir_variantes_de_articulo_cache($art_cache);
            }
        }
        $fin = microtime(true);
        $dur = $fin - $inicio;
        $this->add_observation('variantes en '.number_format($dur, 2, '.', '').' seg');
    }

    /**
     * Toma un artículo desde el cache (con VariantsData/variants_data) y
     * crea/actualiza sus ArticleVariant + pivots de valores.
     */
    protected function persistir_variantes_de_articulo_cache(array $art_cache): void
    {
        $article = $this->encontrar_articulo_model_desde_cache($art_cache);
        if (!$article) {
            \Log::warning('No se encontró Article para variantes', ['art_cache' => $art_cache]);
            return;
        }


        $variants = $art_cache['variants_data'] ?? [];
        if (empty($variants)) {
            return;
        }



        // Mapa acumulado de propiedades del artículo -> valores usados en todas las variantes
        // [ type_id => ['type' => ArticlePropertyType, 'prop_type_value_ids' => [1,2,3]] ]
        $article_property_map = [];



        foreach ($variants as $variant_payload) {

            /* 
                Esperamos estructura:
                    $variant_payload['properties'] = [
                        'color' => 'Negro', 
                        'talle' => '42'
                    ]
            */

            $array_property_types = $variant_payload['properties'];


            if (empty($array_property_types)) {
                // si el payload no trae 'properties' pero los valores vinieron planos,
                // props ya los rescatamos en extraer_props_de_payload()
                \Log::warning('Variant sin properties detectadas, se omite', ['variant_payload' => $variant_payload]);
                continue;
            }

            // 1) Resolver/crear tipos y valores por nombre (simple)
            $type_value_ids = [];     // ids de ArticlePropertyValue
            $pares_para_descripcion = [];     // para armar "Negro 42" etc. preservamos orden de array_property_types

            foreach ($array_property_types as $type_name => $prop_type_value_name) {

                $prop_type_name     = mb_strtolower(trim((string)$type_name));
                $prop_type_value_name    = trim((string)$prop_type_value_name);

                if ($prop_type_name === '' || $prop_type_value_name === '') continue;

                $property_type = ArticlePropertyType::firstOrCreate(
                    ['name' => $prop_type_name],
                    ['name' => $prop_type_name]
                );

                $prop_type_value = ArticlePropertyValue::firstOrCreate(
                    ['article_property_type_id' => $property_type->id, 'name' => $prop_type_value_name],
                    ['article_property_type_id' => $property_type->id, 'name' => $prop_type_value_name]
                );

                $type_value_ids[] = $prop_type_value->id;
                $pares_para_descripcion[] = $prop_type_value->name; // usamos el label tal cual

                if (!isset($article_property_map[$property_type->id])) {
                    $article_property_map[$property_type->id] = [
                        'property_type' => $property_type,
                        'prop_type_value_ids' => [],
                    ];
                }
                $article_property_map[$property_type->id]['prop_type_value_ids'][] = $prop_type_value->id;
            }

            if (empty($type_value_ids)) {
                continue;
            }

            sort($type_value_ids);



            // Cheque si la variante ya fue creada
            $variant = ArticleVariant::where('article_id', $article->id);

            foreach ($type_value_ids as $type_value_id) {

               $variant->whereHas('article_property_values', function ($q) use ($type_value_id) {
                        $q->where('article_property_value_id', $type_value_id);
                    });

            }
            $variant = $variant->first();
                                    

            // Si no existe aun, la creo
            if (!$variant) {

                $variant = new ArticleVariant();
                $variant->article_id = $article->id;


                // 4) Descripción de la variante (ej: "Negro 42")
                $variant_description = implode(' ', $pares_para_descripcion);
                $variant->variant_description = $variant_description;

                $variant->save();

                $variant->article_property_values()->sync($type_value_ids);
                
            } else {
                Log::info('Ya esta creada la variante '.$variant->variant_description);
            }


            if (
                empty($variant_payload['address_stocks'])
                && !is_null($variant_payload['stock'])
            ) {
                $variant->stock = $variant_payload['stock'];
            }

            if (!is_null($variant->stock)) {

                $this->guardar_stock_movement_variant($article, $variant);
            }


            // 👇 Asignar stocks por dirección para esta variante
            if (!empty($variant_payload['address_stocks']) && is_array($variant_payload['address_stocks'])) {
                // $this->sync_variant_address_stocks($variant, $variant_payload['address_stocks']);
                $this->sync_variant_address_pivot($variant, $variant_payload);
            }
        }

        // Crear/actualizar ArticleProperties y sus valores en base al mapa
        $this->persistir_article_properties_desde_mapa($article, $article_property_map);
    }

    protected function persistir_article_properties_desde_mapa(Article $article, array $article_property_map): void
    {
        if (empty($article_property_map)) return;

        foreach ($article_property_map as $type_id => $data) {

            /** @var \App\Models\ArticlePropertyType $property_type */
            $property_type = $data['property_type'];
            $prop_type_value_ids = array_values(array_unique(array_map('intval', $data['prop_type_value_ids'])));

            // 1) upsert ArticleProperty (article_id + type_id)
            $article_property = ArticleProperty::firstOrCreate(
                [
                    'article_id' => $article->id,
                    'article_property_type_id' => $property_type->id,
                ],
                [
                    'article_id' => $article->id,
                    'article_property_type_id' => $property_type->id,
                ]
            );

            // 2) vincular valores (belongsToMany)
            // Agregamos/actualizamos sin quitar los ya existentes
            $pairs = [];
            foreach ($prop_type_value_ids as $vid) {
                $pairs[$vid] = []; // si tu pivot no tiene columnas extra
            }
            if (!empty($pairs)) {
                $article_property->article_property_values()->syncWithoutDetaching($pairs);
            }
        }
    }



    protected function sync_variant_address_pivot(ArticleVariant $variant, array $variant_payload): void
    {



        foreach ($variant_payload['address_stocks'] as $address_id => $stock) {
            $variant->addresses()->syncWithoutDetaching([
                $address_id => [
                    'amount'    => $stock,
                ]
            ]);
            // Log::info('Se pusieron '.$stock.' en address_id: '.$address_id.' para variante '.$variant->variant_description);
        }


        foreach ($variant_payload['address_display'] as $address_id => $on_display) {

            if ($on_display) {

                $variant->addresses()->syncWithoutDetaching([ 
                    $address_id => [
                        'on_display'    => 1,
                    ]
                ]);
                // Log::info('Se pusieron en exhibicion la variante '.$variant->variant_description.' en address_id: '.$address_id);
            }

        }


        // $stocks  = (isset($variant_payload['address_stocks'])  && is_array($variant_payload['address_stocks']))  ? $variant_payload['address_stocks']  : [];
        // $display = (isset($variant_payload['address_display']) && is_array($variant_payload['address_display'])) ? $variant_payload['address_display'] : [];

        // // Construimos pares [address_id => ['amount' => X, 'on_display' => Y]]
        // $pairs = [];

        // // unir por claves; si no hay stock, amount queda null; si no hay display, on_display false
        // $address_ids = array_unique(array_merge(array_keys($stocks), array_keys($display)));

        // foreach ($address_ids as $addr_id) {
        //     if (!is_numeric($addr_id)) continue;
        //     $addr_id = (int)$addr_id;

        //     $amount = isset($stocks[$addr_id]) ? (int)$stocks[$addr_id] : null;
        //     $on_display = isset($display[$addr_id]) ? (bool)$display[$addr_id] : false;

        //     $row = [];
        //     if (!is_null($amount))   $row['amount']     = $amount;
        //     $row['on_display'] = $on_display;

        //     $pairs[$addr_id] = $row;
        // }

        // if (empty($pairs)) return;

        // if (method_exists($variant, 'addresses')) {
        //     // merge sin quitar otros pivots
        //     $variant->addresses()->syncWithoutDetaching($pairs);

        //     // Si querés que un false de on_display actualice lo existente, hacemos updates explícitos:
        //     foreach ($pairs as $aId => $pivot) {
        //         $variant->addresses()->updateExistingPivot($aId, $pivot, false);
        //     }
        //     return;
        // }

        // // Fallback sin relación definida
        // $pivotTable = $this->guess_variant_address_pivot_table();

        // foreach ($pairs as $aId => $pivot) {
        //     $exists = \DB::table($pivotTable)
        //         ->where('article_variant_id', $variant->id)
        //         ->where('address_id', $aId)
        //         ->exists();

        //     if ($exists) {
        //         \DB::table($pivotTable)
        //             ->where('article_variant_id', $variant->id)
        //             ->where('address_id', $aId)
        //             ->update($pivot);
        //     } else {
        //         \DB::table($pivotTable)->insert(array_merge([
        //             'article_variant_id' => $variant->id,
        //             'address_id' => $aId,
        //         ], $pivot));
        //     }
        // }
    }


    /**
     * Sincroniza el stock por dirección para una variante.
     * $address_stocks = ['3' => 10, 'central' => 5, 'deposito_centro' => 2]
     */
    // protected function sync_variant_address_stocks(ArticleVariant $variant, array $address_stocks): void
    // {

    //     foreach ($address_stocks as $address_id => $stock) {
    //         $variant->addresses()->attach($address_id, [
    //             'amount'    => $stock,
    //         ]);
    //         Log::info('Se pusieron '.$stock.' en address_id: '.$address_id.' para variante '.$variant->variant_description);
    //     }
    // }

    /**
     * Resuelve un address_id a partir de una clave:
     * - numérico → id
     * - string → busca por code (si existe la columna) y luego por name (case-insensitive)
     */
    protected function resolve_address_id($addr_key): ?int
    {
        if ($addr_key === null || $addr_key === '') return null;

        // numérico => id directo
        if (is_numeric($addr_key)) {
            $id = (int)$addr_key;
            if (Address::where('id', $id)->exists()) return $id;
        }

        // buscar por code si existe la columna
        $address = null;
        $table = (new Address)->getTable();
        $hasCode = \Schema::hasColumn($table, 'code');

        if ($hasCode) {
            $address = Address::whereRaw('LOWER(code) = ?', [mb_strtolower((string)$addr_key)])->first();
            if ($address) return (int)$address->id;
        }

        // buscar por name
        $address = Address::whereRaw('LOWER(name) = ?', [mb_strtolower((string)$addr_key)])->first();
        if ($address) return (int)$address->id;

        // intento por nombre normalizado (snake, sin tildes)
        $norm = $this->normalize_key((string)$addr_key);
        $address = Address::whereRaw('LOWER(name) = ?', [$norm])->first();
        if ($address) return (int)$address->id;

        \Log::warning('No se encontró Address con clave', ['addr_key' => $addr_key]);
        return null;
    }

    protected function normalize_key(string $key): string
    {
        $key = \Illuminate\Support\Str::of($key)->lower()->snake()->value();
        $key = iconv('UTF-8', 'ASCII//TRANSLIT', $key);
        return preg_replace('/[^a-z0-9_]/', '', $key);
    }

    /**
     * Intenta adivinar el nombre de la tabla pivote variant-address.
     * Ajustalo si tu proyecto usa otro.
     */
    protected function guess_variant_address_pivot_table(): string
    {
        // Prioridad por nombres típicos
        $candidates = [
            'address_article_variant',
            'article_variant_address',
            'address_article_variants',
            'article_variant_addresses',
        ];
        foreach ($candidates as $t) {
            if (\Schema::hasTable($t)) return $t;
        }
        // fallback
        return 'address_article_variant';
    }


    /**
     * Encuentra el Article (ya persistido) a partir de los datos del cache
     * - Primero intenta por id si viene.
     * - Luego por bar_code, provider_code, name (ajustá estos nombres si en tu cache se llaman distinto).
     */
    protected function encontrar_articulo_model_desde_cache(array $art_cache): ?Article
    {
        if (!empty($art_cache['id'])) {
            $m = Article::find($art_cache['id']);
            if ($m) return $m;
        }

        $q = Article::query();

        if (!empty($art_cache['bar_code'])) {
            $m = (clone $q)->where('bar_code', $art_cache['bar_code'])->first();
            if ($m) return $m;
        }
        if (!empty($art_cache['provider_code'])) {
            $m = Article::where('provider_code', $art_cache['provider_code'])->first();
            if ($m) return $m;
        }
        if (!empty($art_cache['name'])) {
            $m = Article::where('name', $art_cache['name'])->first();
            if ($m) return $m;
        }

        return null;
    }

    /**
     * Extrae el array de properties desde el payload de la variante.
     * Soporta dos formatos:
     *   A) ['properties' => ['color'=>'Negro','talle'=>'42']]
     *   B) ['color'=>'Negro','talle'=>'42', 'price'=>..., 'stock'=>...]  (sin 'properties')
     */
    protected function extraer_props_de_payload(array $variant_payload): array
    {
        if (isset($variant_payload['properties']) && is_array($variant_payload['properties'])) {
            return $variant_payload['properties'];
        }
    }


    function actualizar_cache() {

        Log::info('');
        Log::info('');
        Log::info('actualizar_cache');
        Log::info('');

        $index = ArticleIndexCache::get($this->user->id);
        // Log::info('El cache esta asi:');
        // Log::info(count($index['ids']).' ids');
        // Log::info(count($index['bar_codes']).' bar_codes');
        // Log::info(count($index['skus']).' skus');
        // Log::info(count($index['provider_codes']).' provider_codes');
        // Log::info(count($index['names']).' names');
        Log::info('');

        foreach ($this->articulos_creados_models as $article) {
            Log::info('Entro con '.$article->id);
            ArticleIndexCache::update($article);
        }

        foreach ($this->articulos_actualizados_models as $article) {
            ArticleIndexCache::update($article);
        }
        Log::info('');
        Log::info('');
        Log::info('');
    }

    
}