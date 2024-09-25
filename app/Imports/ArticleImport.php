<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\ArticlesPreImportHelper;
use App\Http\Controllers\Helpers\IvaHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\getIva;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\update;
use App\Models\Address;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\PriceType;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
// use Maatwebsite\Excel\Concerns\WithChunkReading;

class ArticleImport implements ToCollection
{

    
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user, $auth_user_id, $archivo_excel_path) {
        set_time_limit(9999999999);

        Log::info('Se creo ArticleImport');

        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->archivo_excel_path = $archivo_excel_path;

        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->provider_id = $provider_id;
        $this->import_history_id = $import_history_id;
        $this->pre_import_id = $pre_import_id;
        $this->provider = null;
        $this->created_models = 0;
        $this->updated_models = 0;
        $this->setAddresses();
        $this->setProps();
        $this->set_price_types();

        $this->import_history_chequeado = false;

        if (UserHelper::hasExtencion('articles_pre_import', $this->user)) {
            $this->articles_pre_import_helper = new ArticlesPreImportHelper($this->provider_id, $this->pre_import_id, $this->user);
        }

        $this->trabajo_terminado = false;
    }


    function setProps() {

        /* 
            Estas propiedades son las que se setean automaticamente
            con los valores que llegan del excel
            
            Siempre y cuando no esten ignoradas
        */
        $this->props_to_set = [
            'name'              => 'nombre',
            'bar_code'          => 'codigo_de_barras',
            'provider_code'     => 'codigo_de_proveedor',
            'stock_min'         => 'stock_minimo',
            'cost'              => 'costo',
            'percentage_gain'   => 'margen_de_ganancia',
            'price'             => 'precio',
        ];

        if (UserHelper::hasExtencion('articulos_precios_en_blanco', $this->user)) {
            $this->props_to_set['percentage_gain_blanco'] = 'margen_de_ganancia_en_blanco';
        }
    }

    function setAddresses() {
        $this->addresses = Address::where('user_id', $this->user->id)
                                    ->get();
        $this->stock_movement_ct = new StockMovementController();
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns)) 
            || !is_null(ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns)) 
            || !is_null(ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns)) 
            || !is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;

        $this->set_finish_row($rows);

        foreach ($rows as $row) {

            if ($this->esta_en_el_rango_de_filas()) {

                if ($this->checkRow($row)) {

                    $this->articulo_existente = ArticleImportHelper::get_articulo_encontrado($this->user, $row, $this->columns);

                    $this->saveArticle($row);

                } else {
                    Log::info('Se omitio una fila N° '.$this->num_row.' con nombre '.ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                } 

            } else if ($this->num_row > $this->finish_row) {

                break;
            }
            $this->num_row++;
        }

        if (!$this->trabajo_terminado) {

            ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->created_models, $this->updated_models, $this->columns, $this->archivo_excel_path);

            ArticleImportHelper::enviar_notificacion($this->user);
            
            $this->trabajo_terminado = true;
        }

    }

    function set_finish_row($rows) {
        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 
    }

    function esta_en_el_rango_de_filas() {
        return $this->check_fila_inicio() && $this->check_fila_fin();
    }

    function check_fila_inicio() {
        return $this->num_row >= $this->start_row;
    }

    function check_fila_fin() {
        return $this->num_row <= $this->finish_row;
    }

    function saveArticle($row) {

        Log::info('saveArticle para row N° '.$this->num_row);
        
        $data = [];

        $this->save_stock_movement = false;

        $this->row = $row;
        
        foreach ($this->props_to_set as $key => $value) {
            if (!ImportHelper::isIgnoredColumn($value, $this->columns)) {
                $data[$key] = ImportHelper::getColumnValue($row, $value, $this->columns);
            }
        }

        $data = ArticleImportHelper::get_unidad_medida($data, $this->columns, $row);

        ArticleImportHelper::guardar_proveedor($this->columns, $row, $this->ct, $this->user);

        $data = ArticleImportHelper::get_iva_id($data, $this->columns, $row, $this->articulo_existente);


        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['category_id'] = LocalImportHelper::getCategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), $this->ct, $this->user);
        }

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['sub_category_id'] = LocalImportHelper::getSubcategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns), $this->ct, $this->user);
        }

        $article = null;
        
        if (!is_null($this->articulo_existente) && $this->isDataUpdated($row, $data)) {
            
            Log::info('Hubo cambios en fila '.$this->num_row);
            
            $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), $this->articulo_existente->id, $this->user->id);

            if (UserHelper::hasExtencion('articles_pre_import', $this->user)) {
                
                Log::info('Se agrego a pre_import la N° '.$this->num_row);

                $this->articles_pre_import_helper->add_article($this->articulo_existente, $data);

            } else {

                $article = Article::find($this->articulo_existente->id);

                $article->update($data);

                Log::info('Se actualizo '.$this->articulo_existente->name);

                $this->articulo_existente = $article;

                $this->updated_models++;
            }

        } else if (is_null($this->articulo_existente) && $this->create_and_edit) {

           
            if (!is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns))) {
                $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), null, $this->user->id);
            }
            $data['user_id'] = $this->user->id;
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $data['apply_provider_percentage_gain'] = 1;

            $data['num'] = $this->ct->num('articles', null, 'user_id', $this->user->id);


            $article = Article::create($data);

            $this->articulo_existente = $article;

            Log::info('Se creo');

            $this->created_models++;

        } else {
            Log::info('No entro a ningun lado la N° '.$this->num_row);
        }

        if (!is_null($this->articulo_existente)) {
            
            Log::info('$article no es null:');

            $this->aplicar_price_types($row);

            $this->aplicar_descuentos($row);
            $this->aplicar_recargos($row);

            $this->set_propiedades_de_distribuidora();

            $this->setProvider($row);

            $this->setStockAddresses($row);
            $this->setStockMovement($row);

            ArticleHelper::setFinalPrice($this->articulo_existente, null, $this->user, $this->auth_user_id);
        }
    }

    function set_propiedades_de_distribuidora() {

        Log::info('set_propiedades_de_distribuidora');

        if (UserHelper::hasExtencion('articulos_con_propiedades_de_distribuidora', $this->user)) {

            Log::info('SI ENTRO en set_propiedades_de_distribuidora');

            ArticleImportHelper::set_tipo_de_envase(
                        $this->articulo_existente, 
                        $this->columns,
                        $this->row,
                        $this->ct,
                        $this->user,
                    );

            ArticleImportHelper::set_contenido(
                        $this->articulo_existente, 
                        $this->columns,
                        $this->row,
                    );

            ArticleImportHelper::set_unidades_por_bulto(
                        $this->articulo_existente, 
                        $this->columns,
                        $this->row,
                    );
        } else {
            Log::info('NO ENTRO en set_propiedades_de_distribuidora');
        }
    }

    function aplicar_price_types($row) {

        if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios', $this->user)) {

            $price_types = [];

            foreach ($this->price_types as $price_type) {

                $row_name = '% '.$price_type->name;

                $percentage = ImportHelper::getColumnValue($row, $row_name, $this->columns);

                Log::info('ArticleImport percentage row :');
                Log::info($percentage);

                $price_types[] = [
                    'id'            => $price_type->id,
                    'pivot'         => [
                        'percentage'    => $percentage,
                    ]
                ];

            }
            ArticlePriceTypeHelper::attach_price_types($this->articulo_existente, $price_types);
        }
    }

    function set_price_types() {
        $this->price_types = PriceType::where('user_id', $this->user->id)
                                        ->orderBy('position', 'ASC')
                                        ->get();
    }

    function sobre_escribir() {
        return env('SOBRE_ESCRIBIR_ARTICULOS_AL_IMPORTAR', false);
    }

    function isDataUpdated($row, $data) {
        $epsilon = 0.00001;

        $new_price = null;
        if (isset($data['price'])) {
            $new_price = (float)$data['price'];
        }

        $actual_price = (float)$this->articulo_existente->price;

        return  (isset($data['name']) && $data['name']                          != $this->articulo_existente->name) ||
                (isset($data['bar_code']) && $data['bar_code']                  != $this->articulo_existente->bar_code) ||
                (isset($data['provider_code']) && $data['provider_code']        != $this->articulo_existente->provider_code) ||
                (isset($data['stock_min']) && $data['stock_min']                != $this->articulo_existente->stock_min) ||
                (isset($data['iva_id']) && $data['iva_id']                      != $this->articulo_existente->iva_id) ||
                (isset($data['cost']) && $data['cost']                          != $this->articulo_existente->cost) ||
                (isset($data['cost_in_dollars']) && $data['cost_in_dollars']    != $this->articulo_existente->cost_in_dollars) ||
                (isset($data['percentage_gain']) && $data['percentage_gain']    != $this->articulo_existente->percentage_gain) ||
                (!is_null($new_price) && abs($actual_price - $new_price) > $epsilon) ||
                (isset($data['category_id']) && $data['category_id']            != $this->articulo_existente->category_id) ||
                (isset($data['sub_category_id']) && $data['sub_category_id']    != $this->articulo_existente->sub_category_id) ||
                (isset($data['percentage_gain_blanco']) && $data['percentage_gain_blanco']    != $this->articulo_existente->percentage_gain_blanco);
    }

    // function cambio_el_stock_por_direccion($row, $data) {
        
    //     $cambio = false;

    //     foreach ($this->addresses as $address)  {

    //         $address_excel = (float)ImportHelper::getColumnValue($row, $address->street, $this->columns);

    //         foreach ($this->articulo_existente->addresses as $article_address) {

    //             if ($article_address->id == $address->id) {

    //                 if ((float)$address_excel != (float)$article_address->pivot->amount) {
    //                     $cambio = true;
    //                 }

    //             }

    //         }
    //     }

    //     return $cambio;
    // }

    function isFirstRow($row) {
        return ImportHelper::getColumnValue($row, 'nombre', $this->columns) == 'Nombre';
    }

    function getCostInDollars($row) {
        if (ImportHelper::getColumnValue($row, 'moneda', $this->columns) == 'USD') {
            return 1;
        }
        return 0;
    }

    function setStockAddresses($row) {
        $set_stock_from_addresses = false;

        $segundos_para_agregar = 5;

        foreach ($this->addresses as $address) {
            
            $address_excel = (float)ImportHelper::getColumnValue($row, $address->street, $this->columns);
            
            if (!is_null($address_excel) && $address_excel != 0) {

                // Log::info('Columna '.$address->street.' para articulo '.$this->articulo_existente->name.' vino con '.$address_excel);
                $request = new \Illuminate\Http\Request();
                $request->model_id = $this->articulo_existente->id;
                $request->to_address_id = $address->id;
                $request->from_excel_import = true;

                $finded_address = null;
                foreach ($this->articulo_existente->addresses as $article_address) {
                    if ($article_address->id == $address->id) {
                        $finded_address = $article_address;
                    }
                }

                if (is_null($finded_address)) {

                    $this->articulo_existente->addresses()->attach($address->id);

                    $request->amount = $address_excel;
                    $set_stock_from_addresses = true;
                    $this->stock_movement_ct->store($request, true, $this->user, $this->auth_user_id, $segundos_para_agregar);

                } else {
                    $cantidad_anterior = $finded_address->pivot->amount;
                    if ($address_excel != $cantidad_anterior) {
                        $set_stock_from_addresses = true;
                        $new_amount = $address_excel - $cantidad_anterior;
                        $request->amount = $new_amount;
                        $this->stock_movement_ct->store($request, true, $this->user, $this->auth_user_id, $segundos_para_agregar);
                    } else {
                        // Log::info('No se actualizo porque no hubo ningun cambio');
                    }
                }

                $segundos_para_agregar += 5;
                // Log::info('---------------------------------');
            }
        }
        if ($set_stock_from_addresses) {
            ArticleHelper::setArticleStockFromAddresses($this->articulo_existente, false);
        }
    }

    function setStockMovement($row) {
        $stock_actual = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);

        // Aca tengo que chequear que stck_actual no sea null
        // Probar cuando importo excel de bellini y ni siquiera marco la columna
        if (!is_null($stock_actual)
            && !count($this->articulo_existente->addresses) >= 1
            && $this->articulo_existente->stock != $stock_actual) {
            $request = new \Illuminate\Http\Request();
            $request->model_id = $this->articulo_existente->id;
            $request->from_excel_import = true;
            $request->amount = $stock_actual - $this->articulo_existente->stock;
            $this->stock_movement_ct->store($request, true, $this->user, $this->auth_user_id);
            Log::info('se mando a guardar stock_movement de '.$this->articulo_existente->name.' con amount = '.$request->amount);
        } 
    }

    function aplicar_descuentos($row) {

        if (!is_null(ImportHelper::getColumnValue($row, 'descuentos', $this->columns))) {

            $_discounts = explode('_', ImportHelper::getColumnValue($row, 'descuentos', $this->columns));
            
            $discounts = [];
            
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts[] = $discount;
            } 
            
            ArticlePricesHelper::adjuntar_descuentos($this->articulo_existente, $discounts);

            if (UserHelper::hasExtencion('articulos_precios_en_blanco', $this->user)) {

                Log::info('Tiene extencion para descuentos en blanco');

                $this->aplicar_descuentos_en_blanco($row);
            }
        }
    }

    function aplicar_descuentos_en_blanco($row) {

        if (!is_null(ImportHelper::getColumnValue($row, 'descuentos_en_blanco', $this->columns))) {
            
            Log::info('Aplicando descuentos en blanco');

            $_discounts = explode('_', ImportHelper::getColumnValue($row, 'descuentos_en_blanco', $this->columns));
            
            Log::info('_discounts:');
            Log::info($_discounts);

            $discounts = [];
            
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts[] = $discount;
            } 
            
            ArticlePricesHelper::adjuntar_descuentos_en_blanco($this->articulo_existente, $discounts);
        }

    }

    function aplicar_recargos($row) {

        if (!is_null(ImportHelper::getColumnValue($row, 'recargos', $this->columns))) {

            $_surchages = explode('_', ImportHelper::getColumnValue($row, 'recargos', $this->columns));
            
            $surchages = [];
            
            foreach ($_surchages as $_surchage) {
                $surchage = new \stdClass;
                $surchage->percentage = $_surchage;
                $surchages[] = $surchage;
            } 
            
            ArticlePricesHelper::adjuntar_recargos($this->articulo_existente, $surchages);

            if (UserHelper::hasExtencion('articulos_precios_en_blanco', $this->user)) {

                $this->aplicar_recargos_en_blanco($row);
            }
        }
    }

    function aplicar_recargos_en_blanco($row) {

        if (!is_null(ImportHelper::getColumnValue($row, 'recargos_en_blanco', $this->columns))) {

            $_surchages = explode('_', ImportHelper::getColumnValue($row, 'recargos_en_blanco', $this->columns));
            
            $surchages = [];
            
            foreach ($_surchages as $_surchage) {
                $surchage = new \stdClass;
                $surchage->percentage = $_surchage;
                $surchages[] = $surchage;
            } 
            
            ArticlePricesHelper::adjuntar_recargos_en_blanco($this->articulo_existente, $surchages);
        }

    }

    function setProvider($row) {
        if ($this->provider_id != 0 || !is_null(ImportHelper::getColumnValue($row, 'proveedor', $this->columns))) {
            $provider_id = null;
            if ($this->provider_id != 0) {
                $provider_id = $this->provider_id;
            } else {
                $provider_id = $this->ct->getModelBy('providers', 'name', ImportHelper::getColumnValue($row, 'proveedor', $this->columns), true, 'id', false, $this->user->id);
            }

            if (!is_null($provider_id)) {
                $this->articulo_existente->provider_id = $provider_id;
                $this->articulo_existente->save();
            }
        }
    }
}
