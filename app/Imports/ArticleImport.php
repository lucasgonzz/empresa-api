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
    
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user) {
        set_time_limit(9999999999);

        Log::info('Se creo ArticleImport');

        Log::info('user param:');
        Log::info((array)$user);

        $this->user = $user;

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

        $this->existing_articles = ArticleImportHelper::set_existing_articles($this->user, $this->props_para_actualizar, $this->provider_id);


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

        Log::info('existing_articles: '.count($this->existing_articles));

        foreach ($rows as $row) {
            // Log::info('Fila N° '.$this->num_row);
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    Log::info('Entro con la fila N° '.$this->num_row);


                    $num = ImportHelper::getColumnValue($row, 'numero', $this->columns);
                    $bar_code = ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns);
                    $provider_code = ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns);
                    $name = ImportHelper::getColumnValue($row, 'nombre', $this->columns);

                    $articulo_encontrado = null;

                    if (!is_null($num) && isset($this->existing_articles[$num])) {
                        $articulo_encontrado = $this->existing_articles[$num];
                        Log::info('Buscando por num');
                    } else if (!is_null($provider_code)) {
                        Log::info('Buscando por provider_code');
                        foreach ($this->existing_articles as $existing_article) {
                            if ($existing_article['provider_code'] === $provider_code) {
                                $articulo_encontrado = $existing_article;
                                Log::info('encontro');
                                break;
                            }
                        }
                    } else if (!is_null($bar_code)) {
                        Log::info('Buscando por bar_code');
                        foreach ($this->existing_articles as $existing_article) {
                            if ($existing_article['bar_code'] === $bar_code) {
                                $articulo_encontrado = $existing_article;
                                Log::info('encontro');
                                break;
                            }
                        }
                    } else if (!is_null($name)) {
                        Log::info('Buscando por name');
                        foreach ($this->existing_articles as $existing_article) {
                            if ($existing_article['name'] === $name) {
                                $articulo_encontrado = $existing_article;
                                Log::info('encontro');
                                break;
                            }
                        }
                    }
                        

                    $this->saveArticle($row, $articulo_encontrado);


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
            $this->crear_articulos();

            $this->actualizar_articulos();


            ArticleImportHelper::create_import_history($this->user, $this->provider_id, $this->created_models, $this->updated_models, $this->columns);

            ArticleImportHelper::enviar_notificacion($this->user);
            
            $this->trabajo_terminado = true;
        }


    }

    function crear_articulos() {

        Log::info(count($this->articulos_para_crear).' articulos para crear:');

        Article::insert($this->articulos_para_crear);

        Log::info('Se crearon:');
        
        ArticleImportHelper::set_articles_num($this->user, $this->ct);
    }

    function actualizar_articulos() {
        $ids = array_keys($this->articulos_para_actualizar);
        $updatedData = array_values($this->articulos_para_actualizar);

        Log::info(count($this->articulos_para_actualizar).' articulos para actualizar:');

        foreach ($this->articulos_para_actualizar as $article_id => $article_data) {
            Article::where('id', $article_id)->update($article_data);
        }
    }

    function saveArticle($row, $articulo_existente) {
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
        } else if (is_null($articulo_existente)) {
            $data['iva_id'] = 2;
        }

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['category_id'] = LocalImportHelper::getCategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), $this->ct);
        }

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['sub_category_id'] = LocalImportHelper::getSubcategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns), $this->ct);
        }
        
        if (!is_null($articulo_existente) && $this->isDataUpdated($articulo_existente, $data)) {

            $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), $articulo_existente['id'], $this->user->id);

            if (UserHelper::hasExtencion('articles_pre_import', $this->user)) {

                $this->articles_pre_import_helper->add_article($articulo_existente, $data);

            } else {

                Log::info('Actualizar '.$articulo_existente['name']);
                
                $this->articulos_para_actualizar[$articulo_existente['id']] = $data;

                $this->updated_models++;
            }

        } else if (is_null($articulo_existente) && $this->create_and_edit) {
            // if (!is_null(ImportHelper::getColumnValue($row, 'codigo', $this->columns))) {
            //     $data['num'] = ImportHelper::getColumnValue($row, 'codigo', $this->columns);
            // } else {
            //     $data['num'] = $this->ct->num('articles');
            // }
            if (!is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns))) {
                $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), null, $this->user->id);
            }
            $data['user_id'] = $this->user->id;
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $data['apply_provider_percentage_gain'] = 1;

            $this->articulos_para_crear[] = $data;

            // $articulo_existente = Article::create($data);
            $this->created_models++;
        } 
        // if (!is_null($article)) {

        //     $this->setDiscounts($row, $article);
        //     $this->setProvider($row, $article);

        //     $this->setStockAddresses($row, $article);
        //     $this->setStockMovement($row, $article);

        //     ArticleHelper::setFinalPrice($article, null, $this->user);
        // }
    }

    function isDataUpdated($article, $data) {
        return  (isset($data['name']) && $data['name']                          != $article['name']) ||
                (isset($data['bar_code']) && $data['bar_code']                  != $article['bar_code']) ||
                (isset($data['provider_code']) && $data['provider_code']        != $article['provider_code']) ||
                (isset($data['stock_min']) && $data['stock_min']                != $article['stock_min']) ||
                (isset($data['iva_id']) && $data['iva_id']                      != $article['iva_id']) ||
                (isset($data['cost']) && $data['cost']                          != $article['cost']) ||
                (isset($data['cost_in_dollars']) && $data['cost_in_dollars']    != $article['cost_in_dollars']) ||
                (isset($data['percentage_gain']) && $data['percentage_gain']    != $article['percentage_gain']) ||
                (isset($data['price']) && $data['price']                        != $article['price']) ||
                (isset($data['category_id']) && $data['category_id']            != $article['category_id']) ||
                (isset($data['sub_category_id']) && $data['sub_category_id']    != $article['sub_category_id']) ||
                (isset($data['stock']) && $data['stock']                        != $article['stock']);
    }
    function isFirstRow($row) {
        return ImportHelper::getColumnValue($row, 'nombre', $this->columns) == 'Nombre';
    }

    function getCostInDollars($row) {
        if (ImportHelper::getColumnValue($row, 'moneda', $this->columns) == 'USD') {
            return 1;
        }
        return 0;
    }

    function setStockAddresses($row, $article) {
        $set_stock_from_addresses = false;
        foreach ($this->addresses as $address) {
            $address_excel = (float)ImportHelper::getColumnValue($row, $address->street, $this->columns);
            if (!is_null($address_excel) && $address_excel != 0) {
                // Log::info('Columna '.$address->street.' para articulo '.$article->name.' vino con '.$address_excel);
                $request = new \Illuminate\Http\Request();
                $request->model_id = $article->id;
                $request->to_address_id = $address->id;
                $request->from_excel_import = true;

                $finded_address = null;
                foreach ($article->addresses as $article_address) {
                    if ($article_address->id == $address->id) {
                        $finded_address = $article_address;
                    }
                }

                if (is_null($finded_address)) {

                    $article->addresses()->attach($address->id);

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
            ArticleHelper::setArticleStockFromAddresses($article);
        }
    }

    function setStockMovement($row, $article) {
        $stock_actual = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);
        if (!count($article->addresses) >= 1
            && $article->stock != $stock_actual) {
            $request = new \Illuminate\Http\Request();
            $request->model_id = $article->id;
            $request->from_excel_import = true;
            $request->amount = $stock_actual - $article->stock;
            $this->stock_movement_ct->store($request);
            // Log::info('se mando a guardar stock_movement de '.$article->name.' con amount = '.$request->amount);
        } 
    }

    function setDiscounts($row, $article) {
        if (!is_null(ImportHelper::getColumnValue($row, 'descuentos', $this->columns))) {
            $_discounts = explode('_', ImportHelper::getColumnValue($row, 'descuentos', $this->columns));
            $discounts = [];
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts[] = $discount;
            } 
            ArticleHelper::setDiscounts($article, $discounts);
        }
    }

    function setProvider($row, $article) {
        if ($this->provider_id != 0 || !is_null(ImportHelper::getColumnValue($row, 'proveedor', $this->columns))) {
            $provider_id = null;
            if ($this->provider_id != 0) {
                $provider_id = $this->provider_id;
            } else {
                $provider_id = $this->ct->getModelBy('providers', 'name', ImportHelper::getColumnValue($row, 'proveedor', $this->columns), true, 'id');
            }

            if (!is_null($provider_id)) {
                $article->provider_id = $provider_id;
                $article->save();
            }
        }
    }
}
