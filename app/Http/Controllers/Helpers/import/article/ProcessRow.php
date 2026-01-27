<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ImportChangeRecorder;
use App\Models\Address;
use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use Illuminate\Support\Collection;
use App\Models\PriceType;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\Log;

class ProcessRow {

    protected $columns;
    protected $user;
    protected $ct;
    protected $provider_id;
    protected $articles_match = 0;
    protected $articulosParaActualizar = [];
    protected $articulosParaCrear = [];
    protected $price_types = [];
    protected $property_types = [];
    protected $unidad_medidas = [];
    protected $se_importaron_price_types = false;



    /**
     * Constructor: recibe los datos necesarios para procesar las filas
     */
    function __construct($data) {
        $this->columns                  = $data['columns'];
        $this->user                     = $data['user'];
        $this->ct                       = $data['ct'];
        $this->provider_id              = $data['provider_id'];
        $this->create_and_edit          = $data['create_and_edit'];
        $this->no_actualizar_articulos_de_otro_proveedor = $data['no_actualizar_articulos_de_otro_proveedor'];
        $this->actualizar_proveedor = $data['actualizar_proveedor'];

        $this->import_history_id = $data['import_history_id'] ?? null;
        $this->import_uuid = $data['import_uuid'] ?? null;

        $this->set_price_types();
        $this->set_addresses();
        $this->set_property_types();
        $this->set_unidad_medidas();
        $this->set_se_importaron_price_types();
    }

    function set_se_importaron_price_types() {
                
        foreach ($this->price_types as $price_type) {

            $row_setear_name = $this->get_price_type_row_name('setear_precio_final_', $price_type);

            $row_percentage_name = $this->get_price_type_row_name('%_', $price_type);
        
            $row_final_price_name = $this->get_price_type_row_name('$_final_', $price_type);
            
            if (
                !ImportHelper::isIgnoredColumn($row_setear_name, $this->columns)
                || !ImportHelper::isIgnoredColumn($row_percentage_name, $this->columns)
                || !ImportHelper::isIgnoredColumn($row_final_price_name, $this->columns)
            ) {
                $this->se_importaron_price_types = true;
            }
        }
            

    }



    /**
     * Procesa una fila del Excel: busca si el artículo ya existe, y lo actualiza o lo agrega.
     */
    function procesar($row, $nombres_proveedores) {

        $this->nombres_proveedores = $nombres_proveedores;

        $props_to_add = [
            [
                'excel_column'  => 'numero',
                'prop_key'      => 'id',
            ],
            [
                'excel_column'  => 'codigo_de_barras',
                'prop_key'      => 'bar_code',
            ],
            [
                'excel_column'  => 'sku',
                'prop_key'      => 'sku',
            ],
            [
                'excel_column'  => 'codigo_de_proveedor',
                'prop_key'      => 'provider_code',
            ],
            [
                'excel_column'  => 'nombre',
                'prop_key'      => 'name',
            ],
            [
                'excel_column'  => 'stock_minimo',
                'prop_key'      => 'stock_min',
                'is_number'     => true,
            ],
            [
                'excel_column'  => 'costo',
                'prop_key'      => 'cost',
                'is_number'     => true,
            ],
            [
                'excel_column'  => 'margen_de_ganancia',
                'prop_key'      => 'percentage_gain',
                'is_number'     => true,
            ],
            [
                'excel_column'  => 'precio',
                'prop_key'      => 'price',
                'is_number'     => true,
            ],
            [
                'excel_column'  => 'u_individuales',
                'prop_key'      => 'unidades_individuales',
            ],
            [
                'excel_column'  => 'descripcion',
                'prop_key'      => 'descripcion',
            ],
            [
                'excel_column'  => 'descripcion',
                'prop_key'      => 'descripcion',
            ],
        ];
        
        $provider_id = $this->get_provider_id($row);

        // Construir array de datos del artículo usando los valores extraídos del Excel
        $data = [
            'provider_id'          => $provider_id,
            'user_id'              => $this->user->id,
        ];


        foreach ($props_to_add as $prop_to_add) {

            if (!ImportHelper::isIgnoredColumn($prop_to_add['excel_column'], $this->columns)) {

                $excel_value = ImportHelper::getColumnValue($row, $prop_to_add['excel_column'], $this->columns);

                if (isset($prop_to_add['is_number'])) {
                    $excel_value = Self::get_number($excel_value);
                }

                $data[$prop_to_add['prop_key']] = $excel_value;
            
            } else {
                // Log::info('Columna ignorada '.$prop_to_add['excel_column']);
            }

        }


        $iva_id = $this->get_iva_id($row);
        $data['iva_id'] = $iva_id;



        // Categoria y Sub categoria
        $res = $this->get_category_id($row);

        $category_id = $res['category_id'];
        $sub_category_id = $res['sub_category_id'];

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['category_id'] = $category_id;
        }
        if (!ImportHelper::isIgnoredColumn('sub_categoria', $this->columns)) {
            $data['sub_category_id'] = $sub_category_id;
        }





