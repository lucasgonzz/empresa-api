<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\Address;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;

use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;

class ProcessRow {

    protected $columns;
    protected $user;
    protected $ct;
    protected $provider_id;
    protected $articulosParaActualizar = [];
    protected $articulosParaCrear = [];
    protected $price_types = [];
    protected $property_types = [];


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
        $this->set_property_types();
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
        $aplicar_iva = $this->get_aplicar_iva($row);

        $brand_id = $this->get_brand_id($row);

        $cost = Self::get_number(ImportHelper::getColumnValue($row, 'costo', $this->columns));
        $price = Self::get_number(ImportHelper::getColumnValue($row, 'precio', $this->columns));
        $percentage_gain = Self::get_number(ImportHelper::getColumnValue($row, 'margen_de_ganancia', $this->columns), 2);

        // Construir array de datos del artículo usando los valores extraídos del Excel
        $data = [
            'id'                   => ImportHelper::getColumnValue($row, 'numero', $this->columns),
            'bar_code'             => ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns),
            'provider_code'        => ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns),
            'name'                 => ImportHelper::getColumnValue($row, 'nombre', $this->columns),
            'stock_min'            => ImportHelper::getColumnValue($row, 'stock_minimo', $this->columns),
            'cost'                 => $cost,
            'percentage_gain'      => $percentage_gain,
            'price'                => $price,
            'unidades_individuales'=> ImportHelper::getColumnValue($row, 'u_individuales', $this->columns),
            'cost_in_dollars'      => $this->get_cost_in_dollars($row),
            'category_id'          => $category_id,
            'sub_category_id'      => $sub_category_id,
            'provider_id'          => $provider_id,
            'iva_id'               => $iva_id,
            'aplicar_iva'          => $aplicar_iva,
            'brand_id'             => $brand_id,
            'user_id'              => $this->user->id,

        ];

        if (UserHelper::hasExtencion('autopartes', $this->user)) {
            $data_autopartes = [
                'espesor'               => ImportHelper::getColumnValue($row, 'espesor', $this->columns),
                'modelo'                => ImportHelper::getColumnValue($row, 'modelo', $this->columns),
                'pastilla'              => ImportHelper::getColumnValue($row, 'pastilla', $this->columns),
                'diametro'              => ImportHelper::getColumnValue($row, 'diametro', $this->columns),
                'litros'                => ImportHelper::getColumnValue($row, 'litros', $this->columns),
                'descripcion'           => ImportHelper::getColumnValue($row, 'descripcion', $this->columns),
                'contenido'             => ImportHelper::getColumnValue($row, 'contenido', $this->columns),
                'cm3'                   => ImportHelper::getColumnValue($row, 'cm3', $this->columns),
                'calipers'              => ImportHelper::getColumnValue($row, 'calipers', $this->columns),
                'juego'                 => ImportHelper::getColumnValue($row, 'juego', $this->columns),
            ];

            $data = array_merge($data, $data_autopartes);
        }




        /* 
            Si el articulo ya estaba previamente en una fila del excel, 
            se omite para no sobreescribirlo
        */
        $ya_estaba_en_excel = $this->ya_estaba_en_el_excel($data);

        if ($ya_estaba_en_excel) {

            // 👇 Nuevo: si esta fila forma parte del mismo producto y tiene propiedades -> agregar como variante
            $variant_payload = $this->build_variant_payload($row);

            if (!is_null($variant_payload)) {

                $this->attach_variant_to_existing_article($data, $variant_payload);
                Log::info('Fila repetida tratada como VARIANTE del artículo base');
                return;
            }
            Log::info('SE OMITIO EN PROCES ROW (fila repetida sin propiedades de variante)');
            return;
        } else {
            Log::info('No esta aun en el excel');
        }




        $codigos_repetidos = filter_var(env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false), FILTER_VALIDATE_BOOLEAN);
        if ($codigos_repetidos && !empty($data['provider_code'])) {


            // Aca entra para actualizar todas las coincidencias del producto en base al codigo de proveedor, caso SAN BLAS

            $articulos = ArticleIndexCache::find_all_by_provider_code($data['provider_code'], $this->user->id);
            Log::info('find_all_by_provider_code:');
            Log::info($articulos);

            foreach ($articulos as $articulo_ya_creado) {

                if (
                    !is_null($articulo_ya_creado->provider_id)
                    && !is_null($provider_id)
                    && $this->no_actualizar_articulos_de_otro_proveedor
                    && $articulo_ya_creado->provider_id != $provider_id
                ) {
                    Log::info('El articulo '.$articulo_ya_creado->name.' ya pertenecia al proveedor id '.$articulo_ya_creado->provider_id);
                    continue;
                }

                $cambios = $this->getModifiedFields($articulo_ya_creado, $data);
                $cambios['id'] = $articulo_ya_creado->id;
                $cambios['variants_data'] = [];

                $this->articulosParaActualizar[] = $cambios;
            }

        } else {

            $articulo_ya_creado = ArticleIndexCache::find($data, $this->user->id);

            if ($articulo_ya_creado) {

                Log::info('Articulo ya creado');

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

                    $cambios['variants_data'] = []; // 👈

                    $this->articulosParaActualizar[] = $cambios;
                } 

            } else if ($this->create_and_edit) {

                Log::info('El articulo NO existia');
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

                $data['variants_data'] = []; // 👈 espacio para variantes
                $this->articulosParaCrear[] = $data;

                // Lo agregamos al índice para evitar procesarlo duplicado en siguientes filas
                $fakeArticle = new \App\Models\Article($data);
                // $num = $this->ct->num('articles', $this->user->id);
                $fakeArticle->id = 'fake_' . uniqid(); // ID temporal único

                ArticleIndexCache::add($fakeArticle);
            }
        }

    }

    function get_cost_in_dollars($row) {
        $cost_in_dollars = 0;

        $moneda = ImportHelper::getColumnValue($row, 'moneda', $this->columns);
        
        if (
            $moneda == 'USD'
            || $moneda == 'usd'
        ) {
            Log::info('Costo en dolares');
            $cost_in_dollars = 1;
        }
        return $cost_in_dollars;
    }


    function ya_estaba_en_el_excel($data) {

        // Verificamos si ya existe un artículo con este identificador en el mismo archivo
        $key = $data['id'] ?? $data['bar_code'] ?? $data['provider_code'] ?? $data['name'];


        if ($key) {

            $ya_en_para_crear = false;
            $ya_en_para_actualizar = false;

            foreach ($this->articulosParaCrear as $index => $art) {

                if ($this->esta_repetido($data, $art)) {
                    $ya_en_para_crear = true;
                    break;
                }
            }

            if (!$ya_en_para_crear) {
                // Log::info('Se va a chequear si ya esta para actualizar dentro de '.count($this->articulosParaActualizar).' articulosParaActualizar');
                foreach ($this->articulosParaActualizar as $index => $art) {

                    if ($this->esta_repetido($data, $art)) {
                        
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

    function esta_repetido($data, $art) {

        $repetido = false;

        // Aseguramos boolean real por si el .env viene como string
        $codigos_repetidos = filter_var(env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false), FILTER_VALIDATE_BOOLEAN);

        // 1) Coincidencia por ID
        if (!empty($data['id'])) {

            if (isset($art['id']) && $art['id'] === $data['id']) {
                Log::info('Ya esta para crear, id: '.$art['id'].' = '.$data['id']);
                return true;
            }
            return false;
        }

        // 2) Coincidencia por bar_code
        if (!empty($data['bar_code'])) {

            if (isset($art['bar_code']) && $art['bar_code'] === $data['bar_code']) {
                Log::info('Ya esta para crear, bar_code: '.$art['bar_code'].' = '.$data['bar_code']);
                return true;
            }
            return false;
        }

        // 3) Coincidencia por provider_code (solo si NO se permiten repetidos)
        if (!empty($data['provider_code']) && !$codigos_repetidos) {

            if (!empty($art['provider_code']) && $art['provider_code'] === $data['provider_code']) {
                Log::info('Ya esta para crear, provider_code: '.$art['provider_code'].' = '.$data['provider_code']);
                return true;
            }
            return false;
        }

        // 4) Coincidencia por name
        if (!empty($data['name'])) {

            if (!empty($art['name']) && $art['name'] === $data['name']) {

                // --- REGLA NUEVA ---
                // Si se permiten codigos de proveedor repetidos, SOLO marcamos repetido
                // cuando el provider_code también coincide (si ambos existen).
                if ($codigos_repetidos) {

                    // Si ambos tienen provider_code y SON IGUALES => repetido = true
                    if (!empty($data['provider_code']) && !empty($art['provider_code'])) {
                        if ($art['provider_code'] === $data['provider_code']) {
                            Log::info('Ya esta para crear, name+provider_code: '.$art['name'].' / '.$art['provider_code'].' = '.$data['name'].' / '.$data['provider_code']);
                            return true;
                        } else {
                            // Mismo nombre pero distinto provider_code => NO repetido
                            Log::info('Mismo name pero distinto provider_code con repetidos habilitados: '.$art['name'].' / '.$art['provider_code'].' != '.$data['name'].' / '.$data['provider_code']);
                            return false;
                        }
                    }

                    // Si falta alguno de los provider_code, no podemos garantizar que no esté repetido.
                    // Por seguridad, consideramos repetido (conservador).
                    Log::info('Name coincide pero falta provider_code para contrastar con repetidos habilitados. Se marca como repetido por seguridad: '.$art['name'].' = '.$data['name']);
                    return true;

                } else {
                    // Si NO se permiten repetidos de provider_code, con que coincida el nombre basta.
                    Log::info('Ya esta para crear, name: '.$art['name'].' = '.$data['name']);
                    return true;
                }
            }

            return false;
        }

        return $repetido;
    }



    /**
     * Compara un artículo existente con nuevos datos, y devuelve
     * solo las propiedades que han cambiado.
     */
    function getModifiedFields($existing, array $data): array
    {
        $modified = [];

        foreach ($data as $key => $value) {
            $modified[$key] = $value;
        }

        // Antes solo agrego las propiedades que cambiaron, lo cambio para agregar todas las propiedades
        // foreach ($data as $key => $value) {
        //     if (
        //         $existing->$key != $value
        //         && !is_null($value)
        //     ) {
        //         $modified[$key] = $value;
        //     }
        // }

        return $modified;
    }

    static function get_number($number, $decimales = 2) {

        if (is_null($number) || $number == '') {
            return null;
        }

        $original = $number;

        // 1. Reemplazar la coma por punto
        $normalized = str_replace(',', '.', $original);

        // 2. Convertir a float y limitar a 4 decimales
        $limited = number_format((float)$normalized, $decimales, '.', '');

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

                // Log::info('Hay info en la columna '.$nombre_columna);

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
            
                $row_percentage_name = '%_' . str_replace(' ', '_', strtolower($price_type->name));
                $percentage = ImportHelper::getColumnValue($row, $row_percentage_name, $this->columns);
            
                $row_final_price_name = '$_final_' . str_replace(' ', '_', strtolower($price_type->name));
                $final_price = ImportHelper::getColumnValue($row, $row_final_price_name, $this->columns);

                Log::info('final_price: '.$final_price);


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

                        Log::info('YA estaba relacionado con price_type');
                        
                        if (
                            $price_type_ya_relacionado->pivot->percentage != $percentage
                            && !$price_type_ya_relacionado->pivot->setear_precio_final
                        ) {
                            Log::info('Entro con percentage');    
                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $percentage);

                        } else if (
                            $price_type_ya_relacionado->pivot->final_price != $final_price
                            && $price_type_ya_relacionado->pivot->setear_precio_final
                        ) {

                            Log::info('Entro con final_price');    
                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, null, $final_price);

                        } else {

                            Log::info('No entro con ninguno');    
                        }

                    } else {

                        Log::info('No estaba relacionado con price_type');

                        $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $percentage, $final_price);

                    }

                } else {

                    $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $percentage, $final_price);
                }

            }
        }

        return $price_types_data;
    }

    function add_price_type_data($price_types_data, $price_type, $percentage, $final_price = null) {

        $price_types_data[] = [
            'id'            => $price_type->id,
            'pivot'         => [
                'percentage'    => !is_null($percentage) ? $percentage : null,
                'final_price'   => !is_null($final_price) ? $final_price : null,
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


    function get_aplicar_iva($row) {
        $aplicar_iva = 1;

        $iva_excel = ImportHelper::getColumnValue($row, 'aplicar_iva', $this->columns);
        Log::info('get_aplicar_iva: '.$iva_excel);
        if (
            $iva_excel == 'No'
            || $iva_excel == 'no'
            || $iva_excel == 'N'
            || $iva_excel == 'n'
        ) {
            $aplicar_iva = 0;
        }
        return $aplicar_iva;
    }


    /**
     * Devuelve el ID del IVA a partir del valor textual en la columna "iva"
     */
    function get_brand_id($row) {
        $brand_excel = ImportHelper::getColumnValue($row, 'marca', $this->columns);

        $brand_id = LocalImportHelper::get_bran_id($brand_excel, $this->ct, $this->user);

        // Log::info('brand_id para article num: '.$row[0].' = '.$brand_id);

        return $brand_id;
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



    // Variantes de los productos

    function set_property_types() {
        // Globales (no por user), según tus migrations actuales
        $this->property_types = ArticlePropertyType::orderBy('id', 'ASC')->get();
    }

    function row_property_values($row) : array {
        $props = [];
        foreach ($this->property_types as $type) {
            $key = mb_strtolower(trim($type->name));
            $val = ImportHelper::getColumnValue($row, $key, $this->columns);
            if (!is_null($val) && trim((string)$val) !== '') {
                $props[$key] = trim((string)$val);
            }
        }
        return $props; // ej: ['color' => 'Rojo', 'talle' => '42']
    }

    /**
     * Devuelve null si la fila NO tiene propiedades; si tiene, arma el payload de variante
     */
    function build_variant_payload($row) : ?array {
        $props = $this->row_property_values($row);
        if (count($props) === 0) return null;

        // Campos de variante opcionales si vienen en la fila

        $variant_price = self::get_number(ImportHelper::getColumnValue($row, 'precio', $this->columns));
        $variant_stock = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);
        $image_url     = ImportHelper::getColumnValue($row, 'imagen', $this->columns); // si mapeás una columna 'imagen'
        $sku           = ImportHelper::getColumnValue($row, 'sku', $this->columns);
        
        // 👇 NUEVO: extraer stocks por address desde columnas stock_*
        $address_stocks = $this->extract_address_stocks($row);
        $address_display = $this->extract_address_display_by_street($row);
        
        return [
            'properties' => $props,                  // ['color'=>'Rojo','talle'=>'42', ...]
            'price'      => $variant_price ?? null,  // num o null
            'stock'      => is_null($variant_stock) ? null : (int)$variant_stock,
            'image_url'  => $image_url ?? null,
            'sku'        => $sku ?? null,
            'address_stocks' => $address_stocks, 
            'address_display'=> $address_display,  
        ];
    }

    protected function extract_address_display_by_street($row): array
    {
        // Devuelve [address_id => bool]
        $display = [];

        foreach ($this->addresses as $address) {

            $nombre_columna = str_replace(' ', '_', strtolower('Exhibicion '.$address->street));

            $exhibicion_excel = ImportHelper::getColumnValue($row, $nombre_columna, $this->columns);

            if (!is_null($exhibicion_excel)) {


                $truthy = ['si','sí','true','1','x','ok','s','y','yes'];
                $on_display = in_array($exhibicion_excel, $truthy, true);

                $display[$address->id] = $on_display;
            }
        }

        return $display;
    }


    /**
     * Lee todas las columnas que empiecen con stock_ y arma:
     *   ['address_key' => amount, ...]
     * address_key puede ser id (número), code, o nombre normalizado.
     */
    function extract_address_stocks($row) : array {
        $stocks = [];

        foreach ($this->addresses as $address) {


            $nombre_columna = str_replace(' ', '_', strtolower($address->street));

            $address_excel = ImportHelper::getColumnValue($row, $nombre_columna, $this->columns);

            if (!is_null($address_excel)) {

                // normalizamos cantidad a int >= 0
                $amount = (int) round((float) str_replace(',', '.', (string)$address_excel));
                if ($amount < 0) $amount = 0;

                $stocks[$address->id] = $amount;

            }

        }

        return $stocks;
    }

    function attach_variant_to_existing_article($data, $variant_payload) : void {
        // Buscamos el artículo correspondiente en los arrays cacheados
        // Reutilizamos tu lógica de comparación con esta_repetido()
        foreach (['articulosParaCrear', 'articulosParaActualizar'] as $bucket) {

            foreach ($this->{$bucket} as $i => $art) {

                if ($this->esta_repetido($data, $art)) {

                    if (!isset($this->{$bucket}[$i]['variants_data']) || !is_array($this->{$bucket}[$i]['variants_data'])) {
                        $this->{$bucket}[$i]['variants_data'] = [];
                    }

                    $this->{$bucket}[$i]['variants_data'][] = $variant_payload;

                    return;
                }
            }
        }
        // Si no lo encontramos (raro), no rompemos el flujo
        Log::warning('No se encontró artículo base para adjuntar variante en cache');
    }
}
