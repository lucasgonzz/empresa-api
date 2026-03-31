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
use App\Http\Controllers\Helpers\import\article\ActualizarBBDD;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\IsArticleUpdated;
use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Http\Controllers\Stock\StockMovementController;
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
use Illuminate\Support\Str;

class ArticleImport implements ToCollection
{

    
    public function __construct(
        $columns, 
        $create_and_edit, 
        $start_row, 
        $finish_row, 
        $provider_id, 
        $user, 
        $auth_user_id, 
        $archivo_excel_path, 
        $chunk_number, 
        $registrar_articulos_creados, 
        $registrar_articulos_actualizados, 
        $import_result_id, 

        $actualizar_articulos_de_otro_proveedor, 
        $actualizar_proveedor, 
        $permitir_provider_code_repetido, 
        $permitir_provider_code_repetido_en_multi_providers,
        $actualizar_por_provider_code
    ) {


        $this->observations = [];

        $this->user                             = $user;
        $this->auth_user_id                     = $auth_user_id;
        $this->archivo_excel_path               = $archivo_excel_path;
        $this->chunk_number                     = $chunk_number;
        $this->registrar_articulos_creados      = $registrar_articulos_creados;
        $this->registrar_articulos_actualizados = $registrar_articulos_actualizados;
        $this->import_result_id                 = $import_result_id;



        $this->actualizar_articulos_de_otro_proveedor               = $actualizar_articulos_de_otro_proveedor;
        $this->actualizar_proveedor                                 = $actualizar_proveedor;
        $this->permitir_provider_code_repetido                      = $permitir_provider_code_repetido;
        $this->permitir_provider_code_repetido_en_multi_providers   = $permitir_provider_code_repetido_en_multi_providers;
        $this->actualizar_por_provider_code                         = $actualizar_por_provider_code;


        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->provider_id = $provider_id;
        $this->provider = null;
        

        $this->created_models = 0;
        $this->updated_models = 0;

        $this->articulos_creados = [];
        $this->articulos_actualizados = [];
        $this->updated_props = [];


        Log::info('');
        Log::info('Empieza ArticleImport');
        Log::info('');



        $this->process_row = new ProcessRow([
            'ct'                                            => $this->ct, 
            'columns'                                       => $this->columns,
            'user'                                          => $this->user,
            'provider_id'                                   => $this->provider_id,
            'create_and_edit'                               => $this->create_and_edit,

            'actualizar_articulos_de_otro_proveedor'                => $this->actualizar_articulos_de_otro_proveedor,
            'actualizar_proveedor'                                  => $this->actualizar_proveedor,
            'permitir_provider_code_repetido'                       => $this->permitir_provider_code_repetido,
            'permitir_provider_code_repetido_en_multi_providers'    => $this->permitir_provider_code_repetido_en_multi_providers,
            'actualizar_por_provider_code'                          => $this->actualizar_por_provider_code,
        ]);

        $this->nombres_proveedores = [];

        $this->import_history_chequeado = false;

        if (UserHelper::hasExtencion('articles_pre_import', $this->user)) {
            $this->articles_pre_import_helper = new ArticlesPreImportHelper($this->provider_id, $this->pre_import_id, $this->user);
        }

        $this->trabajo_terminado = false;
    }


