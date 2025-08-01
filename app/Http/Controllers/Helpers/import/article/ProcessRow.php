<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\Address;
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
        $this->create_and_edit = $data['create_and_edit'];
        $this->no_actualizar_articulos_de_otro_proveedor = $data['no_actualizar_articulos_de_otro_proveedor'];

        $this->set_price_types();
        $this->set_addresses();
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


        /* 
            Si el articulo ya estaba previamente en una fila del excel, 
            se omite para no sobreescribirlo
        */
        $ya_estaba_en_excel = $this->ya_estaba_en_el_excel($data);

        if ($ya_estaba_en_excel) {
            Log::info('SE OMITIO EN PROCES ROW');
            return;
        }

        $articulo_ya_creado = ArticleIndexCache::find($data, $this->user->id);

        if ($articulo_ya_creado) {

            if (
                !is_null($articulo_ya_creado->provider_id)
                && !is_null($provider_id)
                && $this->no_actualizar_articulos_de_otro_proveedor
                && $articulo_ya_creado->provider_id != $provider_id
            ) {
                Log::info('El articulo '.$articulo_ya_creado->name.' ya pertenecia al proveedor id '.$articulo_ya_creado->provider_id);
                return;
            }

            // Comparar propiedades y obtener las que cambiaron
            $cambios = $this->getModifiedFields($articulo_ya_creado, $data);

            $price_types_data = $this->obtener_price_types($row, $articulo_ya_creado);

            $discounts_data_percentage = $this->obtener_descuentos_percentage($row);
            $discounts_data_amount = $this->obtener_descuentos_amount($row);

            $surchages_data_percentage = $this->obtener_recargos_percentage($row);
            $surchages_data_amount = $this->obtener_recargos_amount($row);
            

            $stock = $this->obtener_stock($row, $articulo_ya_creado);

            if (count($price_types_data) > 0) {
                $cambios['price_types_data'] = $price_types_data;
            }

            if (count($discounts_data_percentage) > 0) {
                Log::info('Agregando los discounts_data_percentage para el article id: '.$articulo_ya_creado->id);
                Log::info($discounts_data_percentage);
                $cambios['discounts_data_percentage'] = $discounts_data_percentage;
            }
            if (count($discounts_data_amount) > 0) {
                Log::info('Agregando los discounts_data_amount para el article id: '.$articulo_ya_creado->id);
                Log::info($discounts_data_amount);
                $cambios['discounts_data_amount'] = $discounts_data_amount;
            }

            if (count($surchages_data_percentage) > 0) {
                Log::info('Agregando los surchages_data_percentage para el article id: '.$articulo_ya_creado->id);
                Log::info($surchages_data_percentage);
                $cambios['surchages_data_percentage'] = $surchages_data_percentage;
            }
            if (count($surchages_data_amount) > 0) {
                Log::info('Agregando los surchages_data_amount para el article id: '.$articulo_ya_creado->id);
                Log::info($surchages_data_amount);
                $cambios['surchages_data_amount'] = $surchages_data_amount;
            }

            if (!is_null($stock['stock_global'])) {
                $cambios['stock_global'] = $stock['stock_global'];
            } else if (count($stock['stock_addresses']) > 0) {
                $cambios['stock_addresses'] = $stock['stock_addresses'];
            }

            // Log::info('Cambios:');
            // Log::info($cambios);

            if (!empty($cambios)) {

                $cambios['id'] = $articulo_ya_creado->id;

                $this->articulosParaActualizar[] = $cambios;
            } 

        } else if ($this->create_and_edit) {

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

            $discounts_data_percentage = $this->obtener_descuentos_percentage($row);
            $discounts_data_amount = $this->obtener_descuentos_amount($row);

            $surchages_data_percentage = $this->obtener_recargos_percentage($row);
            $surchages_data_amount = $this->obtener_recargos_amount($row);

            if (count($discounts_data_percentage) > 0) {
                $data['discounts_data_percentage'] = $discounts_data_percentage;
            }
            if (count($discounts_data_amount) > 0) {
                $data['discounts_data_amount'] = $discounts_data_amount;
            }

            if (count($surchages_data_percentage) > 0) {
                $data['surchages_data_percentage'] = $surchages_data_percentage;
            }
            if (count($surchages_data_amount) > 0) {
                $data['surchages_data_amount'] = $surchages_data_amount;
            }


            $stock = $this->obtener_stock($row);

            if (!is_null($stock['stock_global'])) {
                $data['stock_global'] = $stock['stock_global'];
            } else if (count($stock['stock_addresses']) > 0) {
                $data['stock_addresses'] = $stock['stock_addresses'];
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
        $key = $data['id'] ?? $data['bar_code'] ?? $data['provider_code'] ?? $data['name'];


        if ($key) {
            $ya_en_para_crear = false;
            $ya_en_para_actualizar = false;

            foreach ($this->articulosParaCrear as $index => $art) {
                if (
                    (!empty($art['id']) && $art['id'] === $data['id']) 
                    || (!empty($art['bar_code']) && $art['bar_code'] === $data['bar_code']) 
                    || (!env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false) && !empty($art['provider_code']) && $art['provider_code'] === $data['provider_code'])
                    || (!empty($art['name']) && $art['name'] === $data['name'])
                ) {
                    // $this->articulosParaCrear[$index] = $data;
                    $ya_en_para_crear = true;
                    break;
                }
            }

            if (!$ya_en_para_crear) {
                foreach ($this->articulosParaActualizar as $index => $art) {
                    if (
                        (!empty($art['id']) && $art['id'] === $data['id']) 
                        || (!empty($art['bar_code']) && $art['bar_code'] === $data['bar_code']) 
                        || (!env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false) && !empty($art['provider_code']) && $art['provider_code'] === $data['provider_code']) 
                        || (!empty($art['name']) && $art['name'] === $data['name'])
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
            if (
                $existing->$key != $value
                && !is_null($value)
            ) {
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

        $stock_global = null;
        $stock_addresses = [];

        $indico_stock_en_addresses = Self::hay_stock_indicado_en_columnas_addresses($row);

        if (
            (
                ImportHelper::usa_columna($excel_stock)
                && is_numeric($excel_stock)
            )
            || $indico_stock_en_addresses
        ) {

            if ($articulo_ya_creado) {

                if ($indico_stock_en_addresses) {

                    $stock_addresses = $this->obtener_stock_addresses($row, $articulo_ya_creado);
                
                } else {

                    if ($articulo_ya_creado->stock != $excel_stock) {
                        $stock_global = $excel_stock - $articulo_ya_creado->stock;
                    }
                }

            } else {

                if ($indico_stock_en_addresses) {
                    $stock_addresses = $this->obtener_stock_addresses($row);
                } else {
                    $stock_global = $excel_stock;
                }
            }
            
        }

        return [
            'stock_global'      => $stock_global,
            'stock_addresses'   => $stock_addresses,
        ];
    }

    function hay_stock_indicado_en_columnas_addresses($row) {

        foreach ($this->addresses as $address) {
            $nombre_columna = str_replace(' ', '_', strtolower($address->street));

            $address_excel = ImportHelper::getColumnValue($row, $nombre_columna, $this->columns);

            if (!is_null($address_excel)) {
                return true;
            }
        }   
    }

    private function obtener_stock_addresses($row, $articulo_ya_creado = null) {
        $set_stock_from_addresses = false;

        $segundos_para_agregar = 5;

        $stock_addresses = [];

        foreach ($this->addresses as $address) {
            $nombre_columna = str_replace(' ', '_', strtolower($address->street));

            $address_excel = ImportHelper::getColumnValue($row, $nombre_columna, $this->columns);

            if (!is_null($address_excel)) {

                Log::info('Hay info en la columna '.$nombre_columna);

                if (!is_null($articulo_ya_creado)) {

                    $article_address = $articulo_ya_creado->addresses()->where('address_id', $address->id)->first();
                    if ($article_address) {
                        $stock_actual_en_address = $article_address->pivot->amount;
                    } else {
                        $stock_actual_en_address = 0;
                    }
                
                } else {
                    $stock_actual_en_address = 0;
                }

                $address_excel = (float)$address_excel;

                $diferencia = $address_excel - $stock_actual_en_address;

                if ($diferencia != 0) {
                    Log::info('Hay una diferencia de '.$diferencia);
                    $stock_addresses[] = [
                        'address_id'    => $address->id,
                        'amount'        => $diferencia,
                    ];
                }
            }
        }

        return $stock_addresses;
        // if ($set_stock_from_addresses) {
        //     ArticleHelper::setArticleStockFromAddresses($this->articulo_existente, false);
        // }
    }


    private function obtener_descuentos_percentage($row) {

        $discounts_data = [];
        
        $excel_descuentos = ImportHelper::getColumnValue($row, 'descuentos', $this->columns);
        
        if (ImportHelper::usa_columna($excel_descuentos)) {

            $_discounts = explode('_', $excel_descuentos);
            
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts_data[] = $discount;
            } 

        }

        return $discounts_data;
    }


    private function obtener_descuentos_amount($row) {

        $discounts_data = [];
        
        $excel_descuentos = ImportHelper::getColumnValue($row, 'descuentos_montos', $this->columns);
        
        if (ImportHelper::usa_columna($excel_descuentos)) {

            $_discounts = explode('_', $excel_descuentos);
            
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->amount = $_discount;
                $discounts_data[] = $discount;
            } 

        }

        return $discounts_data;
    }


    private function obtener_recargos_percentage($row) {

        $surchages_data = [];
        
        $excel_recargos = ImportHelper::getColumnValue($row, 'recargos', $this->columns);
        
        if (ImportHelper::usa_columna($excel_recargos)) {

            $_surchages = explode('_', $excel_recargos);
            
            foreach ($_surchages as $_surchage) {
                $surchage = new \stdClass;

                $surchage->luego_del_precio_final = 0;
                if (substr($_surchage, 0, 1) == 'F') {
                    $_surchage = substr($_surchage, 1);
                    $surchage->luego_del_precio_final = 1;
                }

                $surchage->percentage = $_surchage;
                $surchages_data[] = $surchage;
            } 

        }

        return $surchages_data;
    }


    private function obtener_recargos_amount($row) {

        $surchages_data = [];
        
        $excel_recargos = ImportHelper::getColumnValue($row, 'recargos_montos', $this->columns);
        
        if (ImportHelper::usa_columna($excel_recargos)) {

            $_surchages = explode('_', $excel_recargos);
            
            foreach ($_surchages as $_surchage) {
                $surchage = new \stdClass;

                $surchage->luego_del_precio_final = 0;
                
                if (substr($_surchage, 0, 1) == 'F') {
                    $_surchage = substr($_surchage, 1);
                    $surchage->luego_del_precio_final = 1;
                } 

                $surchage->amount = $_surchage;
                $surchages_data[] = $surchage;
            } 

        }

        return $surchages_data;
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

        return null;
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

    function set_addresses() {
        $this->addresses = Address::where('user_id', $this->user->id)
                                        ->get();
    }
}
