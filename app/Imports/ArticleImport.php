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
use App\Http\Controllers\Helpers\getIva;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\update;
use App\Models\Address;
use App\Models\Article;
use App\Models\ImportHistory;
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


    // public function chunkSize(): int
    // {
    //     return 100;
    // }
    
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user, $auth_user_id, $archivo_excel_path) {
        set_time_limit(9999999999);

        Log::info('Se creo ArticleImport');

        // Log::info('user param:');
        // Log::info((array)$user);

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

        $this->props_para_actualizar = [
            'id',
            'num',
            'name',
            'bar_code',
            'provider_code',
            'stock_min',
            'iva_id',
            'cost',
            'cost_in_dollars',
            'percentage_gain',
            'price',
            'category_id',
            'sub_category_id',
            'stock',
        ];

        $this->articulos_para_crear = [];
        $this->articulos_para_actualizar = [];

        // $this->existing_articles = ArticleImportHelper::set_existing_articles($this->user, $this->props_para_actualizar, $this->provider_id);


        $this->import_history_chequeado = false;

        if (UserHelper::hasExtencion('articles_pre_import', $this->user)) {
            $this->articles_pre_import_helper = new ArticlesPreImportHelper($this->provider_id, $this->pre_import_id);
        }

        $this->trabajo_terminado = false;
    }


    function setProps() {
        $this->props_to_set = [
            'name'              => 'nombre',
            'bar_code'          => 'codigo_de_barras',
            'provider_code'     => 'codigo_de_proveedor',
            // 'stock'             => 'stock_actual',
            'stock_min'         => 'stock_minimo',
            'cost'              => 'costo',
            'percentage_gain'   => 'margen_de_ganancia',
            'price'             => 'precio',
        ];
    }

    function setAddresses() {
        $this->addresses = Address::where('user_id', $this->user->id)
                                    ->get();
        $this->stock_movement_ct = new StockMovementController();
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns)) || !is_null(ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns)) || !is_null(ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns)) || !is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;

        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 

        foreach ($rows as $row) {
            // Log::info('Fila N° '.$this->num_row);
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    Log::info('Entro con la fila N° '.$this->num_row);


                    $num = ImportHelper::getColumnValue($row, 'numero', $this->columns);
                    $bar_code = ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns);
                    $provider_code = ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns);
                    $name = ImportHelper::getColumnValue($row, 'nombre', $this->columns);

                    $this->articulo_existente = ArticleImportHelper::get_articulo_encontrado($this->user, $row, $this->columns);

                    $this->saveArticle($row);

                } else {
                    Log::info('Se omitio una fila N° '.$this->num_row);
                } 
            } else if ($this->num_row > $this->finish_row) {
                Log::info('Se acabaron las filas');
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

    function saveArticle($row) {
        Log::info('saveArticle para row N° '.$this->num_row);
        $data = [];

        $this->save_stock_movement = false;
        
        foreach ($this->props_to_set as $key => $value) {
            if (!ImportHelper::isIgnoredColumn($value, $this->columns)) {
                $data[$key] = ImportHelper::getColumnValue($row, $value, $this->columns);
                // Log::info('Agregando '.$value.' con '.ImportHelper::getColumnValue($row, $value, $this->columns));
            }
        }

        if (!ImportHelper::isIgnoredColumn('unidad_medida', $this->columns)) {
            $data['unidad_medida_id'] = ArticleImportHelper::get_unidad_medida_id(ImportHelper::getColumnValue($row, 'unidad_medida', $this->columns));
        }

        if (!ImportHelper::isIgnoredColumn('proveedor', $this->columns)) {
            LocalImportHelper::saveProvider(ImportHelper::getColumnValue($row, 'proveedor', $this->columns), $this->ct);
        }

        if (!ImportHelper::isIgnoredColumn('iva', $this->columns)) {
            $data['iva_id'] = LocalImportHelper::getIvaId(ImportHelper::getColumnValue($row, 'iva', $this->columns));
        } else if (is_null($this->articulo_existente)) {
            $data['iva_id'] = 2;
        }

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['category_id'] = LocalImportHelper::getCategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), $this->ct);
        }

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['sub_category_id'] = LocalImportHelper::getSubcategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns), $this->ct);
        }

        $article = null;
        
        if (!is_null($this->articulo_existente) && $this->isDataUpdated($row, $data)) {
            Log::info('Hubo cambios');
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
            // Log::info((array)$article);

            $this->setDiscounts($row);
            $this->setProvider($row);

            $this->setStockAddresses($row);
            $this->setStockMovement($row);

            ArticleHelper::setFinalPrice($this->articulo_existente, null, $this->user, $this->auth_user_id);
        }
    }

    function isDataUpdated($row, $data) {
        return  (isset($data['name']) && $data['name']                          != $this->articulo_existente->name) ||
                (isset($data['bar_code']) && $data['bar_code']                  != $this->articulo_existente->bar_code) ||
                (isset($data['provider_code']) && $data['provider_code']        != $this->articulo_existente->provider_code) ||
                (isset($data['stock_min']) && $data['stock_min']                != $this->articulo_existente->stock_min) ||
                (isset($data['iva_id']) && $data['iva_id']                      != $this->articulo_existente->iva_id) ||
                (isset($data['cost']) && $data['cost']                          != $this->articulo_existente->cost) ||
                (isset($data['cost_in_dollars']) && $data['cost_in_dollars']    != $this->articulo_existente->cost_in_dollars) ||
                (isset($data['percentage_gain']) && $data['percentage_gain']    != $this->articulo_existente->percentage_gain) ||
                (isset($data['price']) && $data['price']                        != $this->articulo_existente->price) ||
                (isset($data['category_id']) && $data['category_id']            != $this->articulo_existente->category_id) ||
                (isset($data['sub_category_id']) && $data['sub_category_id']    != $this->articulo_existente->sub_category_id);
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
        foreach ($this->addresses as $address) {
            $address_excel = (float)ImportHelper::getColumnValue($row, $address->street, $this->columns);
            if (!is_null($address_excel) && $address_excel != 0) {
                Log::info('Columna '.$address->street.' para articulo '.$this->articulo_existente->name.' vino con '.$address_excel);
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
                    $this->stock_movement_ct->store($request);

                } else {
                    $cantidad_anterior = $finded_address->pivot->amount;
                    if ($address_excel != $cantidad_anterior) {
                        $set_stock_from_addresses = true;
                        $new_amount = $address_excel - $cantidad_anterior;
                        $request->amount = $new_amount;
                        $this->stock_movement_ct->store($request);
                    } else {
                        // Log::info('No se actualizo porque no hubo ningun cambio');
                    }
                }
                // Log::info('---------------------------------');
            }
        }
        if ($set_stock_from_addresses) {
            ArticleHelper::setArticleStockFromAddresses($this->articulo_existente);
        }
    }

    function setStockMovement($row) {
        $stock_actual = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);
        if (!count($this->articulo_existente->addresses) >= 1
            && $this->articulo_existente->stock != $stock_actual) {
            $request = new \Illuminate\Http\Request();
            $request->model_id = $this->articulo_existente->id;
            $request->from_excel_import = true;
            $request->amount = $stock_actual - $this->articulo_existente->stock;
            $this->stock_movement_ct->store($request);
            Log::info('se mando a guardar stock_movement de '.$this->articulo_existente->name.' con amount = '.$request->amount);
        } 
    }

    function setDiscounts($row) {
        if (!is_null(ImportHelper::getColumnValue($row, 'descuentos', $this->columns))) {
            $_discounts = explode('_', ImportHelper::getColumnValue($row, 'descuentos', $this->columns));
            $discounts = [];
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts[] = $discount;
            } 
            ArticleHelper::setDiscounts($this->articulo_existente, $discounts);
        }
    }

    function setProvider($row) {
        if ($this->provider_id != 0 || !is_null(ImportHelper::getColumnValue($row, 'proveedor', $this->columns))) {
            $provider_id = null;
            if ($this->provider_id != 0) {
                $provider_id = $this->provider_id;
            } else {
                $provider_id = $this->ct->getModelBy('providers', 'name', ImportHelper::getColumnValue($row, 'proveedor', $this->columns), true, 'id');
            }

            if (!is_null($provider_id)) {
                $this->articulo_existente->provider_id = $provider_id;
                $this->articulo_existente->save();
            }
        }
    }
}
