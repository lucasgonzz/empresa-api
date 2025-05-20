<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;

class ProcessRow {

    protected $columns;
    protected $user;
    protected $ct;
    protected $provider_id;
    protected $articulosParaActualizar = [];
    protected $articulosParaCrear = [];
    protected $price_types = [];


    /**
     * Constructor: recibe los datos necesarios para procesar las filas
     */
    function __construct($data) {
        $this->columns = $data['columns'];
        $this->user = $data['user'];
        $this->ct = $data['ct'];
        $this->provider_id = $data['provider_id'];

        $this->set_price_types();
    }

    /**
     * Procesa una fila del Excel: busca si el artículo ya existe, y lo actualiza o lo agrega.
     */
    function procesar($row, $nombres_proveedores) {

        $this->nombres_proveedores = $nombres_proveedores;

        $res = $this->get_category_id($row);

        $category_id = $res['category_id'];
        $sub_category_id = $res['sub_category_id'];
        
        $provider_id = $this->get_provider_id($row);

        $iva_id = $this->get_iva_id($row);

        $cost = Self::get_number(ImportHelper::getColumnValue($row, 'costo', $this->columns));
        $price = Self::get_number(ImportHelper::getColumnValue($row, 'precio', $this->columns));

        // Construir array de datos del artículo usando los valores extraídos del Excel
        $data = [
            'id'                   => ImportHelper::getColumnValue($row, 'numero', $this->columns),
            'bar_code'             => ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns),
            'provider_code'        => ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns),
            'name'                 => ImportHelper::getColumnValue($row, 'nombre', $this->columns),
            'stock_min'            => ImportHelper::getColumnValue($row, 'stock_minimo', $this->columns),
            'cost'                 => $cost,
            'percentage_gain'      => ImportHelper::getColumnValue($row, 'margen_de_ganancia', $this->columns),
            'price'                => $price,
            'unidades_individuales'=> ImportHelper::getColumnValue($row, 'u_individuales', $this->columns),
            'category_id'          => $category_id,
            'sub_category_id'      => $sub_category_id,
            'provider_id'          => $provider_id,
            'iva_id'               => $iva_id,
            'user_id'              => $this->user->id,
        ];


        $ya_estaba_en_excel = $this->ya_estaba_en_el_excel($data);


        if ($ya_estaba_en_excel) {
            Log::info('SE OMITIO EN PROCES ROW');
            return;
        }

        $articulo_ya_creado = ArticleIndexCache::find($data, $this->user->id);

        if ($articulo_ya_creado) {
            // Log::info('El articulo ya existia');

            // Comparar propiedades y obtener las que cambiaron
            $cambios = $this->getModifiedFields($articulo_ya_creado, $data);

            $price_types_data = $this->obtener_price_types($row, $articulo_ya_creado);

            $discounts_data = $this->obtener_descuentos($row);

            $stock_a_agregar = $this->obtener_stock($row, $articulo_ya_creado);

            // Log::info('Descuentos para article num: '.$articulo_ya_creado->id.':');
            // Log::info($discounts_data);

            if (count($price_types_data) > 0) {
                $cambios['price_types_data'] = $price_types_data;
            }

            if (count($discounts_data) > 0) {
                $cambios['discounts_data'] = $discounts_data;
            }

            if (!is_null($stock_a_agregar)) {
                $cambios['stock_a_agregar'] = $stock_a_agregar;
            }

            if (!empty($cambios)) {

                $cambios['id'] = $articulo_ya_creado->id;

                $this->articulosParaActualizar[] = $cambios;
            } 

        } else {

            // Log::info('El articulo NO existia');
            // Si no existe, lo agregamos a los artículos para crear
            
            /* 
                * Agrego siempre price_types_data, porque si el articulo no esta creado le agrego todos
                    los price_types.
                * Cuando termino de procesar el Excel y actualizo la bbdd, 
                    le adjunto todos estos price_types,
                * Y desde el ArticleHelper veo si le pongo el % que viene en el excel o 
                    el % por defecto del price_type 
            */
            $price_types_data = $this->obtener_price_types($row);
            $data['price_types_data'] = $price_types_data;

            $discounts_data = $this->obtener_descuentos($row);
            if (count($discounts_data) > 0) {
                $data['discounts_data'] = $discounts_data;
            }


            $stock_a_agregar = $this->obtener_stock($row);

            if (!is_null($stock_a_agregar)) {
                $data['stock_a_agregar'] = $stock_a_agregar;
            }

            $this->articulosParaCrear[] = $data;

            // Lo agregamos al índice para evitar procesarlo duplicado en siguientes filas
            $fakeArticle = new \App\Models\Article($data);
            // $num = $this->ct->num('articles', $this->user->id);
            $fakeArticle->id = 'fake_' . uniqid(); // ID temporal único

            ArticleIndexCache::add($fakeArticle);
        }
    }


    function ya_estaba_en_el_excel($data) {

        // Verificamos si ya existe un artículo con este identificador en el mismo archivo
        $key = $data['bar_code'] ?? $data['provider_code'] ?? $data['name'];


        if ($key) {
            $ya_en_para_crear = false;
            $ya_en_para_actualizar = false;

            foreach ($this->articulosParaCrear as $index => $art) {
                if (
                    (!empty($art['bar_code']) && $art['bar_code'] === $data['bar_code']) ||
                    (!empty($art['provider_code']) && $art['provider_code'] === $data['provider_code']) ||
                    (!empty($art['name']) && $art['name'] === $data['name'])
                ) {
                    // $this->articulosParaCrear[$index] = $data;
                    $ya_en_para_crear = true;
                    break;
                }
            }

            if (!$ya_en_para_crear) {
                foreach ($this->articulosParaActualizar as $index => $art) {
                    if (
                        (!empty($art['bar_code']) && $art['bar_code'] === $data['bar_code']) ||
                        (!empty($art['provider_code']) && $art['provider_code'] === $data['provider_code']) ||
                        (!empty($art['name']) && $art['name'] === $data['name'])
                    ) {
                        // $this->articulosParaActualizar[$index] = $data;
                        $ya_en_para_actualizar = true;
                        break;
                    }
                }
            }

            // Si ya lo teníamos en memoria, evitamos reprocesar
            if ($ya_en_para_crear || $ya_en_para_actualizar) {
                return true;
            }
            return false;
        }
    }


    /**
     * Compara un artículo existente con nuevos datos, y devuelve
     * solo las propiedades que han cambiado.
     */
    function getModifiedFields($existing, array $data): array
    {
        $modified = [];

        foreach ($data as $key => $value) {
            if ($existing->$key != $value) {
                $modified[$key] = $value;
            }
        }

        return $modified;
    }

    static function get_number($number) {

        if (is_null($number) || $number == '') {
            return null;
        }

        $original = $number;

        // 1. Reemplazar la coma por punto
        $normalized = str_replace(',', '.', $original);

        // 2. Convertir a float y limitar a 4 decimales
        $limited = number_format((float)$normalized, 4, '.', '');

        return $limited;
    }


    private function obtener_stock($row, $articulo_ya_creado = null) {

        $excel_stock = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);

        if (
            ImportHelper::usa_columna($excel_stock)
            && is_numeric($excel_stock)
        ) {

            if ($articulo_ya_creado) {

                if (
                    count($articulo_ya_creado->addresses) == 0
                    && $articulo_ya_creado->stock != $excel_stock
                ) {
                    return $excel_stock - $articulo_ya_creado->stock;
                }

            } else {

                return $excel_stock;
            }
            
        }

        return null;
    }


    private function obtener_descuentos($row) {

        $discounts_data = [];
        
        $excel_descuentos = ImportHelper::getColumnValue($row, 'descuentos', $this->columns);
        

        if (ImportHelper::usa_columna($excel_descuentos)) {
            // Log::info('excel_descuentos article num: '.$row[0].':');
            // Log::info($excel_descuentos);

            $_discounts = explode('_', $excel_descuentos);
            
            
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts_data[] = $discount;
            } 
            
            // ArticlePricesHelper::adjuntar_descuentos($this->articulo_existente, $discounts);

        }

        return $discounts_data;

    }


    private function obtener_price_types($row, $articulo_ya_creado = null) {
        $price_types_data = [];

        if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios', $this->user)) {

            foreach ($this->price_types as $price_type) {
            
                $row_name = '%_' . str_replace(' ', '_', strtolower($price_type->name));
                $percentage = ImportHelper::getColumnValue($row, $row_name, $this->columns);


                /*
                    * Si es de un articulo ya creado, busco dentro de sus price_types
                    * Si ya esta relacionado con ese price_type, chequeo si cambio el %
                        si cambio lo agrego, sino no.
                    * Si aun no esta relacionado, lo agrego 
                */

                if ($articulo_ya_creado) {

                    $price_type_ya_relacionado = null;

                    foreach ($articulo_ya_creado->price_types as $article_price_type) {

                        if ($article_price_type->id == $price_type->id) {

                            $price_type_ya_relacionado = $article_price_type;
                        }
                    }

                    if ($price_type_ya_relacionado) {

                        if ($price_type_ya_relacionado->pivot->percentage != $percentage) {
                            
                            // Log::info('Hubo cambios en price_type ya creado '.$price_type->name.' para article '.$articulo_ya_creado->id);

                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $percentage);

                        }
                    } else {

                        $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $percentage);
                    }

                } else {

                    $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $percentage);
                    // $price_types_data[] = [
                    //     'id'            => $price_type->id,
                    //     'pivot'         => [
                    //         'percentage'    => $percentage,
                    //     ]
                    // ];
                }

            }
        }

        return $price_types_data;
    }

    function add_price_type_data($price_types_data, $price_type, $percentage) {

        $price_types_data[] = [
            'id'            => $price_type->id,
            'pivot'         => [
                'percentage'    => $percentage,
            ]
        ];
        return $price_types_data;
    }


    /**
     * Devuelve el ID del proveedor.
     * Si se especificó uno globalmente, lo devuelve; si no, lo busca por nombre desde la fila.
     */
    function get_provider_id($row) {

        if ($this->provider_id != 0) {
            return $this->provider_id;
        }

        $nombreProveedor = ImportHelper::getColumnValue($row, 'proveedor', $this->columns);

        if ($nombreProveedor && isset($this->nombres_proveedores[$nombreProveedor])) {
            $proveedor = $this->nombres_proveedores[$nombreProveedor];
            return $proveedor->id;
        }
    }

    /**
     * Devuelve el ID del IVA a partir del valor textual en la columna "iva"
     */
    function get_iva_id($row) {
        $iva_excel = ImportHelper::getColumnValue($row, 'iva', $this->columns);
        $iva_id = LocalImportHelper::getIvaId($iva_excel);
        return $iva_id;
    }

    /**
     * Devuelve el ID de la Categoria a partir del valor textual en la columna "Categoria"
     */
    function get_category_id($row) {

        $category_excel = ImportHelper::getColumnValue($row, 'categoria', $this->columns);

        $category_id = null;
        $sub_category_id = null;

        // Si hay valor en la columna categoría, se obtiene el ID de categoría y subcategoría
        if (ImportHelper::usa_columna($category_excel)) {
            $category_id = LocalImportHelper::getCategoryId($category_excel, $this->ct, $this->user);

            $sub_category_excel = ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns);

            $sub_category_id = LocalImportHelper::getSubcategoryId($category_excel, $sub_category_excel, $this->ct, $this->user);
        }

        return [
            'category_id'       => $category_id,
            'sub_category_id'   => $sub_category_id,
        ];

    }

    /**
     * Devuelve los artículos detectados para actualizar
     */
    function getArticulosParaActualizar() {
        return $this->articulosParaActualizar;
    }

    /**
     * Devuelve los artículos detectados para crear
     */
    function getArticulosParaCrear() {
        return $this->articulosParaCrear;
    }

    function set_price_types() {
        $this->price_types = PriceType::where('user_id', $this->user->id)
                                        ->orderBy('position', 'ASC')
                                        ->get();
    }
}