        if (!ImportHelper::isIgnoredColumn('moneda', $this->columns)) {
            $data['cost_in_dollars'] = $this->get_cost_in_dollars($row);
        }

        if (!ImportHelper::isIgnoredColumn('aplicar_iva', $this->columns)) {
            $data['aplicar_iva'] = $this->get_aplicar_iva($row);
        }

        if (!ImportHelper::isIgnoredColumn('marca', $this->columns)) {
            $brand_id = ImportHelper::getColumnValue($row, 'marca', $this->columns);
            $data['brand_id'] = $this->get_brand_id($row);
        }

        if (!ImportHelper::isIgnoredColumn('unidad_medida', $this->columns)) {
            $data['unidad_medida_id'] = $this->get_unidad_medida_id($row);
        }


        if (UserHelper::hasExtencion('autopartes', $this->user)) {
            $data_autopartes = [
                'espesor'               => ImportHelper::getColumnValue($row, 'espesor', $this->columns),
                'modelo'                => ImportHelper::getColumnValue($row, 'modelo', $this->columns),
                'pastilla'              => ImportHelper::getColumnValue($row, 'pastilla', $this->columns),
                'diametro'              => ImportHelper::getColumnValue($row, 'diametro', $this->columns),
                'litros'                => ImportHelper::getColumnValue($row, 'litros', $this->columns),
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



        $articulo_ya_creado = ArticleIndexCache::find($data, $this->user->id, $provider_id, $this->no_actualizar_articulos_de_otro_proveedor);

        if (
            !is_null($articulo_ya_creado)
            || $this->son_varios_articulos($articulo_ya_creado)
        ) {

            Log::info('Articulo ya creado');

            $this->attach_provider($articulo_ya_creado, $data, $provider_id);


            if ($this->son_varios_articulos($articulo_ya_creado)) {

                foreach ($articulo_ya_creado as $_articulo_ya_creado) {
                    $this->add_article_match();

                    if (!$this->omitir_por_pertencer_a_otro_proveedor($_articulo_ya_creado, $provider_id)) {

                        Log::info('procesando articulo con provider_code repetido:');
                        $this->procesar_articulo_ya_creado($_articulo_ya_creado, $data, $row);
                    }

                }
            } else {

                $this->add_article_match();
                if (!$this->omitir_por_pertencer_a_otro_proveedor($articulo_ya_creado, $provider_id)) {
                    $this->procesar_articulo_ya_creado($articulo_ya_creado, $data, $row);
                }
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

            
            $discounts_diff = $this->get_discounts_diff($articulo_ya_creado, $row);
            if (!empty($discounts_diff)) {
                $data['discounts'] = $discounts_diff;
            } 

            $surchages_diff = $this->get_surchages_diff($articulo_ya_creado, $row);
            if (!empty($surchages_diff)) {
                $data['surchages'] = $surchages_diff;
            }


            $stock = $this->obtener_stock($row);

            if (!is_null($stock['stock_global'])) {
                $data['stock_global'] = $stock['stock_global'];
            } else if (count($stock['stock_addresses']) > 0) {
                $data['stock_addresses'] = $stock['stock_addresses'];
            }

            $data['slug'] = ArticleHelper::slug($data['name']);

            $data['variants_data'] = []; // 👈 espacio para variantes

            $data['fake_id'] = 'fake_' . uniqid(); // ID temporal único

            // Log::info('data[id]: ');
            // Log::info($data['id']);

            $this->articulosParaCrear[] = $data;

            // Lo agregamos al índice para evitar procesarlo duplicado en siguientes filas
            $fakeArticle = new \App\Models\Article($data);
            // $num = $this->ct->num('articles', $this->user->id);


            // $fakeArticle->fake_id = $data['id'];

            ArticleIndexCache::add($fakeArticle);
        }

    }

    function omitir_por_pertencer_a_otro_proveedor($articulo_ya_creado, $provider_id) {

        if (
            !is_null($articulo_ya_creado->provider_id)
            && !is_null($provider_id)
            && $this->no_actualizar_articulos_de_otro_proveedor
            && $articulo_ya_creado->provider_id != $provider_id
        ) {
            return true;
        }
        return false;
    }

    function add_article_match() {
        $this->articles_match++;
        Log::info('articles_match: '.$this->articles_match);
    }

    function attach_provider($articulo_ya_creado, $data, $provider_id) {

        Log::info('attach_provider');

        if (
            !$provider_id
            || $this->son_varios_articulos($articulo_ya_creado)
        ) {

            foreach ($articulo_ya_creado as $article) {
                $this->update_provider_relation($article, $data, $provider_id);
            }
        } else {
            $this->update_provider_relation($articulo_ya_creado, $data, $provider_id);
        }

        // Log::info('articulo_ya_creado: ');
        // Log::info($articulo_ya_creado->toArray());
    }

    function son_varios_articulos($articulo_ya_creado) {
        return $articulo_ya_creado instanceof Collection;
    }

    function update_provider_relation($articulo_ya_creado, $data, $provider_id) {

        Log::info('update_provider_relation de '.$articulo_ya_creado->name);
        // Log::info($articulo_ya_creado->toArray());

        $pivot_data = [
            'provider_code' => isset($data['provider_code']) ? $data['provider_code']: null,
            'cost'          => isset($data['cost']) ? $data['cost'] : null,
        ];

        $existe_relacion = $articulo_ya_creado->providers()
                                ->where('provider_id', $provider_id)
                                ->exists();

        if ($existe_relacion) {

            Log::info('Ya estaba relacionado con el provider id '.$provider_id);
            // ✅ Actualizar pivot existente
            $articulo_ya_creado->providers()->updateExistingPivot($provider_id, $pivot_data);
        } else {
            // ✅ Crear pivot nuevo
            $articulo_ya_creado->providers()->attach($provider_id, $pivot_data);
        }
    }

    

    function procesar_articulo_ya_creado($articulo_ya_creado, $data, $row) {
        $articulo_ya_creado->loadMissing(['price_types', 'addresses']);

        // Comparar propiedades y obtener las que cambiaron
        $cambios = $this->get_modified_fields($articulo_ya_creado, $data);

        $price_types_data = $this->obtener_price_types($row, $articulo_ya_creado);
        $price_types_data = $this->filter_only_changed_price_types($articulo_ya_creado, $price_types_data);
        if (!empty($price_types_data)) {
            $cambios['price_types_data'] = $price_types_data;
        }

        
        $discounts_diff = $this->get_discounts_diff($articulo_ya_creado, $row);
        if (!empty($discounts_diff)) {
            $cambios['discounts'] = $discounts_diff;
        } 

        $surchages_diff = $this->get_surchages_diff($articulo_ya_creado, $row);
        if (!empty($surchages_diff)) {
            $cambios['surchages'] = $surchages_diff;
        }
        

        // if (count($price_types_data) > 0) {
        //     $cambios['price_types_data'] = $price_types_data;
        // }

        $stock_data = $this->obtener_stock($row, $articulo_ya_creado);

        // 🔎 Chequeamos si vino stock global y si cambió realmente
        if (isset($stock_data['stock_global'])) {
            $excel_stock = (float)$this->normalize_scalar($stock_data['stock_global']);
            $actual_stock = (float)$this->normalize_scalar($articulo_ya_creado->stock ?? 0);

            if ($excel_stock !== $actual_stock) {
                $cambios['stock_global'] = [
                    '__diff__stock' => [
                        'old' => $actual_stock,
                        'new' => $excel_stock,
                    ],
                ];
            }
        }

        // 🏬 Si vino stock por direcciones, limpiamos las diferencias cero
        if (isset($stock_data['stock_addresses']) && is_array($stock_data['stock_addresses'])) {
            $stock_changes = $this->purge_zero_stock_diffs($stock_data['stock_addresses'], $articulo_ya_creado);

            if (!empty($stock_changes)) {
                $cambios['stock_addresses'] = $stock_changes;
            }
        }


        if (!empty($cambios)) {

            Log::info('SI Hubo Cambios');

            $cambios['id'] = $articulo_ya_creado->id;

            // $cambios['variants_data'] = []; // 👈

            $this->articulosParaActualizar[] = $cambios;

            // if (!empty($cambios) && $this->import_history_id && isset($articulo_ya_creado->id)) {
            //     ImportChangeRecorder::logUpdated($this->import_history_id, $articulo_ya_creado->id, $cambios);
            // }
        }  else {
            Log::info('');
            Log::info('NO HUBO CAMBIOS');
        }

        // return $cambios;
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

    private function get_modified_fields($existing, array $data): array
    {
        $modified = [];

        foreach ($data as $key => $value) {
            // ignorar campos que no queremos comparar
            if (in_array($key, ['id', 'created_at', 'updated_at'])) continue;

            if (
                $key == 'provider_id'
                && !$this->actualizar_proveedor
            ) {
                Log::info('No se agrego provider_id porque actualizar_proveedor: '.$this->actualizar_proveedor);
                continue;
            }

            // Valor nuevo normalizado
            $new = $this->normalize_value_for_comparison($value);

            // Si el modelo no tiene esa propiedad, lo tratamos como virtual

            if (!array_key_exists($key, $existing->getAttributes())) {
                if (!is_null($new)) {
                    $modified[$key] = $new;
                    Log::info('Agregando a la fuerza '.$key.' con el valor: '.$new);  
                } 
                continue;
            }

            // Valor viejo normalizado
            $old = $this->normalize_value_for_comparison($existing->$key);

            // Si son iguales (tras normalizar), no hay cambio
            if ($old == $new || is_null($new)) continue;

            // Si llegaron hasta acá, es porque realmente cambió
            $modified[$key] = $new;
            $modified["__diff__{$key}"] = [
                'old' => $existing->$key,
                'new' => $value,
            ];
        }

        // Evitamos forzar update por provider_id
        // unset($modified['provider_id'], $modified['__diff__provider_id']);

        return $modified;
    }

    /**
     * Normaliza valores para comparación (números, booleanos, strings, etc.)
     */
    private function normalize_value_for_comparison($v)
    {
        // Nulls
        if (is_null($v)) return null;

        // Booleanos (de Excel o BD)
        if (in_array($v, [true, false, 1, 0, '1', '0', 'true', 'false', 'TRUE', 'FALSE'], true)) {
            return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        // Numéricos
        if (is_numeric($v)) {
            return (float)$v;
        }

        // Strings vacíos → null
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }

        return $v;
    }

    static function get_number($number, $decimales = 2) {
        // 1. Si es null o solo espacios vacíos, retorna null
        if (is_null($number) || (is_string($number) && trim($number) === '')) {
            // \Log::info('get_number Retornando null');
            return null;
        }

        // 2. Reemplazar coma por punto y limpiar espacios
        $normalized = str_replace(',', '.', trim($number));

        // 3. Si no es numérico, retornar null
        if (!is_numeric($normalized)) {
            // \Log::info("get_number Valor no numérico: '$number'");
            return null;
        }

        // 4. Formatear número a la cantidad de decimales solicitada
        return number_format((float) $normalized, $decimales, '.', '');
    }


    private function obtener_stock($row, $articulo_ya_creado = null) {

        $excel_stock = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);

        $stock_global = null;
        $stock_addresses = [];

        // Puede ser columna de stock, min o max
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



            $column_min = 'min_'.str_replace(' ', '_', strtolower($address->street));
            $min_excel = ImportHelper::getColumnValue($row, $column_min, $this->columns);

            if (!is_null($min_excel)) {
                return true;
            }


            $column_max = 'max_'.str_replace(' ', '_', strtolower($address->street));
            $max_excel = ImportHelper::getColumnValue($row, $column_max, $this->columns);
            
            if (!is_null($max_excel)) {
                return true;
            }
        }   

        return false;
    }

    private function obtener_stock_addresses($row, $articulo_ya_creado = null) {
        $set_stock_from_addresses = false;

        $stock_addresses = [];

        foreach ($this->addresses as $address) {

            $column_amount = str_replace(' ', '_', strtolower($address->street));
            $amount_excel = ImportHelper::getColumnValue($row, $column_amount, $this->columns);

            $column_min = 'min_'.str_replace(' ', '_', strtolower($address->street));
            $min_excel = ImportHelper::getColumnValue($row, $column_min, $this->columns);

            $column_max = 'max_'.str_replace(' ', '_', strtolower($address->street));
            $max_excel = ImportHelper::getColumnValue($row, $column_max, $this->columns);

            if (
                !is_null($amount_excel)
                || !is_null($min_excel)
                || !is_null($max_excel)
            ) {

                Log::info('Hay info en la columna '.$address->street);

                $address_article = [
                    'address_id'    => $address->id,
                    'stock_min'     => $min_excel,
                    'stock_max'     => $max_excel,
                    'amount'        => null,
                ];

                Log::info($address->street.' min: '.$min_excel);
                Log::info($address->street.' max: '.$max_excel);

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

                // Ojo aca
                if (!is_null($amount_excel)) {

                    Log::info('Agregando '.$amount_excel.' a la direccion '.$address->street);

                    $amount_excel = (float)$amount_excel;

                    $address_article['amount'] = $amount_excel;
                    // $diferencia = $amount_excel - $stock_actual_en_address;

                    // if ($diferencia != 0) {
                    //     Log::info('Hay una diferencia de '.$diferencia);
                    //     // $stock_addresses[] = [
                    //     //     'address_id'    => $address->id,
                    //     //     'amount'        => $diferencia,
                    //     // ];

                    //     $address_article['amount'] = $diferencia;
                    // }
                } else {
                    Log::info('No se agrego amount a la direccion '.$address->street);
                }

                $stock_addresses[] = $address_article;
            } else {
                Log::info('No hay nada en '.$address->street);
                Log::info($column_min.' min: '.$min_excel);
                Log::info($column_max.' max: '.$max_excel);
            }
        }

        Log::info('stock_addresses:');
        Log::info($stock_addresses);

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

    function get_price_type_row_name($str, $price_type) {
            
        $row_name = $str. str_replace(' ', '_', strtolower($price_type->name));

        return $row_name;
    }


    private function obtener_price_types($row, $articulo_ya_creado = null) {
        // Log::info('obtener_price_types: '.UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios', $this->user));
        $price_types_data = [];

        if (
            UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios', $this->user)
            && $this->se_importaron_price_types
        ) {

            foreach ($this->price_types as $price_type) {
                
                $row_setear_name = $this->get_price_type_row_name('setear_precio_final_', $price_type);

                if (!ImportHelper::isIgnoredColumn($row_setear_name, $this->columns)) {

                    $setear = ImportHelper::getColumnValue($row, $row_setear_name, $this->columns);

                    if (
                        !is_null($setear)
                        && (
                            $setear == 'Si'
                            || $setear == 'si'
                            || $setear == 'SI'
                            || $setear == 'S'
                            || $setear == 's'
                        )
                    ) {

                        $setear = 1;

                    } else {
                        $setear = 0;
                    }
                } else {
                    $setear = null;
                }
            
                $row_percentage_name = $this->get_price_type_row_name('%_', $price_type);
                $percentage = ImportHelper::getColumnValue($row, $row_percentage_name, $this->columns);
            
                $row_final_price_name = $this->get_price_type_row_name('$_final_', $price_type);
                $final_price = ImportHelper::getColumnValue($row, $row_final_price_name, $this->columns);

                Log::info('setear: '.$setear);
                Log::info('percentage: '.$percentage);
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
                            && !$setear
                        ) {
                            Log::info('Entro con percentage');    
                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, $percentage);

                        } else if (
                            $price_type_ya_relacionado->pivot->final_price != $final_price
                            && $setear
                        ) {

                            Log::info('Entro con final_price');    
                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, null, $final_price);

                        } else {

                            Log::info('No entro con ninguno');    
                        }

                    } else {

                        Log::info('No estaba relacionado con price_type');

                        $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, $percentage, $final_price);

                    }

                } else {

                    $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, $percentage, $final_price);
                }

            }
        } else {
            Log::info('Se omitieron price_types');
        }

        return $price_types_data;
    }

    function add_price_type_data($price_types_data, $price_type, $setear, $percentage, $final_price = null) {

        $price_types_data[] = [
            'id'            => $price_type->id,
            'pivot'         => [
                'setear_precio_final'   => !is_null($setear) ? $setear : null,
                'percentage'            => !is_null($percentage) ? $percentage : null,
                'final_price'           => !is_null($final_price) ? $final_price : null,
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

    function get_unidad_medida_id($row) {
        $undiad_medida_excel = ImportHelper::getColumnValue($row, 'unidad_medida', $this->columns);

        $unidad_medida = $this->unidad_medidas->where('name', $undiad_medida_excel)->first();

        if ($unidad_medida) {
            return $unidad_medida->id;
        }

        return null;
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

    function get_articles_match() {
        return $this->articles_match;
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

    function set_unidad_medidas() {

        $this->unidad_medidas = UnidadMedida::orderBy('id', 'ASC')->get();
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

    private function normalize_scalar($v)
    {
        if (is_null($v)) return null;
        if (is_string($v)) {
            $t = trim($v);
            if (is_numeric($t)) return 0 + $t;
            return $t === '' ? null : $t;
        }
        if (is_bool($v)) return (int)$v;
        if (is_numeric($v)) return 0 + $v;
        return $v;
    }

    // private function purge_zero_stock_diffs(array $stock_addresses): array
    // {
    //     Log::info('purge_zero_stock_diffs:');
    //     Log::info($stock_addresses);
    //     $out = [];
    //     foreach ($stock_addresses as $sa) {

    //         if ($sa['amount']) {
    //             $out[] = $sa;
    //         }
    //         // $diff = (float)($sa['amount'] ?? 0);
    //         // if ($diff !== 0.0) $out[] = $sa;
    //     }
    //     return $out;
    // }

    private function purge_zero_stock_diffs($stock_addresses, $article = null)
    {
        $out = [];

        Log::info('stock addresses:');
        foreach ($stock_addresses as $sa) {

            $address_id = isset($sa['address_id']) ? $sa['address_id'] : null;
            if (!$address_id) {
                continue;
            }

            // Buscar dirección existente en la relación 'addresses'
            $existing = $article->addresses()->where('address_id', $address_id)->first();
           

            // Valores actuales (en base de datos)
            $old_amount = $existing && isset($existing->pivot->amount) ? (float)$existing->pivot->amount : null;
            $old_min = $existing && isset($existing->pivot->stock_min) ? (float)$existing->pivot->stock_min : null;
            $old_max = $existing && isset($existing->pivot->stock_max) ? (float)$existing->pivot->stock_max : null;

            // En el Excel puede venir un delta o un valor absoluto.
            $new_amount = !is_null($sa['amount']) ? (float)$sa['amount'] : null;
            $new_min = !is_null($sa['stock_min']) ? (float)$sa['stock_min'] : null;
            $new_max = !is_null($sa['stock_max']) ? (float)$sa['stock_max'] : null;

            // Detectar diferencias individuales
            $diff_amount = 0;
            if (!is_null($new_amount)) {
                $diff_amount = $old_amount !== $new_amount;
            }

            $diff_min = $old_min !== $new_min;
            $diff_max = $old_max !== $new_max;

            Log::info('');
            Log::info('');
            if ($existing) {
                Log::info($existing->street.':');
            }

            Log::info('actual:');
            Log::info('stock: '.$old_amount);
            Log::info('min: '.$old_min);
            Log::info('max: '.$old_max);

            Log::info('');
            Log::info('nuevo:');
            Log::info('stock: '.$new_amount);
            Log::info('min: '.$new_min);
            Log::info('max: '.$new_max);

            // Log::info('');
            Log::info('diff:');
            Log::info('stock: '.$diff_amount);
            Log::info('min: '.$diff_min);
            Log::info('max: '.$diff_max);

            // Si no hay cambios, continuar
            if (!$diff_amount && !$diff_min && !$diff_max) {
                continue;
            }

            // Construimos la estructura de salida
            $stock_a_agregar = null;
            if ($diff_amount) {
                $stock_a_agregar = $new_amount - $old_amount;
            }

            $sa_out = [
                'address_id' => $address_id,
                'amount'     => $stock_a_agregar,
                'stock_min'  => $new_min,
                'stock_max'  => $new_max,
            ];

            // Si hay diffs, agregamos las claves separadas
            if ($diff_amount) {
                $sa_out['__diff__amount'] = [
                    'old' => $old_amount,
                    'new' => $new_amount,
                ];
            }
            if ($diff_min) {
                $sa_out['__diff__min'] = [
                    'old' => $old_min,
                    'new' => $new_min,
                ];
            }
            if ($diff_max) {
                $sa_out['__diff__max'] = [
                    'old' => $old_max,
                    'new' => $new_max,
                ];
            }

            // (Opcional) incluir nombre del depósito si existe
            if ($existing && isset($existing->name)) {
                $sa_out['address_name'] = $existing->name;
            }

            $out[] = $sa_out;
        }

        Log::info('Out:');
        Log::info($out);

        return $out;
    }

    private function filter_only_changed_price_types($article, array $price_types_data): array
    {
        if (!$article || empty($price_types_data)) return [];

        // Log::info('filter_only_changed_price_types, price_types_data:');
        // Log::info($price_types_data);

        $current = [];
        foreach ($article->price_types as $pt) {
            $current[$pt->id] = [
                'id'    => $pt->id,
                'pivot' => [
                    'percentage'      => $pt->pivot->percentage ?? null,
                    'final_price'           => $pt->pivot->final_price ?? null,
                    'setear_precio_final' => $pt->pivot->setear_precio_final ?? null,
                ],
            ];
        }

        // Log::info('current:');
        // Log::info($current);

        $only_changed = [];
        foreach ($price_types_data as $row_pt) {
            $id = $row_pt['id'] ?? null;
            if (is_null($id)) continue;

            $prev = $current[$id] ?? [];
            $changed = false;
            $diff = [];

            foreach (['percentage','final_price','setear_precio_final'] as $f) {
                $old = $this->normalize_scalar($prev['pivot'][$f] ?? null);
                $new = $this->normalize_scalar($row_pt['pivot'][$f] ?? null);
                if (
                    !is_null($new)
                    && $old !== $new
                ) {
                    $changed = true;
                    $diff["__diff__{$f}"] = ['old' => $prev['pivot'][$f] ?? null, 'new' => $row_pt['pivot'][$f] ?? null];
                }
            }

            if ($changed) {
                $only_changed[] = array_merge($row_pt, $diff);
            } else {
                // Log::info('No cambio el precio');
            }
        }

        return $only_changed;
    }



    private function get_discounts_diff($article, $row)
    {
        $discounts_percent_str = ImportHelper::getColumnValue($row, 'descuentos', $this->columns);
        $discounts_amount_str = ImportHelper::getColumnValue($row, 'descuentos_montos', $this->columns);

        $diffs = [];

        // Parsear las cadenas del Excel
        $new_percents = [];
        if ($discounts_percent_str) {
            $new_percents = array_filter(array_map('floatval', explode('_', $discounts_percent_str)));
        }

        $new_amounts = [];
        if ($discounts_amount_str) {
            $new_amounts = array_filter(array_map('floatval', explode('_', $discounts_amount_str)));
        }

        // Obtener los valores actuales desde BD
        $old_percents = [];
        $old_amounts = [];


        if ($article) {
            $article->load('article_discounts');
        }

        if ($article && $article->article_discounts) {
            foreach ($article->article_discounts as $disc) {
                if ($disc->percentage !== null) {
                    $old_percents[] = (float)$disc->percentage;
                } elseif ($disc->amount !== null) {
                    $old_amounts[] = (float)$disc->amount;
                }
            }
        }

        // Comparar porcentajes
        if ($discounts_percent_str) {

            if ($old_percents != $new_percents) {
                $diffs[] = [
                    'type' => '%',
                    '__diff__discounts_percent' => [
                        'old' => $old_percents,
                        'new' => $new_percents,
                    ]
                ];
            }
        }

        // Comparar montos
        if ($discounts_amount_str) {

            if ($old_amounts != $new_amounts) {
                $diffs[] = [
                    'type' => 'amount',
                    '__diff__discounts_amount' => [
                        'old' => $old_amounts,
                        'new' => $new_amounts,
                    ]
                ];
            }
        }

        return $diffs;
    }

    private function get_surchages_diff($article, $row)
    {
        $surchages_percent_str = ImportHelper::getColumnValue($row, 'recargos', $this->columns);
        $surchages_amount_str = ImportHelper::getColumnValue($row, 'recargos_montos', $this->columns);

        $diffs = [];

        // 🔹 1. Parsear nuevos valores del Excel
        $new_percents = [];
        if ($surchages_percent_str) {
            $chunks = explode('_', $surchages_percent_str);
            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }

                $final_flag = false;
                if (substr($chunk, -1) === 'F' || substr($chunk, -1) === 'f') {
                    $final_flag = true;
                    $chunk = substr($chunk, 0, -1);
                }

                $value = (float)$chunk;
                $new_percents[] = [
                    'value' => $value,
                    'final' => $final_flag,
                ];
            }
        }

        $new_amounts = [];
        if ($surchages_amount_str) {
            $chunks = explode('_', $surchages_amount_str);
            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }

                $final_flag = false;
                if (substr($chunk, -1) === 'F' || substr($chunk, -1) === 'f') {
                    $final_flag = true;
                    $chunk = substr($chunk, 0, -1);
                }

                $value = (float)$chunk;
                $new_amounts[] = [
                    'value' => $value,
                    'final' => $final_flag,
                ];
            }
        }

        // 🔹 2. Obtener los valores actuales de BD
        $old_percents = [];
        $old_amounts = [];

        if ($article) {
            $article->load('article_surchages');
            Log::info('article_surchages:');
            Log::info($article->article_surchages);
        }

        if ($article && $article->article_surchages) {
            foreach ($article->article_surchages as $sur) {
                if (!is_null($sur->percentage)) {
                    $old_percents[] = [
                        'value' => (float)$sur->percentage,
                        'final' => (bool)$sur->luego_del_precio_final,
                    ];
                } elseif (!is_null($sur->amount)) {
                    $old_amounts[] = [
                        'value' => (float)$sur->amount,
                        'final' => (bool)$sur->luego_del_precio_final,
                    ];
                }
            }
        }

        // 🔹 3. Comparar porcentajes
        if (!$this->compare_surchages_arrays($old_percents, $new_percents)) {
            $diffs[] = [
                'type' => '%',
                '__diff__surchages_percent' => [
                    'old' => $old_percents,
                    'new' => $new_percents,
                ],
            ];
        }

        // 🔹 4. Comparar montos
        if (!$this->compare_surchages_arrays($old_amounts, $new_amounts)) {
            $diffs[] = [
                'type' => 'amount',
                '__diff__surchages_amount' => [
                    'old' => $old_amounts,
                    'new' => $new_amounts,
                ],
            ];
        }

        return $diffs;
    }

    /**
     * Compara dos arrays de recargos (considerando valor y flag "final")
     */
    private function compare_surchages_arrays($old, $new)
    {
        if (count($old) !== count($new)) {
            return false;
        }

        foreach ($old as $index => $item) {
            $old_val = isset($item['value']) ? (float)$item['value'] : 0.0;
            $new_val = isset($new[$index]['value']) ? (float)$new[$index]['value'] : 0.0;

            $old_final = !empty($item['final']);
            $new_final = !empty($new[$index]['final']);

            if ($old_val !== $new_val || $old_final !== $new_final) {
                return false;
            }
        }

        return true;
    }

}