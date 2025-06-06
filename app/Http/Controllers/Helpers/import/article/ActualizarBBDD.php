<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ActualizarBBDD {

    function __construct($articulos_para_crear_CACHE, $articulos_para_actualizar_CACHE, $user, $auth_user_id) {
        
        Log::info('');
        Log::info('********* ActualizarBBDD ************');
        Log::info('');

        $this->user                                 = $user;
        $this->auth_user_id                         = $auth_user_id;

        $this->articulos_para_crear_CACHE           = $articulos_para_crear_CACHE;
        $this->articulos_para_actualizar_CACHE      = $articulos_para_actualizar_CACHE;

        $this->articulos_creados_models = [];
        $this->articulos_actualizados_models = [];

        $this->stock_movement_ct = new StockMovementController();

        $this->now = Carbon::now()->toDateTimeString();


        $this->set_price_types();

        $this->guardar_articulos();
    }

    function guardar_articulos() {

        // Crear los artículos nuevos en la bbdd
        if (!empty($this->articulos_para_crear_CACHE)) {
            
            Log::info('Se van a crear ' . count($this->articulos_para_crear_CACHE) . ' articulos');
            // if (app()->environment('local')) { Log::info($this->articulos_para_crear_CACHE); }
            
            Article::insert(array_map(function ($art) {
                return collect($art)->except([
                    'price_types_data',
                    'discounts_data',
                    'stock_global',
                    'stock_addresses',
                ])->merge([
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ])->toArray();
            }, $this->articulos_para_crear_CACHE));

            $this->set_articulos_creados_models();
        }

        // Actualizar artículos existentes por lote con SQL crudo
        if (!empty($this->articulos_para_actualizar_CACHE)) {

            $this->set_articulos_actualizados_models();

            
            if (count($this->articulos_actualizados_models) > 0) {
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
                            || $column === 'discounts_data'
                            || $column === 'stock_global'
                            || $column === 'stock_addresses'
                        ) continue;

                        if (
                            !is_null($value)
                            && $value != ''
                        ) {
                            
                            $quotedValue = DB::getPdo()->quote($value);

                            if (!isset($casesByColumn[$column])) {
                                $casesByColumn[$column] = "`$column` = CASE `id`";
                            }

                            $casesByColumn[$column] .= " WHEN $id THEN $quotedValue";
                        }

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
        $this->asignar_discounts();
        Log::info('Se asignaron discounts');

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
    }

    function asignar_discounts() {

        $insertData = [];

        foreach ($this->articulos_para_crear_CACHE as $article_cache) {


            if (empty($article_cache['discounts_data'])) continue;

            $article_model = $this->get_article_model_from_cache($article_cache);

            if (!$article_model) continue;

            $article_id = $article_model->id;

            foreach ($article_cache['discounts_data'] as $discount) {
                if (
                    $discount->percentage !== ''
                    && $discount->percentage !== 0
                    && $discount->percentage !== '0'
                ) {
                    Log::info('Argegando descuento de '.$discount->percentage.' para article id: '.$article_id);
                    $insertData[] = [
                        'article_id' => $article_id,
                        'percentage' => $discount->percentage,
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ];
                }
            }
        }

        foreach ($this->articulos_para_actualizar_CACHE as $article_cache) {


            if (empty($article_cache['discounts_data'])) continue;

            $article_model = $this->articulos_actualizados_models[$article_cache['id']] ?? null;

            if (!$article_model) continue;

            $article_id = $article_model->id;

            foreach ($article_cache['discounts_data'] as $discount) {
                if (
                    $discount->percentage !== ''
                    && $discount->percentage !== 0
                    && $discount->percentage !== '0'
                ) {
                    Log::info('Argegando descuento de '.$discount->percentage.' para article id: '.$article_id);
                    $insertData[] = [
                        'article_id' => $article_id,
                        'percentage' => $discount->percentage,
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ];
                }
            }
        }

        $article_ids = collect($this->articulos_para_actualizar_CACHE)->pluck('id');

        DB::table('article_discounts')
            ->whereIn('article_id', $article_ids)
            ->delete();

        Log::info('Se eliminaron descuentos de '.count($article_ids).' articulos');

        if (!empty($insertData)) {
            DB::table('article_discounts')->insert($insertData);
        }
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
                    Log::info('Act stock global de '.$article_model->name);
                    $this->guardar_stock_movement_global($article_model, $article_cache['stock_global']);
                } else {
                    Log::info('Act stock por direcciones de '.$article_model->name);
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
                    Log::info('Act stock global de '.$article->name);
                    $this->guardar_stock_movement_global($article, $article_cache['stock_global']);
                } else {
                    Log::info('Act stock por direcciones de '.$article->name);
                    $this->guardar_stock_movement_addresses($article, $article_cache['stock_addresses']);
                }
                
            }
        }
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
            $data['to_address_id'] = $address['address_id'];
            $data['amount'] = $address['amount'];
            $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);
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

                // if (app()->environment('local')) { Log::info('percentage: '); }
                // if (app()->environment('local')) { Log::info($percentage); }
                $incluir = $this->get_incluir_en_excel_para_clientes($price_type);

                // Almacenamos los valores para construir el SQL
                $rows_create[] = "({$article_id}, {$price_type['id']}, {$percentage}, {$incluir})";

            }
        }

        if (!empty($rows_create)) {
            $values = implode(",\n", $rows_create);

            $sql = "
                INSERT IGNORE INTO article_price_type (
                    article_id, price_type_id, percentage, incluir_en_excel_para_clientes
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

                $incluir_en_excel_para_clientes = $this->get_incluir_en_excel_para_clientes($price_type);

                // Almacenamos los valores para construir el SQL
                $updates[] = [
                    'article_id'    => $article_id,
                    'price_type_id' => $price_type['id'],
                    'percentage'    => $percentage,
                    'incluir_en_excel_para_clientes' => $incluir_en_excel_para_clientes,
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
                            return "WHEN article_id = {$update['article_id']} AND price_type_id = {$update['price_type_id']} THEN {$update['percentage']}";
                        }, $updates)) . "
                    END,
                    incluir_en_excel_para_clientes = CASE
                        " . implode("\n", array_map(function($update) {
                            return "WHEN article_id = {$update['article_id']} AND price_type_id = {$update['price_type_id']} THEN {$update['incluir_en_excel_para_clientes']}";
                        }, $updates)) . "
                    END
                    WHERE (article_id, price_type_id) IN (" . implode(',', array_map(function($update) {
                        return "({$update['article_id']}, {$update['price_type_id']})";
                    }, $updates)) . ")";

            // Ejecutar la consulta SQL
            if (app()->environment('local')) { Log::info(''); }
            if (app()->environment('local')) { Log::info('sql para setear price_types:'); }
            if (app()->environment('local')) { Log::info($sql); }
            if (app()->environment('local')) { Log::info(''); }
            DB::statement($sql);
            if (app()->environment('local')) { Log::info(''); }
            if (app()->environment('local')) { Log::info('Price_types actualizados'); }
            if (app()->environment('local')) { Log::info(''); }
        }


    }


    function get_price_type_percetange($price_type) {

        $percentage = null;

        if (
            isset($price_type['pivot']['percentage'])
            && !is_null($price_type['pivot']['percentage'])
        ) {

            $percentage = $price_type['pivot']['percentage'];

        } else {

            $price_type_model = $this->get_price_type($price_type['id']);

            $percentage = $price_type_model->percentage;
        }

        return $percentage;
    }


    function get_incluir_en_excel_para_clientes($price_type) {

        $price_type_model = $this->get_price_type($price_type['id']);

        return $price_type_model->incluir_en_lista_de_precios_de_excel;
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


        $updates = [];

        foreach ($this->articulos_creados_models as $article) {

            $res = ArticleHelper::setFinalPrice($article, $this->user->id, $this->user, $this->auth_user_id, false, $this->price_types);

            $update = [
                'id'                        => $article->id,
                'final_price'               => $res['final_price'],
                'current_final_price'       => $res['current_final_price'],
                'final_price_updated_at'    => $this->now,
            ];

            Log::info('Se calculo precio de article id: '.$article->id);

            $updates[] = $update;
        }

        foreach ($this->articulos_actualizados_models as $article) {

            $res = ArticleHelper::setFinalPrice($article, $this->user->id, $this->user, $this->auth_user_id, false, $this->price_types);

            $update = [
                'id'                        => $article->id,
                'final_price'               => $res['final_price'],
                'current_final_price'       => $res['current_final_price'],
                // 'current_final_price'       => !is_null($res['current_final_price']) ? $res['current_final_price'] : 0,
                'final_price_updated_at'    => $this->now,
            ];

            $updates[] = $update;
            Log::info('Se calculo precio de article id: '.$article->id);
        }

        Log::info('Se van a setear precios finales de '.count($updates).' articulos');
        if (app()->environment('local')) { Log::info(''); }

        $this->updateMasivo($updates);

    }

    function updateMasivo(array $updates) {
        if (empty($updates)) return;

        $ids = array_column($updates, 'id');

        // Armamos cada CASE completo
        $finalPriceCases = "final_price = CASE id\n";
        foreach ($updates as $u) {
            $valor = is_null($u['final_price']) ? 'NULL' : $u['final_price'];
            $finalPriceCases .= "WHEN {$u['id']} THEN {$valor}\n";
            // if (
            //     isset($u['final_price'])
            //     && !is_null($u['final_price'])
            //     && $u['final_price'] != ''
            // ) {
            //     $finalPriceCases .= "WHEN {$u['id']} THEN {$u['final_price']}\n";
            // }
        }
        $finalPriceCases .= "ELSE final_price END";

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
                $previusFinalPriceCases,
                $finalPriceUpdatedAtCases
            WHERE id IN (" . implode(',', $ids) . ")
        ";

        if (app()->environment('local')) { Log::info('Lanzando consulta SQL para setear precios finales'); }
        DB::statement($sql);
        if (app()->environment('local')) { Log::info('Precios finales actualizados'); }
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

    function set_articulos_creados_models() {

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

        Log::info('se seteo articulos_creados_models con '.count($this->articulos_creados_models).' articulos');
    }

    function set_articulos_actualizados_models() {

        $ids = array_column($this->articulos_para_actualizar_CACHE, 'id');

        $this->articulos_actualizados_models = Article::whereIn('id', $ids)->get()->keyBy('id');

        Log::info('Se seteo articulos_actualizados_models con '.count($this->articulos_actualizados_models).' articulos');
    }
    
}