    function slugs($rows) {
        // ✅ Prefetch de slugs para evitar queries por fila al crear artículos
        $slug_bases = [];
        foreach ($rows as $row) {
            $name = ImportHelper::getColumnValue($row, 'nombre', $this->columns);
            if (!is_null($name) && trim((string)$name) !== '') {
                $base = Str::slug((string)$name);
                if ($base !== '') {
                    $slug_bases[$base] = true;
                }
            }
        }
        $slug_bases = array_keys($slug_bases);

        $taken_slugs = [];
        if (!empty($slug_bases)) {
            $taken_slugs = Article::where('user_id', $this->user->id)
                ->where(function ($q) use ($slug_bases) {
                    foreach ($slug_bases as $base) {
                        $q->orWhere('slug', $base)
                          ->orWhere('slug', 'like', $base . '-%');
                    }
                })
                ->pluck('slug')
                ->toArray();
        }

        $this->process_row->set_taken_slugs($taken_slugs);
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
        // Log::info('entro a collection, rows:');
        // Log::info($rows);


        $rows_observations = [];

        
        // $text = 'Cacheo de articulos';
        // if ($this->chunk_number == 1) {
        //     $duracion_cacheo = ArticleIndexCache::build(
        //         $this->user->id,
        //         $this->provider_id ?? null,
        //         (bool)$this->actualizar_articulos_de_otro_proveedor,
        //     );
        //     // $duracion_cacheo = number_format($duracion_cacheo, 2, '.', '');
        // } else {
        //     $text .= '. Ya estaba cacheado';
        //     Log::info('Ya estaban cacheados');
        // }


        // ✅ En paralelo: NO asumimos nada por chunk_number.
        // Siempre pedimos el índice: si no existe, get_index() lo construye con lock.
        // $article_index = ArticleIndexCache::get_index(
        //     $this->user->id,
        //     $this->provider_id ? (int)$this->provider_id : null,
        //     (bool)$this->actualizar_articulos_de_otro_proveedor
        // );
        
        $this->iniciar();

        $article_index = ArticleIndexCache::get_index(
            $this->user->id,
            $this->provider_id ? (int)$this->provider_id : null,
            (bool)$this->actualizar_articulos_de_otro_proveedor
        );
        $this->terminar('Obtener cacheo de articulos');

        $this->process_row->set_article_index($article_index);


        $this->iniciar();
        $this->slugs($rows);
        $this->terminar('seteando slugs');

        $this->set_finish_row($rows);

        $this->set_providers($rows);

        $error_message = null;

        $filas_procesada = $this->start_row;
        $this->filas_procesadas = 0;

        // Log::info('rows:');
        // Log::info($rows);

        // Log::info('filas_procesadas: '.$this->filas_procesadas);
        // Log::info('start_row: '.$this->start_row);
        // Log::info('finish_row: '.$this->finish_row);
        
        $this->iniciar();
        foreach ($rows as $row) {

            // if ($this->esta_en_el_rango_de_filas()) {
    
                // Log::info('');
                // Log::info('');
                // Log::info('');
                Log::info('Va por fila '.$this->filas_procesadas);

                if ($this->checkRow($row)) {

                    // $this->articulo_existente = ArticleImportHelper::get_articulo_encontrado($this->user, $row, $this->columns);

                    try {

                        $row_observations = $this->process_row->procesar($row, $this->nombres_proveedores);
                        
                        $row_observations['fila'] = $filas_procesada;

                        $rows_observations[] = $row_observations;

                        $this->filas_procesadas++;
                        $filas_procesada++;
                        

                        // $obs_row = ' Info fila N° '.$this->filas_procesadas.': '.$observations.' ';
                        // $this->observations .= $obs_row;

                    } catch (\Throwable $e) {

                        $error_message = 'Error en la linea '.$this->filas_procesadas;


                        Log::error('Error al importar, se capturó una excepción.');
                        Log::error('error_message: '.$error_message);
                        Log::error('Mensaje: ' . $e->getMessage());
                        Log::error('Archivo: ' . $e->getFile());
                        Log::error('Línea: ' . $e->getLine());
                        // Log::error('Trace: ' . $e->getTraceAsString());


                        // Registra el progreso y errores en Import History
                        // ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->created_models, $this->updated_models, $this->columns, $this->archivo_excel_path, $error_message, $this->articulos_creados, $this->articulos_actualizados, $this->updated_props);

                        // ArticleImportHelper::error_notification($this->user, $this->filas_procesadas, $e->getMessage());
                        
                        throw $e;

                    } 


                } else {
                    // Log::info('Se omitio una fila N° '.$this->filas_procesadas.' con nombre '.ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                } 

            // } else if ($this->filas_procesadas > $this->finish_row) {

            //     break;
            // }
        }

        $this->terminar('Procesar todas las filas');


        if (!$this->trabajo_terminado) {


            $this->iniciar();
            $articulos_creados = $this->guardar_articulos();

            $this->terminar('ActualizarBBDD desde ArticleImport');


            // $articulos_creados = $this->process_row->getArticulosParaCrear();
            $articulos_actualizados = $this->process_row->getArticulosParaActualizar();

            $articles_match = $this->process_row->get_articles_match();
            $articles_repetidos = $this->process_row->get_articles_repetidos();


            Log::info('Trabajo terminado en ArticleImport');
            Log::info('articulos_creados: '.count($articulos_creados));
            Log::info('articulos_actualizados: '.count($articulos_actualizados));
            Log::info('articles_match: '.$articles_match);
            Log::info('articles_repetidos: '.$articles_repetidos);
            Log::info('filas_procesadas: '.$this->filas_procesadas);



            $this->iniciar();
            ArticleImportHelper::update_article_import_result([
                'import_result_id'                      => $this->import_result_id, 
                'articulos_creados'                     => $articulos_creados, 
                'articulos_actualizados'                => $articulos_actualizados, 
                'articles_match'                        => $articles_match, 
                'articles_repetidos'                    => $articles_repetidos, 
                'filas_procesadas'                      => $this->filas_procesadas, 
                'provider_id'                           => $this->provider_id,
                'registrar_articulos_creados'           => $this->registrar_articulos_creados,
                'registrar_articulos_actualizados'      => $this->registrar_articulos_actualizados,
            ]);
            $this->terminar('Update article_import_result');
            
            $this->trabajo_terminado = true;
        }

        // Log::info('retornando observations: '.$this->observations);

        return [
            'article_import_observations'   => $this->observations,
            'rows_observations'             => $rows_observations,
        ];

        // return $this->observations;

    }

    function iniciar() {
        $this->inicio = microtime(true);
    }

    function terminar($text) {

        $this->fin = microtime(true);
        $dur = $this->fin - $this->inicio;

        if ($dur > 0) {
            $proceso = [
                'name'          => $text,
                'duration'      => number_format($dur, 2, '.', ''),
            ];

            $this->observations['procesos'][] = $proceso;
        }
    }

    function guardar_articulos() {


        $articulosParaCrear = $this->process_row->getArticulosParaCrear();
        $articulosParaActualizar = $this->process_row->getArticulosParaActualizar();
        $provider_buffer  = $this->process_row->get_provider_relations_buffer();


        try {

            $this->iniciar();
            $actualizar_bbdd = new ActualizarBBDD($articulosParaCrear, $articulosParaActualizar, $this->user, $this->auth_user_id, $this->permitir_provider_code_repetido, $this->chunk_number, $provider_buffer);
            $observations = $actualizar_bbdd->get_observations();

            foreach ($observations as $observation) {
                $observation['name'] = 'BBDD -> '.$observation['name'];
                $this->observations['procesos'][] = $observation;
            }


            return $actualizar_bbdd->get_articulos_creados_models();

        } catch (\Throwable $e) {

            $error_message = 'Error al guardar cambios';

            Log::error('Error al importar, se capturó una excepción. Intentando llamar a ActualizarBBDD');
            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
            // Log::error('Trace: ' . $e->getTraceAsString());


            // Registra el progreso y errores en Import History
            // ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->columns, $this->archivo_excel_path, $error_message, $this->created_models, $this->updated_models);

            // ArticleImportHelper::error_notification($this->user, null, $e->getMessage());

            throw $e;
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
        return $this->filas_procesadas >= $this->start_row;
    }

    function check_fila_fin() {
        return $this->filas_procesadas <= $this->finish_row;
    }

    function set_providers($rows) {

        if ($this->provider_id != 0) return;

        $this->nombres_proveedores = [];

        if (isset($this->columns['proveedor'])) {

            $index_columna_proveedor = $this->columns['proveedor'];

            $proveedoresNombres = collect($rows)
                        ->pluck($index_columna_proveedor) // índice de la columna "proveedor"
                        ->filter(fn($nombre) => !is_null($nombre) && trim($nombre) !== '')
                        ->map(fn($nombre) => trim($nombre))
                        ->unique()
                        ->values();

            // Paso 2: obtener todos los proveedores existentes
            $proveedoresExistentes = Provider::where('user_id', $this->user->id)
                                        ->whereIn('name', $proveedoresNombres)
                                        ->get();

            // Paso 3: mapear por nombre para acceso rápido
            $this->nombres_proveedores = $proveedoresExistentes->keyBy('name');

            // Paso 4: determinar qué nombres faltan
            $nombresFaltantes = $proveedoresNombres->diff($this->nombres_proveedores->keys());

            // Paso 5: crear los que faltan
            foreach ($nombresFaltantes as $nombre) {
                $this->nombres_proveedores[$nombre] = Provider::create([
                    'name' => $nombre,
                    'user_id' => $this->user->id 
                ]);
            }

        }
    }

    // function guardar_proveedor($row) {

    //     $nombreProveedor = ImportHelper::getColumnValue($row, 'proveedor', $this->columns);

    //     if ($nombreProveedor && isset($this->nombres_proveedores[$nombreProveedor])) {
    //         $proveedor = $this->nombres_proveedores[$nombreProveedor];
    //         return $provider->id;
    //     }
    // }

    function saveArticle($row) {

        // Log::info('saveArticle para row N° '.$this->filas_procesadas);
        
        $data = [];

        $this->save_stock_movement = false;

        $this->row = $row;
        
        foreach ($this->props_to_set as $key => $value) {
            $column_value = ImportHelper::getColumnValue($row, $value, $this->columns);
            
            if (ImportHelper::usa_columna($column_value)) {
                $data[$key] = $column_value;
                // Log::info($key.': '.$column_value); 
            } else {
                // Log::info('No usa '.$key);
            }
        }

        $data = ArticleImportHelper::get_unidad_medida($data, $this->columns, $row);

        ArticleImportHelper::guardar_proveedor($this->columns, $row, $this->ct, $this->user);

        $data = ArticleImportHelper::get_iva_id($data, $this->columns, $row, $this->articulo_existente);


        $category_excel = ImportHelper::getColumnValue($row, 'categoria', $this->columns);
        
        if (ImportHelper::usa_columna($category_excel)) {
            $data['category_id'] = LocalImportHelper::getCategoryId($category_excel, $this->ct, $this->user);

            $sub_category_excel = ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns);
            $data['sub_category_id'] = LocalImportHelper::getSubcategoryId($category_excel, $sub_category_excel, $this->ct, $this->user);

        }

        $article = null;
        
        if (
            !is_null($this->articulo_existente) 
        ) {

            $res = IsArticleUpdated::check($this->articulo_existente, $data, $this->updated_props);

            if ($res['is_data_updated']) {

                $this->updated_props = $res['updated_props'];

                $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), $this->articulo_existente->id, $this->user->id);

                if (UserHelper::hasExtencion('articles_pre_import', $this->user)) {
                    
                    $this->articles_pre_import_helper->add_article($this->articulo_existente, $data);

                } else {

                    $article = Article::find($this->articulo_existente->id);

                    $article->update($data);

                    $this->articulo_existente = $article;

                    $this->updated_models++;
                    $this->articulos_actualizados[] = $article;
                }
            } else {
                // Log::info('No ubo cambios');
            }
            

        } else if (is_null($this->articulo_existente) && $this->create_and_edit) {

           
            if (!is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns))) {
                $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), null, $this->user->id);
            }
            $data['user_id'] = $this->user->id;
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->filas_procesadas);
            $data['apply_provider_percentage_gain'] = 1;

            $data['num'] = $this->ct->num('articles', null, 'user_id', $this->user->id);


            $article = Article::create($data);

            $this->articulo_existente = $article;

            // Log::info('Se creo');

            $this->created_models++;
            $this->articulos_creados[] = $article;

        } else {
            // Log::info('No entro a ningun lado la N° '.$this->filas_procesadas);
        }

        if (!is_null($this->articulo_existente)) {
            
            // Log::info('$article no es null:');

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

        // Log::info('set_propiedades_de_distribuidora');

        if (UserHelper::hasExtencion('articulos_con_propiedades_de_distribuidora', $this->user)) {

            // Log::info('SI ENTRO en set_propiedades_de_distribuidora');

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
            // Log::info('NO ENTRO en set_propiedades_de_distribuidora');
        }
    }

    function aplicar_price_types($row) {

        if (UserHelper::uses_listas_de_precio($this->user)) {

            $price_types = [];

            foreach ($this->price_types as $price_type) {

                $row_name = '%_'.str_replace(' ', '_', strtolower($price_type->name));

                $percentage = ImportHelper::getColumnValue($row, $row_name, $this->columns);

                // Log::info('ArticleImport percentage row :');
                // Log::info($percentage);

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

    // function isDataUpdated($row, $data) {
    //     $epsilon = 0.00001;

    //     $new_price = null;
    //     if (isset($data['price'])) {
    //         $new_price = (float)$data['price'];
    //     }

    //     $updated_props = [];

    //     $actual_pricel = (float)$this->articulo_existente->price;

    //             if (
    //                 isset($data['name']) 
    //                 && $data['name']                        != $this->articulo_existente->name
    //             ) {
    //                 $updated_props[]
    //             }
                
    //             if (
    //                 isset($data['bar_code']) 
    //                 && $data['bar_code']                != $this->articulo_existente->bar_code
    //             ) {
    //             }
                
    //             if (
    //                 isset($data['provider_code']) 
    //                 && $data['provider_code']      != $this->articulo_existente->provider_code
    //             ) {
    //             }
                
    //             if (
    //                 isset($data['stock_min']) 
    //                 && $data['stock_min']              != $this->articulo_existente->stock_min
    //             ) {
    //             }
                
    //             if (
    //                 isset($data['iva_id']) 
    //                 && $data['iva_id']                    != $this->articulo_existente->iva_id
    //             ) {
    //             }
                
    //             if (
    //                 isset($data['cost']) 
    //                 && $data['cost']                        != $this->articulo_existente->cost
    //             ) {
    //             }
                
    //             if (
    //                 isset($data['cost_in_dollars']) 
    //                 && $data['cost_in_dollars']  != $this->articulo_existente->cost_in_dollars
    //             ) {
    //             }
                
    //             if (
    //                 isset($data['percentage_gain']) 
    //                 && $data['percentage_gain']  != $this->articulo_existente->percentage_gain
    //             ) {
    //             }
                
    //             if (!is_null($new_price) && abs($actual_price - $new_price) > $epsilon) {

    //             } 

    //             if (
    //                 isset($data['category_id']) 
    //                 && $data['category_id']          != $this->articulo_existente->category_id) 
    //             {
    //             }
                
    //             if (
    //                 isset($data['sub_category_id']) 
    //                 && $data['sub_category_id']  != $this->articulo_existente->sub_category_id) 
    //             {
    //             }
                
    //             if (
    //                 isset($data['percentage_gain_blanco']) 
    //                 && $data['percentage_gain_blanco']    != $this->articulo_existente->percentage_gain_blanco) 
    //             {
    //             }
                



    //     return  (isset($data['name']) && $data['name']                          != $this->articulo_existente->name) ||
    //             (isset($data['bar_code']) && $data['bar_code']                  != $this->articulo_existente->bar_code) ||
    //             (isset($data['provider_code']) && $data['provider_code']        != $this->articulo_existente->provider_code) ||
    //             (isset($data['stock_min']) && $data['stock_min']                != $this->articulo_existente->stock_min) ||
    //             (isset($data['iva_id']) && $data['iva_id']                      != $this->articulo_existente->iva_id) ||
    //             (isset($data['cost']) && $data['cost']                          != $this->articulo_existente->cost) ||
    //             (isset($data['cost_in_dollars']) && $data['cost_in_dollars']    != $this->articulo_existente->cost_in_dollars) ||
    //             (isset($data['percentage_gain']) && $data['percentage_gain']    != $this->articulo_existente->percentage_gain) ||
    //             (!is_null($new_price) && abs($actual_price - $new_price) > $epsilon) ||
    //             (isset($data['category_id']) && $data['category_id']            != $this->articulo_existente->category_id) ||
    //             (isset($data['sub_category_id']) && $data['sub_category_id']    != $this->articulo_existente->sub_category_id) ||
    //             (isset($data['percentage_gain_blanco']) && $data['percentage_gain_blanco']    != $this->articulo_existente->percentage_gain_blanco);
    // }

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
            $nombre_columna = str_replace(' ', '_', strtolower($address->street));
            // Log::info('----------------------- ');
            // Log::info('nombre_columna: '.$nombre_columna);

            $address_excel = ImportHelper::getColumnValue($row, $nombre_columna, $this->columns);
            // Log::info('se llamo getColumnValue');
            // Log::info($address_excel);


            // Log::info('address_excel de '.$nombre_columna.' para '.$this->articulo_existente->name.': '.$address_excel);
            
            if (!is_null($address_excel)) {

                $address_excel = (float)$address_excel;

                Log::info('Columna '.$address->street.' para articulo '.$this->articulo_existente->name.' vino con '.$address_excel);
                $data['model_id'] = $this->articulo_existente->id;
                $data['to_address_id'] = $address->id;
                $data['concepto_stock_movement_name'] = 'Importacion de excel';

                $finded_address = null;
                foreach ($this->articulo_existente->addresses as $article_address) {
                    if ($article_address->id == $address->id) {
                        $finded_address = $article_address;
                    }
                }

                if (is_null($finded_address)) {

                    // Esta la comente el 22 de enero del 2025 
                    // $this->articulo_existente->addresses()->attach($address->id);

                    $data['amount'] = $address_excel;
                    $set_stock_from_addresses = true;
                    $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id, $segundos_para_agregar);
                    // Log::info('Se mandaron '.$address_excel.' a '.$address->street);

                } else {
                    // Log::info('Ya tenia la direccion '.$finded_address->street);
                    
                    $cantidad_anterior = $finded_address->pivot->amount;
                    // Log::info('cantidad_anterior: '.$cantidad_anterior);

                    // Log::info('address_excel: '.$address_excel);
                    if ($address_excel != $cantidad_anterior) {
                        $set_stock_from_addresses = true;
                        $new_amount = $address_excel - $cantidad_anterior;
                        $data['amount'] = $new_amount;
                        $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id, $segundos_para_agregar);
                        // Log::info('Se mandaron '.$new_amount.' a '.$address->street);
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

        // Aca tengo que chequear que stock_actual no sea null
        // Probar cuando importo excel de bellini y ni siquiera marco la columna
        if (!is_null($stock_actual)
            && is_numeric($stock_actual)
            && count($this->articulo_existente->addresses) == 0
            && $this->articulo_existente->stock != $stock_actual) {

            $data = [];

            $data['concepto_stock_movement_name'] = 'Importacion de excel';

            $data['model_id'] = $this->articulo_existente->id;
            $data['amount'] = $stock_actual - $this->articulo_existente->stock;

            $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);
            // Log::info('se mando a guardar stock_movement de '.$this->articulo_existente->name.' con amount = '.$data['amount']);
        } 
    }

    function aplicar_descuentos($row) {

        // Log::info('descuentos:');
        // Log::info(ImportHelper::getColumnValue($row, 'descuentos', $this->columns));

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

                // Log::info('Tiene extencion para descuentos en blanco');

                $this->aplicar_descuentos_en_blanco($row);
            }
        }
    }

    function aplicar_descuentos_en_blanco($row) {

        if (!is_null(ImportHelper::getColumnValue($row, 'descuentos_en_blanco', $this->columns))) {
            
            // Log::info('Aplicando descuentos en blanco');

            $_discounts = explode('_', ImportHelper::getColumnValue($row, 'descuentos_en_blanco', $this->columns));
            
            // Log::info('_discounts:');
            // Log::info($_discounts);

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
