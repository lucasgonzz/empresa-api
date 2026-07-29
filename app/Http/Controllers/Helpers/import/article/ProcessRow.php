<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ImportChangeRecorder;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\Iva;
use App\Models\PriceType;
use App\Models\SubCategory;
use App\Models\UnidadMedida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessRow {

    protected $columns;
    protected $user;
    protected $ct;
    protected $provider_id;

    /**
     * Modo elegido por el usuario para interpretar el punto en columnas numéricas
     * ambiguas (grupo 239, prompt 04): 'auto' | 'siempre_miles' | 'siempre_decimal'.
     * Solo afecta al caso "solo punto, sin coma" dentro de ImportHelper::parseNumericValue().
     */
    protected $interpretacion_punto = 'auto';
    protected $articles_match = 0;
    protected $articulos_repetidos = 0;

    protected $articles_repetidos = 0;

    /** Cantidad de filas salteadas por matchear de forma ambigua (bar_code/sku/provider_code repetidos). */
    protected $filas_ambiguas = 0;

    /** Cantidad de identificadores (bar_code/sku/provider_code/id) descartados por ser placeholders (ej. "-", "S/N"). */
    protected $identificadores_descartados = 0;

    /**
     * Conflictos detectados en este chunk (ambiguos, placeholders descartados y filas sin
     * identificador), unificados en un solo formato para persistirse en `import_conflicts`
     * al cerrar el lote (ActualizarBBDD::persistir_conflictos(), prompt 02 del grupo 229).
     * Cada entrada: ['fila' => int, 'tipo' => string, 'campo' => ?string, 'valor' => ?string,
     * 'article_ids' => ?array, 'nombre_excel' => ?string].
     */
    protected $conflictos = [];

    /** Número de fila (relativo al chunk) que se está procesando; se incrementa en cada llamada a procesar(). */
    protected $fila_actual = 0;

    /**
     * Escalon de la cadena de identificacion (esta_repetido()) que detecto que esta
     * fila ya estaba en el Excel: 'id'|'bar_code'|'sku'|'provider_code'|'name'|null.
     * Lo setea esta_repetido() inmediatamente antes de cada `return true`, y queda
     * disponible para el llamador de ya_estaba_en_el_excel(). Se resetea a null al
     * inicio de cada fila (ver procesar()) para que una fila sin repeticion no
     * arrastre el valor de la fila anterior (prompt 03, grupo 265).
     *
     * @var string|null
     */
    protected $escalon_repeticion = null;

    /**
     * Conteo por resultado de la fila. Cada fila procesada incrementa EXACTAMENTE UN
     * bucket: la suma de todos tiene que dar el total de filas procesadas del chunk.
     *
     * Los primeros cinco son los escalones de la cadena de identificacion (la fila
     * matcheo contra un articulo existente por esa via).
     */
    protected $conteo_matching = [
        'id'                       => 0,
        'bar_code'                 => 0,
        'sku'                      => 0,
        'provider_code'            => 0,
        'name'                     => 0,

        /* No matcheo por ninguna via: se encola para crear. */
        'creado_nuevo'             => 0,
        /* No matcheo y create_and_edit = false: no se crea ni se actualiza nada. */
        'sin_match_no_creado'      => 0,

        /* Salidas tempranas por repeticion DENTRO del mismo Excel (no llegan a find_with_index). */
        'variante_de_fila_previa'  => 0,
        'merge_fila_repetida'      => 0,
        'fila_repetida_en_excel'   => 0,

        /* Salteadas a proposito. */
        'bloqueado_otro_proveedor' => 0,
        'ambiguo'                  => 0,

        /* Red de seguridad: fila que salio por un camino no contemplado. Tiene que dar 0. */
        'sin_clasificar'           => 0,
    ];

    /** @var string|null Bucket ya asignado a la fila que se esta procesando. */
    protected $bucket_fila_actual = null;

    /** @var int Filas que entraron a procesar() en este chunk. */
    protected $filas_contadas = 0;

    /** @var bool Hay una fila abierta pendiente de cerrar. */
    protected $fila_abierta = false;
    protected $articulosParaActualizar = [];
    protected $articulosParaCrear = [];
    protected $price_types = [];
    protected $property_types = [];
    protected $unidad_medidas = [];
    protected $se_importaron_price_types = false;

    protected $brand_cache = [];
    protected $category_cache = [];
    protected $sub_category_cache = []; // [category_id][name_key] => id
    protected $iva_cache = [];   
    protected $article_index = [];

    protected $observations = [];
    protected $inicio = '';
    protected $fin = '';
    protected $taken_slugs = [];
    protected $slug_next_index = [];

    protected $provider_relations_buffer = []; // [article_id][provider_id] => pivot_data


    /**
     * Constructor: recibe los datos necesarios para procesar las filas
     */
    function __construct($data) {
        $this->columns                  = $data['columns'];
        $this->user                     = $data['user'];
        $this->ct                       = $data['ct'];
        $this->provider_id              = $data['provider_id'];
        $this->create_and_edit          = $data['create_and_edit'];
        
        $this->actualizar_articulos_de_otro_proveedor               = $data['actualizar_articulos_de_otro_proveedor'];
        $this->actualizar_proveedor                                 = $data['actualizar_proveedor'];
        $this->permitir_provider_code_repetido                      = $data['permitir_provider_code_repetido'];
        $this->permitir_provider_code_repetido_en_multi_providers   = $data['permitir_provider_code_repetido_en_multi_providers'];
        $this->actualizar_por_provider_code                         = $data['actualizar_por_provider_code'];

        /*
         * Modo elegido por el usuario para interpretar el punto en columnas numéricas
         * ambiguas (grupo 239, prompt 04). Default 'auto' si no llega, para no romper
         * llamadores viejos (no debería pasar, ArticleImport ya lo manda siempre).
         */
        $this->interpretacion_punto = $data['interpretacion_punto'] ?? 'auto';

        $this->import_history_id = $data['import_history_id'] ?? null;
        $this->import_uuid = $data['import_uuid'] ?? null;

        $this->set_price_types();
        $this->set_addresses();
        $this->set_property_types();
        $this->set_unidad_medidas();
        $this->set_se_importaron_price_types();

        $this->set_brand_cache();
        $this->set_category_cache();
        $this->set_sub_category_cache();
        $this->set_iva_cache();
    }

    public function set_taken_slugs(array $slugs): void
    {
        // set estilo "hash" para lookup O(1)
        $this->taken_slugs = [];
        foreach ($slugs as $s) {
            $this->taken_slugs[$s] = true;
        }
    }

    protected function unique_slug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'articulo';
        }

        if (!isset($this->slug_next_index[$base])) {
            $this->slug_next_index[$base] = 1;
        }

        $slug = $base;

        if (isset($this->taken_slugs[$slug])) {
            $i = $this->slug_next_index[$base];
            do {
                $slug = $base . '-' . $i;
                $i++;
            } while (isset($this->taken_slugs[$slug]));
            $this->slug_next_index[$base] = $i;
        }

        $this->taken_slugs[$slug] = true;
        return $slug;
    }

    public function set_article_index(array $article_index): void
    {
        $this->article_index = $article_index;
    }

    protected function normalize_cache_key($value): string
    {
        return strtolower(trim((string) $value));
    }

    protected function set_brand_cache(): void
    {
        $this->brand_cache = Brand::where('user_id', $this->user->id)
            ->select('id', 'name')
            ->get()
            ->mapWithKeys(fn ($b) => [$this->normalize_cache_key($b->name) => (int)$b->id])
            ->toArray();
    }

    protected function set_category_cache(): void
    {
        $this->category_cache = Category::where('user_id', $this->user->id)
            ->select('id', 'name')
            ->get()
            ->mapWithKeys(fn ($c) => [$this->normalize_cache_key($c->name) => (int)$c->id])
            ->toArray();
    }

    protected function set_sub_category_cache(): void
    {
        $this->sub_category_cache = [];

        $subs = SubCategory::where('user_id', $this->user->id)
            ->select('id', 'name', 'category_id')
            ->get();

        foreach ($subs as $s) {
            $key = $this->normalize_cache_key($s->name);
            $cid = (int)$s->category_id;
            if (!isset($this->sub_category_cache[$cid])) {
                $this->sub_category_cache[$cid] = [];
            }
            $this->sub_category_cache[$cid][$key] = (int)$s->id;
        }
    }

    protected function set_iva_cache(): void
    {
        $this->iva_cache = Iva::select('id', 'percentage')
            ->get()
            ->mapWithKeys(fn ($i) => [trim((string)$i->percentage) => (int)$i->id])
            ->toArray();
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

        $this->observations = [
            'procesos'  => [],
        ];

        // Número de fila relativo a este chunk; se usa para identificar conflictos (ambiguos/placeholders) en los reportes.
        $this->fila_actual++;

        // Reset por fila: si esta fila no repite nada, no puede quedar el escalon de la anterior.
        $this->escalon_repeticion = null;

        // Abre el conteo de esta fila (cierra la anterior si quedo sin clasificar) y
        // suma al total de filas contadas del chunk (ver get_conteo_matching()/get_filas_contadas()).
        $this->abrir_conteo_fila();

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
                'is_number'     => true,
            ],
            [
                'excel_column'  => 'descripcion',
                'prop_key'      => 'descripcion',
            ],
            // Magnitud del contenido del artículo (ej. 2.5 para "2.5 litros"); se parsea como número.
            [
                'excel_column'  => 'medida',
                'prop_key'      => 'medida',
                'is_number'     => true,
            ],
            // Descripción del contenido o empaque del artículo (texto general, no exclusivo de autopartes).
            [
                'excel_column'  => 'contenido',
                'prop_key'      => 'contenido',
            ],
        ];
        
        $this->iniciar();

        $provider_id = $this->get_provider_id($row);

        // Construir array de datos del artículo usando los valores extraídos del Excel
        $data = [
            'provider_id'          => $provider_id,
            'user_id'              => $this->user->id,
        ];

        $this->terminar('get_provider_id');


        $this->iniciar();
        foreach ($props_to_add as $prop_to_add) {

            if (!ImportHelper::isIgnoredColumn($prop_to_add['excel_column'], $this->columns)) {

                $excel_value = ImportHelper::getColumnValue($row, $prop_to_add['excel_column'], $this->columns);

                if (isset($prop_to_add['is_number'])) {

                    /*
                     * get_number_for_field() (grupo 229, prompt 07) valida contra la
                     * precision REAL de la columna destino (cost admite 6 decimales,
                     * percentage_gain solo 8 digitos enteros, etc.) y nunca falla en
                     * silencio: si el valor no es numerico o no entra en la columna,
                     * se registra como conflicto y la fila no pisa el valor existente.
                     */
                    $resultado_numero = Self::get_number_for_field($excel_value, $prop_to_add['prop_key'], $this->interpretacion_punto);

                    if (!$resultado_numero['ok']) {

                        $this->registrar_conflicto_numerico(
                            $this->fila_actual,
                            $prop_to_add['prop_key'],
                            $resultado_numero['original'],
                            $resultado_numero['motivo'],
                            $data['name'] ?? null
                        );

                        // No se pisa el valor existente con basura: se deja el campo sin tocar.
                        continue;
                    }

                    $excel_value = $resultado_numero['value'];
                }

                $data[$prop_to_add['prop_key']] = $excel_value;

            } else {
                // $this->log('Columna ignorada '.$prop_to_add['excel_column']);
            }

        }
        $this->terminar('set props_to_add');


        /*
         * Normalizar los identificadores que llegan del Excel (id, bar_code, sku, provider_code)
         * ANTES de cualquier uso posterior (ya_estaba_en_el_excel, find_with_index, etc.).
         * Los proveedores usan placeholders como "-" o "S/N" para indicar "sin código"; si se
         * tratan como identificadores reales, todas esas filas matchean contra el mismo artículo
         * y se sobreescriben entre sí. Solo se normalizan los 4 campos identificadores: el
         * nombre, la descripción, el precio y el stock quedan intactos.
         */
        $this->iniciar();
        foreach (['id', 'bar_code', 'sku', 'provider_code'] as $campo_identificador) {

            if (!array_key_exists($campo_identificador, $data)) {
                continue;
            }

            // Valor tal cual vino del Excel, para poder registrar el descarte si corresponde.
            $original = $data[$campo_identificador];
            $data[$campo_identificador] = IdentifierNormalizer::normalize($original);

            // Si el valor original no era vacío pero la normalización lo convirtió en null,
            // significa que era un placeholder ("-", "S/N", etc): se registra para el reporte.
            if (!is_null($original) && trim((string) $original) !== '' && is_null($data[$campo_identificador])) {
                $this->registrar_placeholder_descartado($this->fila_actual, $campo_identificador, $original, $data['name'] ?? null);
            }
        }
        $this->terminar('normalizar identificadores (IdentifierNormalizer)');

        /*
         * Si tras normalizar los 4 identificadores la fila quedó sin ninguno utilizable
         * (id, bar_code, sku ni provider_code), se registra como conflicto 'sin_identificador'.
         * Son las filas que hoy caen al fallback por nombre o generan artículos duplicados
         * sin que el usuario se entere (prompt 02, grupo 229).
         */
        if (
            empty($data['id'])
            && empty($data['bar_code'])
            && empty($data['sku'])
            && empty($data['provider_code'])
        ) {
            $this->registrar_sin_identificador($this->fila_actual, $data['name'] ?? null);
        }


        if (!ImportHelper::isIgnoredColumn('iva', $this->columns)) {
            $this->iniciar();
            $iva_id = $this->get_iva_id($row);
            $data['iva_id'] = $iva_id;
            $this->terminar('set iva_id');
        }



        // Categoria y Sub categoria
        $this->iniciar();
        $res = $this->get_category_id($row);

        $category_id = $res['category_id'];
        $sub_category_id = $res['sub_category_id'];

        if (!ImportHelper::isIgnoredColumn('categoria', $this->columns)) {
            $data['category_id'] = $category_id;
        }
        if (!ImportHelper::isIgnoredColumn('sub_categoria', $this->columns)) {
            $data['sub_category_id'] = $sub_category_id;
        }
        $this->terminar('categoria y sub categoria');





        if (!ImportHelper::isIgnoredColumn('moneda', $this->columns)) {
            $this->iniciar();
            $data['cost_in_dollars'] = $this->get_cost_in_dollars($row);
            $this->terminar('moneda');
        }

        if (!ImportHelper::isIgnoredColumn('aplicar_iva', $this->columns)) {
            $this->iniciar();
            $data['aplicar_iva'] = $this->get_aplicar_iva($row);
            $this->terminar('aplicar_iva');
        }

        // Indica si el artículo está en oferta. Default 0 (no en oferta) si no viene la columna.
        if (!ImportHelper::isIgnoredColumn('in_offer', $this->columns)) {
            $this->iniciar();
            $data['in_offer'] = $this->get_boolean_column($row, 'in_offer', 0);
            $this->terminar('in_offer');
        }

        // Indica si el artículo está activo/disponible para la venta. Default 1 para no desactivar por error.
        if (!ImportHelper::isIgnoredColumn('online', $this->columns)) {
            $this->iniciar();
            $data['online'] = $this->get_boolean_column($row, 'online', 1);
            $this->terminar('online');
        }

        // Indica si el precio se muestra como "Consultar" en la tienda. Default 0 (precio visible).
        if (!ImportHelper::isIgnoredColumn('precio_pausado', $this->columns)) {
            $this->iniciar();
            $data['precio_pausado'] = $this->get_boolean_column($row, 'precio_pausado', 0);
            $this->terminar('precio_pausado');
        }

        // Indica si el artículo está disponible en Tienda Nube. Default 0 (no disponible).
        if (!ImportHelper::isIgnoredColumn('disponible_tienda_nube', $this->columns)) {
            $this->iniciar();
            $data['disponible_tienda_nube'] = $this->get_boolean_column($row, 'disponible_tienda_nube', 0);
            $this->terminar('disponible_tienda_nube');
        }

        if (!ImportHelper::isIgnoredColumn('marca', $this->columns)) {
            $this->iniciar();
            $brand_id = ImportHelper::getColumnValue($row, 'marca', $this->columns);
            $data['brand_id'] = $this->get_brand_id($row);
            $this->terminar('brand_id');
        }

        if (!ImportHelper::isIgnoredColumn('unidad_medida', $this->columns)) {
            $this->iniciar();
            $data['unidad_medida_id'] = $this->get_unidad_medida_id($row);
            $this->terminar('unidad_medida_id');
        }


        $this->iniciar();
        if (UserHelper::hasExtencion('autopartes', $this->user)) {
            $data_autopartes = [
                'espesor'               => ImportHelper::getColumnValue($row, 'espesor', $this->columns),
                'modelo'                => ImportHelper::getColumnValue($row, 'modelo', $this->columns),
                'pastilla'              => ImportHelper::getColumnValue($row, 'pastilla', $this->columns),
                'diametro'              => ImportHelper::getColumnValue($row, 'diametro', $this->columns),
                'litros'                => ImportHelper::getColumnValue($row, 'litros', $this->columns),
                // 'contenido' se procesa ahora como campo general dentro de props_to_add.
                'cm3'                   => ImportHelper::getColumnValue($row, 'cm3', $this->columns),
                'calipers'              => ImportHelper::getColumnValue($row, 'calipers', $this->columns),
                'juego'                 => ImportHelper::getColumnValue($row, 'juego', $this->columns),
            ];

            $data = array_merge($data, $data_autopartes);
        }
        $this->terminar('autopartes');



        /* 
            Si el articulo ya estaba previamente en una fila del excel, 
            se omite para no sobreescribirlo
        */
        $this->iniciar();
        $ya_estaba_en_excel = $this->ya_estaba_en_el_excel($data);

        if ($ya_estaba_en_excel) {

            // 👇 Nuevo: si esta fila forma parte del mismo producto y tiene propiedades -> agregar como variante
            $variant_payload = $this->build_variant_payload($row);

            if (!is_null($variant_payload)) {

                // Fila repetida dentro del mismo Excel, tratada como variante del articulo base.
                $this->contar_fila('variante_de_fila_previa');
                $this->attach_variant_to_existing_article($data, $variant_payload);
                // $this->log('Fila repetida tratada como VARIANTE del artículo base');
                return;
            }

            /*
             * "La última fila gana" siempre, sea cual sea el identificador que detectó
             * la repetición (bar_code, sku, provider_code, id o name), y cada
             * sobrescritura se reporta con las dos filas involucradas (decision de
             * Lucas, 29/7/2026, prompt 03 grupo 265).
             *
             * $campo es el escalon que esta_repetido() dejo asentado en
             * escalon_repeticion durante ya_estaba_en_el_excel(). fila_origen_anterior
             * es la fila que HASTA AHORA figuraba como "ganadora" en la cola (la que
             * esta fila va a pisar); se busca ANTES de mergear porque el merge
             * actualiza esa marca a la fila actual.
             */
            $campo = $this->escalon_repeticion;
            $valor = (!is_null($campo) && isset($data[$campo])) ? $data[$campo] : null;

            $fila_origen_anterior = $this->buscar_fila_origen_repetida($campo, $valor, $data);

            $this->registrar_fila_sobrescrita($fila_origen_anterior, $this->fila_actual, $campo, $valor, $data['name'] ?? null);

            if (in_array($campo, ['bar_code', 'sku', 'provider_code'], true)) {
                // Fila repetida por bar_code/sku/provider_code: se hace merge sobre la fila anterior en cola.
                $this->contar_fila('merge_fila_repetida');
                $this->merge_fila_duplicada($data, $row, $campo);
                $this->sumar_durations();
                return $this->observations;
            }

            // Fila repetida por id o name (o sin escalon detectado): se descarta sin mas, pero ya quedo reportada arriba.
            $this->contar_fila('fila_repetida_en_excel');
            $this->articles_repetidos++;
            $this->log('SE OMITIO EN PROCES ROW (fila repetida sin propiedades de variante)');
            return;
        } else {
            // $this->log('No esta aun en el excel');
        }
        $this->terminar('Chequear si estaba repetida la fila');



        // $articulo_ya_creado = ArticleIndexCache::find($data, $this->user->id, $provider_id, $this->no_actualizar_articulos_de_otro_proveedor);

        $this->iniciar();
        $articulo_ya_creado = ArticleIndexCache::find_with_index(
            $data,
            $this->article_index,
            $this->user->id,
            $this->provider_id,
            
            $this->permitir_provider_code_repetido,
            $this->permitir_provider_code_repetido_en_multi_providers,
            $this->actualizar_articulos_de_otro_proveedor,
            $this->actualizar_por_provider_code,
            $this->actualizar_proveedor,
        );
        /*
         * Escalon de la cadena que produjo el match (o null si no hubo match/hubo
         * ambiguedad). Se lee INMEDIATAMENTE despues de find_with_index(), antes de
         * cualquier otra llamada que pueda volver a tocar ArticleIndexCache (ver
         * ArticleIndexCache::ultimo_escalon()).
         */
        $escalon_de_match = ArticleIndexCache::ultimo_escalon();
        $this->terminar('find en cache');

        $this->log('articulo encontrado:');

        /**
         * Marcador especial devuelto por ArticleIndexCache cuando el provider_code
         * existe en otro proveedor y la configuración impide actualizarlo.
         * En este caso no se debe crear ni actualizar.
         */
        $provider_code_bloqueado_en_otro_proveedor = (
            is_array($articulo_ya_creado)
            && !empty($articulo_ya_creado['__provider_code_blocked_by_other_provider'])
        );

        if ($provider_code_bloqueado_en_otro_proveedor) {
            $this->contar_fila('bloqueado_otro_proveedor');
            $this->log('No hubo mach (bloqueado por provider_code existente en otro proveedor)');
            $this->articles_repetidos++;
            $this->sumar_durations();
            return $this->observations;
        }

        /*
         * ArticleIndexCache::find_with_index() devuelve un AmbiguousMatch cuando el
         * identificador de la fila (bar_code/sku/provider_code) resuelve a más de un
         * artículo y la configuración no permite repetidos. Es deliberado: se prefiere
         * saltear la fila y reportarla a adivinar (->first()) y corromper un artículo.
         * No se crea ni se actualiza nada con esta fila.
         */
        if ($articulo_ya_creado instanceof AmbiguousMatch) {
            $this->contar_fila('ambiguo');
            $this->registrar_conflicto_ambiguo($this->fila_actual, $articulo_ya_creado, $data['name'] ?? null);
            $this->sumar_durations();
            return $this->observations;
        }

        if ($articulo_ya_creado instanceof \App\Models\Article) {

            $this->log($articulo_ya_creado->name);
        } else if ($this->son_varios_articulos($articulo_ya_creado)) {

            $this->log('Macheo con '.count($articulo_ya_creado).' articulos:');

            foreach ($articulo_ya_creado as $article) {
                $this->log($article->name);
            }
        } else {
            $this->log('No hubo mach');
        }

        // if (!is_null($articulo_ya_creado)) {
        //     $this->log('No es null');
        // } else {
        //     $this->log('Es null');

        // }

        if (
            !$this->son_varios_articulos($articulo_ya_creado)
            && !($articulo_ya_creado instanceof \App\Models\Article)
        ) {
            $articulo_ya_creado = null;
        }

        // if ($articulo_ya_creado instanceof \App\Models\Article) {
        //     $this->log('Es instancia de Artlce');
        // }

        // Marca si esta fila ya quedo resuelta (match, fake pendiente o creacion encolada);
        // si llega al final sin pasar por ninguna de esas ramas, cuenta 'sin_match_no_creado'.
        $fila_resuelta = false;

        if (
            (!is_null($articulo_ya_creado) && $articulo_ya_creado instanceof \App\Models\Article)
            || $this->son_varios_articulos($articulo_ya_creado)
        ) {

            /*
                Artículo aún no persistido en BD: el índice devolvió el modelo fake registrado en RAM.
                No debe pasar por attach_provider ni procesar_articulo_ya_creado (id null / pivot).
            */
            if (
                !$this->son_varios_articulos($articulo_ya_creado)
                && $articulo_ya_creado instanceof Article
                && $this->is_pending_create_fake_article($articulo_ya_creado)
            ) {

                // Hubo match (contra un articulo fake pendiente de crear); se cuenta por el
                // escalon que produjo ese match, leido inmediatamente tras find_with_index().
                $fila_resuelta = true;
                $this->contar_fila($escalon_de_match);

                $this->add_article_match();

                $this->iniciar();
                $this->merge_fila_en_articulo_para_crear_pendiente($articulo_ya_creado, $data, $row);
                $this->terminar('actualizar articulo pendiente de creacion (fake_id)');

                $this->sumar_durations();

                return $this->observations;
            }

            // Hubo match real (articulo existente o coleccion de articulos): se cuenta una
            // sola vez para esta fila, por el escalon que produjo el match.
            $fila_resuelta = true;
            $this->contar_fila($escalon_de_match);

            $this->log('Articulo ya creado');

            $this->iniciar();
            $this->attach_provider($articulo_ya_creado, $data, $provider_id);
            $this->terminar('attach_provider a articulo creado');


            if ($this->son_varios_articulos($articulo_ya_creado)) {

                foreach ($articulo_ya_creado as $_articulo_ya_creado) {

                    if ($this->is_pending_create_fake_article($_articulo_ya_creado)) {

                        $this->add_article_match();

                        $this->iniciar();
                        $this->merge_fila_en_articulo_para_crear_pendiente($_articulo_ya_creado, $data, $row);
                        $this->terminar('actualizar articulo pendiente de creacion (fake_id), coleccion');

                        continue;
                    }

                    $this->add_article_match();

                    $this->articulos_repetidos++;

                    if (!$this->omitir_por_pertencer_a_otro_proveedor($_articulo_ya_creado, $provider_id)) {

                        $this->iniciar();
                        
                        $this->procesar_articulo_ya_creado($_articulo_ya_creado, $data, $row);

                        $this->terminar('procesar_articulo_ya_creado con provider_code repetido');
                    }

                }
            } else {

                $this->add_article_match();

                if (!$this->omitir_por_pertencer_a_otro_proveedor($articulo_ya_creado, $provider_id)) {

                    $this->iniciar();

                    $this->procesar_articulo_ya_creado($articulo_ya_creado, $data, $row);
                    
                    $this->terminar('procesar_articulo_ya_creado');
                }
            }


        } else if ($this->create_and_edit) {

            // No hubo match por ningun escalon: la fila se encola para crear un articulo nuevo.
            $fila_resuelta = true;
            $this->contar_fila('creado_nuevo');

            $this->log('El articulo NO existia');
            // Si no existe, lo agregamos a los artículos para crear
            
            /* 
                * Agrego siempre price_types_data, porque si el articulo no esta creado le agrego todos
                    los price_types.
                * Cuando termino de procesar el Excel y actualizo la bbdd, 
                    le adjunto todos estos price_types,
                * Y desde el ArticleHelper veo si le pongo el % que viene en el excel o 
                    el % por defecto del price_type 
            */
            $this->iniciar();
            $price_types_data = $this->obtener_price_types($row);
            $data['price_types_data'] = $price_types_data;
            $this->terminar('crear: obtener_price_types');

            
            $this->iniciar();
            $discounts_diff = $this->get_discounts_diff($articulo_ya_creado, $row);
            if (!empty($discounts_diff)) {
                $data['discounts'] = $discounts_diff;
            } 
            $this->terminar('crear: discounts_diff');

            $this->iniciar();
            $surchages_diff = $this->get_surchages_diff($articulo_ya_creado, $row);
            if (!empty($surchages_diff)) {
                $data['surchages'] = $surchages_diff;
            }
            $this->terminar('crear: surchages_diff');


            $this->iniciar();
            $stock = $this->obtener_stock($row);
            $this->terminar('crear: obtener_stock');

            $this->iniciar();
            if (!is_null($stock['stock_global'])) {
                $data['stock_global'] = $stock['stock_global'];
            } else if (count($stock['stock_addresses']) > 0) {
                $data['stock_addresses'] = $stock['stock_addresses'];
            }
            $this->terminar('crear: stock_global');

            $this->iniciar();
            // $data['slug'] = ArticleHelper::slug($data['name'], $this->user->id);

            if (isset($data['slug'])) {
                $data['slug'] = $this->unique_slug((string)$data['name']);
            }
            $this->terminar('crear: article slug');

            $data['variants_data'] = []; // 👈 espacio para variantes

            $data['fake_id'] = 'fake_' . uniqid(); // ID temporal único

            /*
             * Detectar si el bar_code o provider_code de este artículo nuevo ya existe
             * en la BD (en el índice real, no en fakes). Se guarda en el array para que
             * ActualizarBBDD pueda identificar los IDs al persistir y reportarlos al usuario.
             */
            $data['has_repeated_code_in_db'] = $this->bar_code_or_provider_code_already_in_bd($data);

            /*
             * Numero de fila que genero esta entrada (prompt 03, grupo 265): la usa
             * buscar_fila_origen_repetida() para reportar contra que fila hay que
             * encadenar la PROXIMA repeticion de este mismo identificador. Se
             * actualiza tambien en merge_fila_en_articulo_para_crear_pendiente()
             * cuando una fila posterior mergea sobre esta entrada.
             */
            $data['__fila_origen'] = $this->fila_actual;

            $this->articulosParaCrear[] = $data;

            $this->iniciar();
            // Lo agregamos al índice para evitar procesarlo duplicado en siguientes filas
            $fakeArticle = new \App\Models\Article($data);
            // $num = $this->ct->num('articles', $this->user->id);


            // $fakeArticle->fake_id = $data['id'];

            ArticleIndexCache::add($fakeArticle);

            /*
                IMPORTANTE:
                - `ArticleIndexCache::add()` actualiza el índice memoizado en RAM (static runtime_*),
                  pero `ProcessRow` busca usando el snapshot `$this->article_index`.
                - Si no refrescamos este snapshot, la siguiente fila puede NO detectar el artículo "fake"
                  recién agregado.
                - Esto NO impacta en rendimiento porque `get_index()` retorna desde RAM (no Redis)
                  cuando ya está cargado para este user.
            */
            // provider_id puede ser null dependiendo del flujo de importación.
            $provider_id_for_index = !is_null($this->provider_id) ? (int)$this->provider_id : null;
            $this->article_index = ArticleIndexCache::get_index(
                (int)$this->user->id,
                $provider_id_for_index,
                $this->actualizar_articulos_de_otro_proveedor
            );
            $this->terminar('crear: add cache');
        }

        // No hubo match y create_and_edit = false: no se crea ni se actualiza nada con esta fila.
        if (!$fila_resuelta) {
            $this->contar_fila('sin_match_no_creado');
        }

        $this->sumar_durations();

        return $this->observations;
    }

    function sumar_durations() {
        $duration = 0;
        foreach ($this->observations['procesos'] as $observation) {
            
            $duration += $observation['duration'];
        }
        $this->observations['duration'] = $duration;
    }

    function iniciar() {
        $this->inicio = microtime(true);
    }

    function terminar($title) {
        $this->fin = microtime(true);
        $dur = $this->fin - $this->inicio;
        if ($dur > 0) {
            $proceso = [
                'name'          => $title,
                'duration'      => number_format($dur, 2, '.', ''),
            ];

            $this->observations['procesos'][] = $proceso;
            // $this->observations .= $title.' '. number_format($dur, 2, '.', '') .' seg. ';
        }
    }

    function omitir_por_pertencer_a_otro_proveedor($articulo_ya_creado, $provider_id) {

        if (
            !is_null($articulo_ya_creado->provider_id)
            && !is_null($provider_id)
            && !$this->actualizar_articulos_de_otro_proveedor
            && $articulo_ya_creado->provider_id != $provider_id
        ) {
            return true;
        }
        return false;
    }

    function add_article_match() {
        $this->articles_match++;
        // $this->log('articles_match: '.$this->articles_match);
    }

    /**
     * Indica si el modelo es un artículo pendiente de INSERT (fake_id) en la cola de creación.
     *
     * @param mixed $articulo instancia evaluada
     * @return bool
     */
    protected function is_pending_create_fake_article($articulo): bool
    {
        if (!($articulo instanceof Article)) {
            return false;
        }

        $fake_id = $articulo->getAttribute('fake_id');

        return is_string($fake_id) && strncmp($fake_id, 'fake_', strlen('fake_')) === 0;
    }

    /**
     * Combina datos de la fila actual sobre la entrada ya encolada en articulosParaCrear (mismo fake_id).
     * Actualiza índice en RAM: remueve claves viejas del fake y vuelve a registrar el modelo.
     *
     * @param Article $articulo_fake modelo devuelto por el índice (MISMO proceso)
     * @param array $data datos armados desde la fila actual
     * @param array $row fila CSV/Excel
     */
    protected function merge_fila_en_articulo_para_crear_pendiente(Article $articulo_fake, array $data, $row): void
    {
        $fake_id = $articulo_fake->getAttribute('fake_id');

        if (!is_string($fake_id) || $fake_id === '') {
            return;
        }

        $idx_en_cola = null;

        foreach ($this->articulosParaCrear as $idx => $art_en_cola) {

            if (!empty($art_en_cola['fake_id']) && $art_en_cola['fake_id'] === $fake_id) {
                $idx_en_cola = $idx;
                break;
            }
        }

        if ($idx_en_cola === null) {

            $this->log('merge_fila_en_articulo_para_crear_pendiente: no se encontro fake_id en articulosParaCrear');

            return;
        }

        $merged = $this->articulosParaCrear[$idx_en_cola];

        foreach ($data as $key => $value) {
            $merged[$key] = $value;
        }

        $merged['fake_id'] = $fake_id;

        /*
            Base coherente con lo ya acumulado en cola + fila actual, para difs de precios/desc/stock.
        */
        $baseline_para_diffs = new Article($merged);

        $this->iniciar();
        $price_types_data = $this->obtener_price_types($row, $baseline_para_diffs);
        $merged['price_types_data'] = $price_types_data;
        $this->terminar('merge pendiente: obtener_price_types');


        $this->iniciar();
        $discounts_diff = $this->get_discounts_diff($baseline_para_diffs, $row);

        if (!empty($discounts_diff)) {
            $merged['discounts'] = $discounts_diff;
        } else {
            unset($merged['discounts']);
        }

        $this->terminar('merge pendiente: discounts_diff');


        $this->iniciar();
        $surchages_diff = $this->get_surchages_diff($baseline_para_diffs, $row);

        if (!empty($surchages_diff)) {
            $merged['surchages'] = $surchages_diff;
        } else {
            unset($merged['surchages']);
        }

        $this->terminar('merge pendiente: surchages_diff');


        $this->iniciar();
        $stock = $this->obtener_stock($row, $baseline_para_diffs);
        $this->terminar('merge pendiente: obtener_stock');


        $this->iniciar();

        unset($merged['stock_global']);
        unset($merged['stock_addresses']);

        if (!is_null($stock['stock_global'])) {
            $merged['stock_global'] = $stock['stock_global'];
        } else if (count($stock['stock_addresses']) > 0) {
            $merged['stock_addresses'] = $stock['stock_addresses'];
        }

        $this->terminar('merge pendiente: stock');


        $this->iniciar();

        if (isset($merged['slug'])) {
            $merged['slug'] = $this->unique_slug((string) $merged['name']);
        }

        $this->terminar('merge pendiente: slug');


        if (!isset($merged['variants_data']) || !is_array($merged['variants_data'])) {
            $merged['variants_data'] = [];
        }

        /*
         * Esta fila pasa a ser la nueva "ganadora" de esta entrada (prompt 03,
         * grupo 265): si el mismo identificador vuelve a aparecer mas adelante en
         * el Excel, tiene que reportarse contra ESTA fila, no contra la que
         * originalmente encolo la entrada.
         */
        $merged['__fila_origen'] = $this->fila_actual;

        $this->articulosParaCrear[$idx_en_cola] = $merged;

        ArticleIndexCache::remove_fake_from_runtime_index((int) $this->user->id, $fake_id);

        $nuevo_fake_article = new Article($merged);

        ArticleIndexCache::add($nuevo_fake_article);

        $provider_id_for_index = !is_null($this->provider_id) ? (int) $this->provider_id : null;

        $this->article_index = ArticleIndexCache::get_index(
            (int) $this->user->id,
            $provider_id_for_index,
            $this->actualizar_articulos_de_otro_proveedor
        );
    }

    function attach_provider($articulo_ya_creado, $data, $provider_id) {

        // $this->log('attach_provider');

        if (!$provider_id) {
            // $this->log('no entro a attach_provider');
            return;
        }

        // $this->log('attach_provider');
        
        if (
            $this->son_varios_articulos($articulo_ya_creado)
        ) {

            foreach ($articulo_ya_creado as $article) {

                if ($this->is_pending_create_fake_article($article)) {
                    continue;
                }

                $this->update_provider_relation($article, $data, $provider_id);
            }
        } else {

            if ($this->is_pending_create_fake_article($articulo_ya_creado)) {
                return;
            }

            $this->update_provider_relation($articulo_ya_creado, $data, $provider_id);
        }

        // $this->log('articulo_ya_creado: ');
        // $this->log($articulo_ya_creado->toArray());
    }

    function son_varios_articulos($articulo_ya_creado) {
        // $this->log('son_varios_articulos: '.$articulo_ya_creado instanceof Collection);
        if ($articulo_ya_creado instanceof Collection) {
            // $this->log('hay '.count($articulo_ya_creado));
            if (count($articulo_ya_creado) >= 1) {
                return true;
            }
        }
        // $this->log('No son varios articulos');
        return false;
        // return $articulo_ya_creado instanceof Collection;
    }


    function update_provider_relation($articulo_ya_creado, $data, $provider_id) {

        $epsilon = 0.01; // ajustalo según tu caso (p.ej. centavos: 0.01 / 0.001)

        // $this->log('update_provider_relation, data:');
        // $this->log($data);
        // $this->log('actual cost: '.$articulo_ya_creado->cost);

        if (
            isset($data['cost'])
            // && abs((float)$data['cost'] - (float)$articulo_ya_creado->cost) > $epsilon 
        ) {

            $pivot_data = [
                'provider_code' => isset($data['provider_code']) ? $data['provider_code']: null,
                'cost'          => isset($data['cost']) ? $data['cost'] : null,
            ];

            // ⚡️ Performance: no hacemos DB write por fila.
            // Guardamos para upsert masivo al final del chunk.
            $this->buffer_provider_relation((int)$articulo_ya_creado->id, (int)$provider_id, $pivot_data);

            // $this->log('Se adjunta relacion con provider a '.$articulo_ya_creado->name. ' a provider_id: '.$provider_id);
            // $this->log('provider_id '.$provider_id);
            // $this->log('pivot_data '.$pivot_data);

            // ✅ 1 sola operación: inserta o actualiza pivot sin hacer exists() antes
            // $articulo_ya_creado->providers()->syncWithoutDetaching([
            //     $provider_id => $pivot_data
            // ]);
        } else {

            // $this->log('NO Se adjunta relacion con provider');
        }
        

    }

    

    function procesar_articulo_ya_creado($articulo_ya_creado, $data, $row) {
        $this->iniciar();
        $articulo_ya_creado->loadMissing(['price_types', 'addresses']);
        $this->terminar('precargar price_types y addresses para procesar articulo ya creado');

        // Comparar propiedades y obtener las que cambiaron
        $this->iniciar();
        $cambios = $this->get_modified_fields($articulo_ya_creado, $data);
        $this->terminar('get_modified_fields');


        $this->iniciar();
        $price_types_data = $this->obtener_price_types($row, $articulo_ya_creado);
        $price_types_data = $this->filter_only_changed_price_types($articulo_ya_creado, $price_types_data);
        if (!empty($price_types_data)) {
            $cambios['price_types_data'] = $price_types_data;
        }
        $this->terminar('price_types_data');

        
        $this->iniciar();
        $discounts_diff = $this->get_discounts_diff($articulo_ya_creado, $row);
        if (!empty($discounts_diff)) {
            $cambios['discounts'] = $discounts_diff;
        } 
        $this->terminar('discounts_diff');

        $this->iniciar();
        $surchages_diff = $this->get_surchages_diff($articulo_ya_creado, $row);
        if (!empty($surchages_diff)) {
            $cambios['surchages'] = $surchages_diff;
        }
        $this->terminar('surchages_diff');
        

        // if (count($price_types_data) > 0) {
        //     $cambios['price_types_data'] = $price_types_data;
        // }

        $this->iniciar();
        $stock_data = $this->obtener_stock($row, $articulo_ya_creado);
        $this->terminar('obtener_stock');


        // 🔎 Chequeamos si vino stock global y si cambió realmente
        $this->iniciar();
        if (isset($stock_data['stock_global'])) {

            /*
             * obtener_stock() devuelve el DELTA (excel_stock_parsed - stock actual),
             * no el stock final del Excel. Antes se guardaba ese delta directamente
             * en 'new' y se mostraba en el detalle del lote como si fuera el stock
             * resultante ("2 → -1" en vez de "2 → 1 (-1)"). Ahora separamos las tres
             * cifras (old, resultante y delta) para que el frontend pueda mostrar el
             * resultado real (prompt 04, grupo 229).
             */
            $delta = (float)$this->normalize_scalar($stock_data['stock_global']);
            $actual_stock = (float)$this->normalize_scalar($articulo_ya_creado->stock ?? 0);
            $resultante = $actual_stock + $delta;

            /*
             * Comparamos el delta contra cero, NO el resultante contra el stock actual.
             * La comparación vieja ($excel_stock !== $actual_stock) comparaba un delta
             * contra un valor absoluto: si el Excel pedía exactamente el doble del
             * stock actual (delta == stock actual), el cambio se descartaba en
             * silencio y el stock nunca se actualizaba. Usamos != (no !==) porque
             * normalize_scalar puede devolver 0 int o 0.0 float según el origen, y
             * 0 !== 0.0 es true en PHP.
             */
            if ($delta != 0.0) {
                $cambios['stock_global'] = [
                    '__diff__stock' => [
                        'old'   => $actual_stock,
                        'new'   => $resultante,
                        'delta' => $delta,
                    ],
                ];
            }
        }
        $this->terminar('stock_global');


        // 🏬 Si vino stock por direcciones, limpiamos las diferencias cero
        $this->iniciar();
        if (isset($stock_data['stock_addresses']) && is_array($stock_data['stock_addresses'])) {
            $stock_changes = $this->purge_zero_stock_diffs($stock_data['stock_addresses'], $articulo_ya_creado);

            if (!empty($stock_changes)) {
                $cambios['stock_addresses'] = $stock_changes;
            }
        }
        $this->terminar('stock_addresses');


        if (!empty($cambios)) {

            // $this->log('SI Hubo Cambios');

            $cambios['id'] = $articulo_ya_creado->id;

            // Guardar bar_code para identificar este artículo si el mismo bar_code
            // vuelve a aparecer en el Excel (última fila gana).
            if (!empty($data['bar_code'])) {
                $cambios['__bar_code'] = $data['bar_code'];
            }

            /*
             * Marcador generico de "por que campo se identifico esta fila" (prompt 03,
             * grupo 265): a diferencia de __bar_code (solo bar_code), __match_key cubre
             * tambien sku y provider_code, para que una repeticion posterior del mismo
             * identificador en el Excel (merge_fila_duplicada) encuentre esta entrada
             * sin importar cual de los tres escalones la identifico. Misma prioridad
             * que esta_repetido(): bar_code, despues sku, despues provider_code.
             */
            $match_field = null;
            $match_value = null;

            if (!empty($data['bar_code'])) {
                $match_field = 'bar_code';
                $match_value = $data['bar_code'];
            } elseif (!empty($data['sku'])) {
                $match_field = 'sku';
                $match_value = $data['sku'];
            } elseif (!empty($data['provider_code'])) {
                $match_field = 'provider_code';
                $match_value = $data['provider_code'];
            }

            if (!is_null($match_field)) {
                $cambios['__match_key'] = $match_field . '|' . $match_value;
            }

            /*
             * Numero de fila que dejo esta entrada en la cola vigente para este
             * articulo (prompt 03, grupo 265): la usa buscar_fila_origen_repetida()
             * para saber contra que fila reportar la PROXIMA repeticion.
             */
            $cambios['__fila_origen'] = $this->fila_actual;

            // $cambios['variants_data'] = []; // 👈

            $this->articulosParaActualizar[] = $cambios;

            // if (!empty($cambios) && $this->import_history_id && isset($articulo_ya_creado->id)) {
            //     ImportChangeRecorder::logUpdated($this->import_history_id, $articulo_ya_creado->id, $cambios);
            // }
        }  else {
            // $this->log('');
            // $this->log('NO HUBO CAMBIOS');
        }

        // return $cambios;
    }

    /**
     * Busca la fila que HASTA AHORA figura como "ganadora" para el identificador
     * ($campo, $valor) de esta repetición, para poder reportar contra ella el
     * conflicto de sobrescritura (prompt 03, grupo 265).
     *
     * Recorre articulosParaCrear y articulosParaActualizar con la MISMA regla de
     * igualdad que esta_repetido() (no una comparación distinta), y se queda con el
     * __fila_origen de la ÚLTIMA entrada que matchea, no la primera: si esta es ya
     * la tercera-o-más repetición del mismo identificador, la entrada vigente en la
     * cola es la que dejó la repetición anterior, no la fila original del Excel.
     *
     * @param  string|null $campo escalon detectado por esta_repetido() ('id'|'bar_code'|'sku'|'provider_code'|'name'|null)
     * @param  mixed        $valor valor de ese campo en la fila actual
     * @param  array        $data  datos completos de la fila actual (para esta_repetido())
     * @return int|null
     */
    protected function buscar_fila_origen_repetida($campo, $valor, array $data)
    {
        $fila_origen = null;

        foreach ($this->articulosParaCrear as $art) {
            if ($this->esta_repetido($data, $art) && isset($art['__fila_origen'])) {
                $fila_origen = $art['__fila_origen'];
            }
        }

        if (!is_null($fila_origen)) {
            return $fila_origen;
        }

        foreach ($this->articulosParaActualizar as $art) {
            if ($this->esta_repetido($data, $art) && isset($art['__fila_origen'])) {
                $fila_origen = $art['__fila_origen'];
            }
        }

        return $fila_origen;
    }

    /**
     * Cuando el identificador (bar_code, sku o provider_code) de la fila actual ya fue
     * procesado en una fila anterior del mismo Excel, actualiza el artículo ya encolado
     * con los datos de esta fila (última fila gana, generalizado a los tres escalones
     * que soportan merge — prompt 03, grupo 265; antes solo bar_code).
     *
     * Si el artículo es nuevo (pendiente de INSERT), reutiliza merge_fila_en_articulo_para_crear_pendiente.
     * Si el artículo ya existía en BD, recarga el Article REAL desde la base y delega en
     * procesar_articulo_ya_creado() (no un merge superficial de arrays): así no se pierde
     * el cálculo de diffs de stock/price_types que ese método ya hace.
     *
     * @param  array  $data  datos de la fila actual
     * @param  mixed  $row   fila cruda del Excel
     * @param  string $campo 'bar_code'|'sku'|'provider_code'
     * @return void
     */
    protected function merge_fila_duplicada(array $data, $row, string $campo): void
    {
        $valor = isset($data[$campo]) ? $data[$campo] : null;

        if (empty($valor)) {
            $this->log('merge_fila_duplicada: valor vacio para campo ' . $campo . ', se omite');
            return;
        }

        /*
         * $index['bar_codes']/['skus']/['provider_codes'] guardan una LISTA de
         * article_ids (no un escalar) para poder detectar duplicados ambiguos en
         * find_with_index(). Acá solo nos interesa encontrar el id "fake" (artículo
         * nuevo de este mismo import) si está presente entre los ids que matchean;
         * ArticleIndexCache::index_entry_to_ids soporta tanto el formato viejo
         * (escalar) como el nuevo (lista). provider_codes está anidado por
         * provider_id (ver ArticleIndexCache::purgar_fake_del_indice()).
         */
        $ids_en_indice = [];

        if ($campo === 'bar_code') {
            $ids_en_indice = ArticleIndexCache::index_entry_to_ids($this->article_index['bar_codes'][$valor] ?? null);
        } elseif ($campo === 'sku') {
            $ids_en_indice = ArticleIndexCache::index_entry_to_ids($this->article_index['skus'][$valor] ?? null);
        } elseif ($campo === 'provider_code') {
            $provider_id_idx = !is_null($data['provider_id'] ?? null)
                ? (int) $data['provider_id']
                : (!is_null($this->provider_id) ? (int) $this->provider_id : null);

            $ids_en_indice = ArticleIndexCache::index_entry_to_ids(
                $this->article_index['provider_codes'][$provider_id_idx][$valor] ?? null
            );
        }

        $fake_id_en_indice = null;
        foreach ($ids_en_indice as $id_val) {
            if (strncmp((string) $id_val, 'fake_', strlen('fake_')) === 0) {
                $fake_id_en_indice = (string) $id_val;
                break;
            }
        }

        // --- 1. Es un fake (artículo nuevo pendiente de INSERT en este import) ---
        if (!is_null($fake_id_en_indice)) {

            $fake_article = ArticleIndexCache::get_runtime_fake_article((int) $this->user->id, $fake_id_en_indice);

            if ($fake_article instanceof \App\Models\Article) {
                $this->merge_fila_en_articulo_para_crear_pendiente($fake_article, $data, $row);
                $this->log('merge_fila_duplicada: ' . $campo . '=' . $valor . ' actualizado via merge_fila_en_articulo_para_crear_pendiente (fake_id=' . $fake_id_en_indice . ')');
                return;
            }

            $this->log('merge_fila_duplicada: WARNING — fake_id=' . $fake_id_en_indice . ' no encontrado en runtime para ' . $campo . '=' . $valor);
            return;
        }

        // --- 2. Es un artículo real de BD, ya encolado en articulosParaActualizar ---
        $match_key_buscado = $campo . '|' . $valor;
        $idx_encontrado = null;

        foreach ($this->articulosParaActualizar as $idx => $art) {

            $coincide = false;

            if ($campo === 'bar_code' && isset($art['__bar_code']) && $art['__bar_code'] === $valor) {
                $coincide = true;
            } elseif (isset($art['__match_key']) && $art['__match_key'] === $match_key_buscado) {
                $coincide = true;
            }

            if ($coincide) {
                // Sin break: se queda con la ÚLTIMA entrada que matchea, no la primera
                // (mismo motivo que buscar_fila_origen_repetida()).
                $idx_encontrado = $idx;
            }
        }

        if (is_null($idx_encontrado)) {
            $this->log('merge_fila_duplicada: WARNING — ' . $campo . '=' . $valor . ' no encontrado en ninguna cola');
            return;
        }

        $article_id = $this->articulosParaActualizar[$idx_encontrado]['id'];

        /*
         * Recargar el Article REAL desde BD (no un array_merge superficial) para no
         * perder el calculo de diffs de stock/price_types de procesar_articulo_ya_creado().
         * Ese método hace un push() de una entrada NUEVA a articulosParaActualizar (no
         * pisa la entrada anterior in-place): es intencional, ActualizarBBDD::
         * merge_articulos_para_actualizar_ultima_fila_gana() ya fusiona por id con
         * "última fila gana" antes de armar el UPDATE. Lo que hay que garantizar acá
         * es que la entrada vigente para este id quede marcada con __match_key y
         * __fila_origen correctos, para que buscar_fila_origen_repetida() encadene
         * bien la PRÓXIMA repetición (ver bloque de abajo).
         */
        $articulo_real = \App\Models\Article::find($article_id);

        if (is_null($articulo_real)) {
            $this->log('merge_fila_duplicada: WARNING — articulo id=' . $article_id . ' no encontrado en BD para ' . $campo . '=' . $valor);
            return;
        }

        $this->procesar_articulo_ya_creado($articulo_real, $data, $row);

        /*
         * FIX (prompt 03, grupo 265, corrección de checker): procesar_articulo_ya_creado()
         * ya deja __match_key/__fila_origen en la entrada que appendea SI hubo cambios
         * (get_modified_fields no vacío). Si no hubo cambios, no se appendeó nada nuevo
         * y la entrada vigente sigue siendo la anterior con su __fila_origen viejo: se
         * re-marca acá explícitamente para que la fila actual quede como origen vigente
         * de todas formas (esta fila "ganó" la repetición aunque no haya cambiado ningún
         * valor concreto).
         */
        $idx_final = null;
        foreach ($this->articulosParaActualizar as $idx => $art) {
            if ((int) ($art['id'] ?? 0) === (int) $article_id) {
                // Última entrada con este id: la recién appendeada, o la única si no hubo cambios.
                $idx_final = $idx;
            }
        }

        if (!is_null($idx_final)) {
            $this->articulosParaActualizar[$idx_final]['__match_key'] = $match_key_buscado;
            $this->articulosParaActualizar[$idx_final]['__fila_origen'] = $this->fila_actual;
        }

        $this->log('merge_fila_duplicada: ' . $campo . '=' . $valor . ' reprocesado via procesar_articulo_ya_creado (id=' . $article_id . ')');
    }

    /**
     * Convierte el valor de una columna del Excel a un booleano entero (1 o 0).
     *
     * Acepta variantes habituales de "sí" y "no" (Si/Sí/S/1/yes/true/verdadero y No/N/0/false/falso).
     * Si la celda está vacía o el valor no coincide con ninguna variante conocida, retorna el default.
     *
     * @param  array  $row          Fila del Excel en proceso.
     * @param  string $column_name  Clave de la columna en el mapeo de importación.
     * @param  int    $default      Valor por defecto (0 o 1) cuando no hay dato reconocible.
     * @return int                  1 (verdadero) o 0 (falso).
     */
    private function get_boolean_column($row, string $column_name, int $default = 0): int
    {
        // Valor crudo de la celda según el mapeo de columnas.
        $value = ImportHelper::getColumnValue($row, $column_name, $this->columns);

        if (is_null($value)) {
            return $default;
        }

        // Normalizamos a minúsculas y sin espacios para comparar contra los conjuntos aceptados.
        $normalized = strtolower(trim((string)$value));

        if (in_array($normalized, ['si', 'sí', 's', '1', 'yes', 'y', 'true', 'verdadero'], true)) {
            return 1;
        }

        if (in_array($normalized, ['no', 'n', '0', 'false', 'falso'], true)) {
            return 0;
        }

        return $default;
    }

    function get_cost_in_dollars($row) {
        $cost_in_dollars = 0;

        $moneda = ImportHelper::getColumnValue($row, 'moneda', $this->columns);

        if (is_null($moneda)) {
            return $cost_in_dollars;
        }

        $valor = strtolower(trim((string) $moneda));

        /*
         * Valores reconocidos como "costo en dólares = verdadero":
         * USD, u$s, dolar, dólar, dolares, dólares, dollar, dollars, 1, true, si, sí, s, yes, y
         * Todo lo demás (peso, pesos, ars, 0, no, false, etc.) se toma como falso.
         */
        $valores_dolar = [
            'usd', 'u$s',
            'dolar', 'dólar', 'dolares', 'dólares', 'dollar', 'dollars',
            '1', 'true', 'si', 'sí', 's', 'yes', 'y',
        ];

        if (in_array($valor, $valores_dolar, true)) {
            $this->log('Costo en dolares');
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
                // $this->log('Se va a chequear si ya esta para actualizar dentro de '.count($this->articulosParaActualizar).' articulosParaActualizar');
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

    /**
     * Decide si dos filas del MISMO Excel representan al mismo producto.
     *
     * Cadena de escalones, igual en espiritu a la de referencia del lado de la base
     * (ArticleIndexCache::find_with_index()): cada escalon, si el campo tiene valor
     * en $data, decide y devuelve sin caer al siguiente.
     *
     *   1) id            -> compara. return.
     *   2) bar_code      -> compara. return.
     *   3) sku           -> compara. return.
     *   4) provider_code -> compara, solo si !permitir_provider_code_repetido. return.
     *   5) name          -> logica propia (contraste con provider_code cuando los
     *                        repetidos estan habilitados).
     *
     * El codigo de proveedor es el UNICO escalon con bandera de configuracion
     * (permitir_provider_code_repetido) porque es la unica de estas columnas que
     * puede repetirse legitimamente entre productos distintos (ej. varios articulos
     * de un mismo proveedor bajo el mismo codigo de catalogo). id, bar_code y sku
     * identifican al producto por si solos: si la fila ya trae valor en alguno de
     * esos tres, ese valor la identifica y un provider_code repetido es irrelevante
     * (no llega a evaluarse el escalon 4). La pregunta "¿permito repetidos de
     * provider_code?" solo tiene sentido cuando el provider_code es lo unico que
     * identifica a la fila.
     *
     * Comparacion con === (no ==): los codigos vienen como string desde el Excel y
     * un == haria que '0012' y '12' matcheen.
     */
    function esta_repetido($data, $art) {

        $repetido = false;

        // Aseguramos boolean real por si el .env viene como string
        $codigos_repetidos = $this->permitir_provider_code_repetido;

        // 1) Coincidencia por ID
        if (!empty($data['id'])) {

            if (isset($art['id']) && $art['id'] === $data['id']) {
                // $this->log('Ya esta para crear, id: '.$art['id'].' = '.$data['id']);
                $this->escalon_repeticion = 'id';
                return true;
            }
            return false;
        }

        // 2) Coincidencia por bar_code
        if (!empty($data['bar_code'])) {

            /*
             * $art['bar_code'] no siempre está presente: get_modified_fields() salta
             * bar_code de $cambios cuando no hay columna 'numero' (id) en el Excel
             * (es la regla de "bar_code solo se edita si vino un id explícito"), así
             * que un artículo YA EXISTENTE encolado para actualizar puede no tener esa
             * clave aunque su bar_code no haya cambiado. __match_key es el marcador
             * genérico que procesar_articulo_ya_creado() deja SIEMPRE (prompt 03,
             * grupo 265) y no depende de si el campo se considera "modificado".
             */
            $match_via_key = isset($art['__match_key']) && $art['__match_key'] === ('bar_code|' . $data['bar_code']);

            if ($match_via_key || (isset($art['bar_code']) && $art['bar_code'] === $data['bar_code'])) {
                // $this->log('Ya esta para crear, bar_code: '.$data['bar_code']);
                $this->escalon_repeticion = 'bar_code';
                return true;
            }
            return false;
        }

        // 3) Coincidencia por sku
        if (!empty($data['sku'])) {

            // Ver comentario del escalón bar_code: mismo motivo para __match_key.
            $match_via_key = isset($art['__match_key']) && $art['__match_key'] === ('sku|' . $data['sku']);

            if ($match_via_key || (isset($art['sku']) && $art['sku'] === $data['sku'])) {
                // $this->log('Ya esta para crear, sku: '.$data['sku']);
                $this->escalon_repeticion = 'sku';
                return true;
            }
            return false;
        }

        // 4) Coincidencia por provider_code (solo si NO se permiten repetidos)
        if (!empty($data['provider_code']) && !$codigos_repetidos) {

            // Ver comentario del escalón bar_code: mismo motivo para __match_key.
            $match_via_key = isset($art['__match_key']) && $art['__match_key'] === ('provider_code|' . $data['provider_code']);

            if ($match_via_key || (!empty($art['provider_code']) && $art['provider_code'] === $data['provider_code'])) {
                // $this->log('Ya esta para crear, provider_code: '.$data['provider_code']);
                $this->escalon_repeticion = 'provider_code';
                return true;
            }
            return false;
        }

        // 5) Coincidencia por name
        if (!empty($data['name'])) {

            if (!empty($art['name']) && $art['name'] === $data['name']) {

                // --- REGLA NUEVA ---
                // Si se permiten codigos de proveedor repetidos, SOLO marcamos repetido
                // cuando el provider_code también coincide (si ambos existen).
                if ($codigos_repetidos) {

                    // Si ambos tienen provider_code y SON IGUALES => repetido = true
                    if (!empty($data['provider_code']) && !empty($art['provider_code'])) {
                        if ($art['provider_code'] === $data['provider_code']) {
                            $this->escalon_repeticion = 'name';
                            $this->log('Ya esta para crear, name+provider_code: '.$art['name'].' / '.$art['provider_code'].' = '.$data['name'].' / '.$data['provider_code']);
                            return true;
                        } else {
                            // Mismo nombre pero distinto provider_code => NO repetido
                            $this->log('Mismo name pero distinto provider_code con repetidos habilitados: '.$art['name'].' / '.$art['provider_code'].' != '.$data['name'].' / '.$data['provider_code']);
                            return false;
                        }
                    }

                    // Si falta alguno de los provider_code, no podemos garantizar que no esté repetido.
                    // Por seguridad, consideramos repetido (conservador).
                    $this->log('Name coincide pero falta provider_code para contrastar con repetidos habilitados. Se marca como repetido por seguridad: '.$art['name'].' = '.$data['name']);
                    $this->escalon_repeticion = 'name';
                    return true;

                } else {
                    // Si NO se permiten repetidos de provider_code, con que coincida el nombre basta.
                    $this->log('Ya esta para crear, name: '.$art['name'].' = '.$data['name']);
                    $this->escalon_repeticion = 'name';
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

            /*
             * bar_code es la clave de identidad natural del artículo.
             * Solo se permite actualizarlo si la columna 'numero' (ID) estaba
             * presente en el Excel — es decir, si el artículo fue identificado
             * por su ID y el usuario quiso cambiar explícitamente el código de barras.
             * Si no hay ID en $data, el bar_code fue usado para IDENTIFICAR el
             * artículo (no para modificarlo) y debe quedar intacto.
             */
            if ($key === 'bar_code' && empty($data['id'])) continue;

            if (
                $key == 'provider_id'
                && !$this->actualizar_proveedor
            ) {
                // $this->log('No se agrego provider_id porque actualizar_proveedor: '.$this->actualizar_proveedor);
                continue;
            }

            // Valor nuevo normalizado
            $new = $this->normalize_value_for_comparison($value);

            // Si el modelo no tiene esa propiedad, lo tratamos como virtual

            if (!array_key_exists($key, $existing->getAttributes())) {
                if (!is_null($new)) {
                    $modified[$key] = $new;
                    $this->log('Agregando a la fuerza '.$key.' con el valor: '.$new);  
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

    /**
     * Precision real de las columnas numericas de `articles` (grupo 229, prompt 07).
     * Formato: campo => [digitos_enteros_maximos, decimales].
     *
     * Los enteros maximos salen de (precision - scale) de la definicion DECIMAL real
     * de cada columna, verificada en produccion. Si se toca una migracion que cambie
     * estas columnas, hay que actualizar este mapa.
     */
    protected static $numeric_precision = [
        'cost'                   => [44, 6],   // decimal(50,6)
        'costo_real'             => [44, 6],   // decimal(50,6)
        'costo_mano_de_obra'     => [10, 6],   // decimal(16,6)
        'price'                  => [10, 6],   // decimal(16,6)
        'final_price'            => [44, 6],   // decimal(50,6)
        'percentage_gain'        => [6,  2],   // decimal(8,2)
        'percentage_gain_blanco' => [14, 2],   // decimal(16,2)
        'final_price_blanco'     => [28, 2],   // decimal(30,2)
        'stock'                  => [10, 2],   // decimal(12,2)
        'medida'                 => [8,  4],   // decimal(12,4)
        'stock_min'              => [10, 0],   // int
        'unidades_individuales'  => [10, 0],   // int
        'unidades_por_bulto'     => [10, 0],   // int
    ];

    /**
     * Nucleo compartido del parseo numerico robusto: castea con parseNumericValue()
     * (que ya resuelve la ambiguedad punto/coma), redondea a los decimales admitidos
     * y valida el rango real de la columna. Nunca lanza excepcion y nunca devuelve
     * null en silencio: el resultado siempre indica si fue ok o por que fallo.
     *
     * Usado tanto por get_number_for_field() (precision real por columna) como por
     * get_number() (wrapper legacy, precision fija recibida por parametro).
     *
     * @param  mixed  $number               Valor crudo de la celda.
     * @param  int    $max_enteros          Cantidad maxima de digitos enteros permitidos.
     * @param  int    $decimales            Cantidad de decimales a los que se redondea.
     * @param  string $interpretacion_punto Modo de interpretacion del punto ambiguo
     *   (grupo 239, prompt 04): 'auto' | 'siempre_miles' | 'siempre_decimal'. Se
     *   propaga tal cual a ImportHelper::parseNumericValue().
     * @return array ['ok' => bool, 'value' => ?string, 'motivo' => ?string, 'original' => ?string]
     */
    private static function parse_number_core($number, $max_enteros, $decimales, $interpretacion_punto = 'auto') {

        if (is_null($number) || (is_string($number) && trim($number) === '')) {
            return ['ok' => true, 'value' => null];
        }

        try {
            $parsed = ImportHelper::parseNumericValue($number, null, null, $interpretacion_punto);
        } catch (\InvalidArgumentException $exception) {
            // Defecto (1) corregido: ya no se traga la excepcion en silencio.
            return [
                'ok'       => false,
                'motivo'   => 'no_numerico',
                'original' => (string) $number,
            ];
        }

        if (is_null($parsed)) {
            return ['ok' => true, 'value' => null];
        }

        // Redondeo (no truncado) a los decimales que admite la columna real.
        $redondeado = round((float) $parsed, $decimales);

        /*
         * Defecto (2) corregido: NO se usa (string) sobre el float para medir la
         * parte entera (eso da notacion cientifica sobre floats grandes y hace que
         * el control de rango mida mal). Se calcula la parte entera con aritmetica.
         */
        $entero_abs = abs($redondeado) - fmod(abs($redondeado), 1);
        $digitos    = ($entero_abs < 1) ? 1 : ((int) floor(log10($entero_abs)) + 1);

        // Defecto (3) corregido: el limite viene de la columna real, no de un fijo de 10.
        if ($digitos > $max_enteros) {
            return [
                'ok'       => false,
                'motivo'   => 'fuera_de_rango',
                'original' => (string) $number,
            ];
        }

        return [
            'ok'    => true,
            // Separador de miles vacio: MySQL rechaza "123,123.34".
            'value' => number_format($redondeado, $decimales, '.', ''),
        ];
    }

    /**
     * Normaliza un valor numerico del Excel para una columna concreta de `articles`,
     * usando la precision real de esa columna (self::$numeric_precision). Reemplaza
     * el comportamiento legacy de get_number() de devolver null en silencio: quien
     * llama decide si registra un conflicto o corta (grupo 229, prompt 07).
     *
     * @param  mixed  $number               Valor crudo de la celda.
     * @param  string $campo                Nombre de la columna destino (cost, price, medida, etc.).
     * @param  string $interpretacion_punto Modo de interpretacion del punto ambiguo
     *   (grupo 239, prompt 04): 'auto' | 'siempre_miles' | 'siempre_decimal'.
     * @return array ['ok' => bool, 'value' => ?string, 'motivo' => ?string, 'original' => ?string]
     */
    static function get_number_for_field($number, $campo, $interpretacion_punto = 'auto') {

        $config = isset(self::$numeric_precision[$campo])
                    ? self::$numeric_precision[$campo]
                    : [10, 2];

        return self::parse_number_core($number, $config[0], $config[1], $interpretacion_punto);
    }

    /**
     * Normaliza un valor numérico del Excel usando el parser compartido de importaciones.
     *
     * @deprecated Usar get_number_for_field() pasando el campo real de la columna
     * destino, para que la validacion de rango use la precision de esa columna en
     * vez de un limite generico. Se mantiene como envoltorio de compatibilidad para
     * los puntos que aun la llaman directamente sin un campo de `articles` asociado
     * (obtener_stock, variantes, depositos, descuentos, price_types) — migrar uno
     * por uno a get_number_for_field() cuando corresponda a una columna real.
     *
     * @param mixed  $number               Valor crudo de la celda.
     * @param int    $decimales            Cantidad de decimales del resultado formateado.
     * @param string $interpretacion_punto Modo de interpretacion del punto ambiguo
     *   (grupo 239, prompt 04): 'auto' | 'siempre_miles' | 'siempre_decimal'.
     * @return string|null Número formateado como string, o null si está vacío o no es válido
     *                      (incluye el caso "no es un número": este wrapper legacy no
     *                      expone el motivo del fallo, a diferencia de get_number_for_field()).
     */
    static function get_number($number, $decimales = 2, $interpretacion_punto = 'auto') {

        /*
         * Limite generico de 15 digitos enteros (no ligado a ninguna columna real):
         * evita la notacion cientifica/perdida de precision del bug original sin
         * rechazar valores validos por el tope fijo de 10 que tenia antes. 15 es el
         * limite de digitos significativos que un float de PHP representa de forma
         * confiable.
         */
        $resultado = self::parse_number_core($number, 15, $decimales, $interpretacion_punto);

        return $resultado['ok'] ? $resultado['value'] : null;
    }

    /**
     * Igual que get_number() pero pensado para chunks individuales de descuentos/recargos
     * (ej: "12,5" dentro de "12,5_20,3"). Nunca lanza excepción: si el chunk no es un
     * número válido devuelve 0.0, igual que hacía antes floatval()/(float) sobre el string.
     *
     * @param mixed  $chunk                Chunk individual ya separado por "_".
     * @param string $interpretacion_punto Modo de interpretacion del punto ambiguo
     *   (grupo 239, prompt 04): 'auto' | 'siempre_miles' | 'siempre_decimal'.
     * @return float
     */
    static function get_number_forgiving($chunk, $interpretacion_punto = 'auto') {
        try {
            $parsed = ImportHelper::parseNumericValue($chunk, null, null, $interpretacion_punto);
        } catch (\InvalidArgumentException $exception) {
            return 0.0;
        }

        return is_null($parsed) ? 0.0 : (float) $parsed;
    }


    private function obtener_stock($row, $articulo_ya_creado = null) {

        $excel_stock = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);
        // Normaliza stock tolerando separadores locales (ej: "1.000" → 1000).
        $excel_stock_parsed = self::get_number($excel_stock, 0, $this->interpretacion_punto);

        $stock_global = null;
        $stock_addresses = [];

        // Puede ser columna de stock, min o max
        $indico_stock_en_addresses = Self::hay_stock_indicado_en_columnas_addresses($row);

        if (
            (
                !is_null($excel_stock_parsed)
            )
            || $indico_stock_en_addresses
        ) {

            if ($articulo_ya_creado) {

                if ($indico_stock_en_addresses) {

                    $stock_addresses = $this->obtener_stock_addresses($row, $articulo_ya_creado);
                
                } else {
                    $this->log('comparando stock existente de '.$articulo_ya_creado->stock.' con excel de '.$excel_stock_parsed);
                    if ($articulo_ya_creado->stock != $excel_stock_parsed) {
                        $stock_global = $excel_stock_parsed - $articulo_ya_creado->stock;
                        $this->log('nuevo stock_global: '.$stock_global);
                    }
                }

            } else {

                if ($indico_stock_en_addresses) {
                    $stock_addresses = $this->obtener_stock_addresses($row);
                } else {
                    $stock_global = $excel_stock_parsed;
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

                $this->log('Hay info en la columna '.$address->street);

                // Normaliza cantidades y límites de stock por sucursal.
                $min_excel_parsed = self::get_number($min_excel, 0, $this->interpretacion_punto);
                $max_excel_parsed = self::get_number($max_excel, 0, $this->interpretacion_punto);
                $amount_excel_parsed = self::get_number($amount_excel, 0, $this->interpretacion_punto);

                $address_article = [
                    'address_id'    => $address->id,
                    'stock_min'     => $min_excel_parsed,
                    'stock_max'     => $max_excel_parsed,
                    'amount'        => null,
                ];

                $this->log($address->street.' min: '.$min_excel_parsed);
                $this->log($address->street.' max: '.$max_excel_parsed);

                if (!is_null($articulo_ya_creado) && $articulo_ya_creado instanceof \App\Models\Article) {

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
                if (!is_null($amount_excel_parsed)) {

                    $this->log('Agregando '.$amount_excel_parsed.' a la direccion '.$address->street);

                    $address_article['amount'] = (float) $amount_excel_parsed;
                    // $diferencia = $amount_excel - $stock_actual_en_address;

                    // if ($diferencia != 0) {
                    //     $this->log('Hay una diferencia de '.$diferencia);
                    //     // $stock_addresses[] = [
                    //     //     'address_id'    => $address->id,
                    //     //     'amount'        => $diferencia,
                    //     // ];

                    //     $address_article['amount'] = $diferencia;
                    // }
                } else {
                    $this->log('No se agrego amount a la direccion '.$address->street);
                }

                $stock_addresses[] = $address_article;
            } else {
                $this->log('No hay nada en '.$address->street);
                $this->log($column_min.' min: '.$min_excel);
                $this->log($column_max.' max: '.$max_excel);
            }
        }

        // $this->log('stock_addresses:');
        // $this->log($stock_addresses);

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
        // $this->log('obtener_price_types: '.UserHelper::uses_listas_de_precio($this->user));
        $price_types_data = [];

        if (
            UserHelper::uses_listas_de_precio($this->user)
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
                $percentage = self::get_number(ImportHelper::getColumnValue($row, $row_percentage_name, $this->columns), 2, $this->interpretacion_punto);

                $row_final_price_name = $this->get_price_type_row_name('$_final_', $price_type);
                $final_price = self::get_number(ImportHelper::getColumnValue($row, $row_final_price_name, $this->columns), 2, $this->interpretacion_punto);

                $this->log('setear: '.$setear);
                $this->log('percentage: '.$percentage);
                $this->log('final_price: '.$final_price);


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

                        $this->log('YA estaba relacionado con price_type');
                        
                        if (
                            $price_type_ya_relacionado->pivot->percentage != $percentage
                            && !$setear
                        ) {
                            $this->log('Entro con percentage');    
                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, $percentage);

                        } else if (
                            $price_type_ya_relacionado->pivot->final_price != $final_price
                            && $setear
                        ) {

                            $this->log('Entro con final_price');    
                            $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, null, $final_price);

                        } else {

                            $this->log('No entro con ninguno');    
                        }

                    } else {

                        $this->log('No estaba relacionado con price_type');

                        $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, $percentage, $final_price);

                    }

                } else {

                    $price_types_data = $this->add_price_type_data($price_types_data, $price_type, $setear, $percentage, $final_price);
                }

            }
        } else {
            // $this->log('Se omitieron price_types');
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
    // function get_iva_id($row) {
    //     $iva_excel = ImportHelper::getColumnValue($row, 'iva', $this->columns);
    //     $iva_id = LocalImportHelper::getIvaId($iva_excel);
    //     return $iva_id;
    // }
    function get_iva_id($row)
    {
        $iva_excel = ImportHelper::getColumnValue($row, 'iva', $this->columns);

        if (is_null($iva_excel)) {
            return 2; // mismo default que LocalImportHelper
        }

        $iva = trim(str_replace('%', '', (string)$iva_excel));

        if ($iva === '' && $iva !== '0') {
            return 2;
        }

        if (isset($this->iva_cache[$iva])) {
            return $this->iva_cache[$iva];
        }

        $model = Iva::create(['percentage' => $iva]);
        $this->iva_cache[$iva] = (int)$model->id;

        return $model->id;
    }


    function get_aplicar_iva($row) {
        $aplicar_iva = 1;

        $iva_excel = ImportHelper::getColumnValue($row, 'aplicar_iva', $this->columns);
        // $this->log('get_aplicar_iva: '.$iva_excel);
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
    // function get_brand_id($row) {
    //     $brand_excel = ImportHelper::getColumnValue($row, 'marca', $this->columns);

    //     $brand_id = LocalImportHelper::get_bran_id($brand_excel, $this->ct, $this->user);

    //     // $this->log('brand_id para article num: '.$row[0].' = '.$brand_id);

    //     return $brand_id;
    // }
    function get_brand_id($row)
    {
        $brand_excel = ImportHelper::getColumnValue($row, 'marca', $this->columns);

        if (!$brand_excel || trim($brand_excel) === '') {
            return null;
        }

        $name = trim((string)$brand_excel);
        $key = $this->normalize_cache_key($name);

        if (isset($this->brand_cache[$key])) {
            return $this->brand_cache[$key];
        }

        $brand = Brand::create([
            'name' => $name,
            'user_id' => $this->user->id,
        ]);

        $this->brand_cache[$key] = (int)$brand->id;

        return $brand->id;
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
    // function get_category_id($row) {

    //     $category_excel = ImportHelper::getColumnValue($row, 'categoria', $this->columns);

    //     $category_id = null;
    //     $sub_category_id = null;

    //     // Si hay valor en la columna categoría, se obtiene el ID de categoría y subcategoría
    //     if (ImportHelper::usa_columna($category_excel)) {
    //         $category_id = LocalImportHelper::getCategoryId($category_excel, $this->ct, $this->user);

    //         $sub_category_excel = ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns);

    //         $sub_category_id = LocalImportHelper::getSubcategoryId($category_excel, $sub_category_excel, $this->ct, $this->user);
    //     }

    //     return [
    //         'category_id'       => $category_id,
    //         'sub_category_id'   => $sub_category_id,
    //     ];

    // }
    function get_category_id($row)
    {
        $category_excel = ImportHelper::getColumnValue($row, 'categoria', $this->columns);

        $category_id = null;
        $sub_category_id = null;

        if (!ImportHelper::usa_columna($category_excel)) {
            return [
                'category_id' => null,
                'sub_category_id' => null,
            ];
        }

        $category_name = trim((string)$category_excel);
        $category_key = $this->normalize_cache_key($category_name);

        // 1) Categoria
        if (isset($this->category_cache[$category_key])) {
            $category_id = $this->category_cache[$category_key];
        } else {
            $category = Category::create([
                'num' => $this->ct->num('categories', $this->user->id, 'user_id', $this->user->id),
                'name' => $category_name,
                'user_id' => $this->user->id,
            ]);

            SetPriceTypesHelper::set_price_types($category, $this->user);
            SetPriceTypesHelper::set_rangos($category, $this->user);

            $category_id = (int)$category->id;
            $this->category_cache[$category_key] = $category_id;
        }

        // 2) Subcategoria
        $sub_category_excel = ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns);
        if (ImportHelper::usa_columna($sub_category_excel)) {

            $sub_name = trim((string)$sub_category_excel);
            $sub_key = $this->normalize_cache_key($sub_name);

            if (isset($this->sub_category_cache[$category_id][$sub_key])) {
                $sub_category_id = $this->sub_category_cache[$category_id][$sub_key];
            } else {
                $sub = SubCategory::create([
                    'num' => $this->ct->num('sub_categories', $this->user->id, 'user_id', $this->user->id),
                    'name' => $sub_name,
                    'category_id' => $category_id,
                    'user_id' => $this->user->id,
                ]);

                if (UserHelper::hasExtencion('lista_de_precios_por_categoria', $this->user)) {
                    SetPriceTypesHelper::set_price_types($sub, $this->user);
                }

                if (!isset($this->sub_category_cache[$category_id])) {
                    $this->sub_category_cache[$category_id] = [];
                }
                $this->sub_category_cache[$category_id][$sub_key] = (int)$sub->id;

                $sub_category_id = (int)$sub->id;
            }
        }

        return [
            'category_id' => $category_id,
            'sub_category_id' => $sub_category_id,
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

    function get_articles_repetidos() {
        return $this->articles_repetidos;
    }

    /**
     * Registra que un valor de identificador (bar_code/sku/provider_code/id) fue
     * descartado por ser un placeholder (ej. "-", "S/N") y no un código real.
     * Acumula en memoria (para el conteo del chunk) y agrega la entrada unificada
     * a $conflictos, que ActualizarBBDD::persistir_conflictos() inserta en bloque
     * en `import_conflicts` al cerrar el lote (prompt 02, grupo 229).
     *
     * @param int    $fila         número de fila (relativo al chunk) donde se detectó.
     * @param string $campo        campo identificador afectado (bar_code/sku/provider_code/id).
     * @param mixed  $original     valor original tal cual vino del Excel, antes de normalizar.
     * @param string|null $nombre_excel nombre del producto en esa fila, para ubicarla en el Excel.
     * @return void
     */
    function registrar_placeholder_descartado($fila, $campo, $original, $nombre_excel = null): void
    {
        $this->identificadores_descartados++;

        $this->conflictos[] = [
            'fila'          => $fila,
            'fila_ganadora' => null,
            'tipo'          => 'placeholder_descartado',
            'campo'         => $campo,
            'valor'         => (string) $original,
            'article_ids'   => null,
            'nombre_excel'  => $nombre_excel,
        ];

        $this->log('Placeholder descartado en fila ' . $fila . ', campo ' . $campo . ': "' . $original . '"');
    }

    /**
     * Registra una fila salteada por matchear de forma ambigua (más de un artículo
     * candidato para el mismo bar_code/sku/provider_code, sin permitir repetidos).
     * Acumula en memoria (para el conteo del chunk) y agrega la entrada unificada
     * a $conflictos, que ActualizarBBDD::persistir_conflictos() inserta en bloque
     * en `import_conflicts` al cerrar el lote (prompt 02, grupo 229).
     *
     * @param int             $fila         número de fila (relativo al chunk) donde se detectó.
     * @param AmbiguousMatch  $ambiguo      marcador devuelto por ArticleIndexCache::find_with_index().
     * @param string|null     $nombre_excel nombre del producto en esa fila, para ubicarla en el Excel.
     * @return void
     */
    function registrar_conflicto_ambiguo($fila, AmbiguousMatch $ambiguo, $nombre_excel = null): void
    {
        $this->filas_ambiguas++;

        $this->conflictos[] = [
            'fila'          => $fila,
            'fila_ganadora' => null,
            'tipo'          => 'ambiguo',
            'campo'         => $ambiguo->campo,
            'valor'         => $ambiguo->valor,
            'article_ids'   => $ambiguo->article_ids,
            'nombre_excel'  => $nombre_excel,
        ];

        $this->log('Fila ' . $fila . ' salteada por match ambiguo en ' . $ambiguo->campo . ' = "' . $ambiguo->valor . '" (' . count($ambiguo->article_ids) . ' articulos candidatos)');
    }

    /**
     * Registra una fila que, tras normalizar los 4 identificadores (id, bar_code, sku,
     * provider_code), quedó sin ninguno utilizable. Estas filas son las que hoy caen al
     * fallback por nombre en ya_estaba_en_el_excel()/esta_repetido(), o terminan creando
     * artículos duplicados, sin que nadie se entere (prompt 02, grupo 229).
     *
     * No cuenta para $filas_ambiguas ni $identificadores_descartados: es un tipo de
     * conflicto distinto, solo se acumula en $conflictos.
     *
     * @param int         $fila         número de fila (relativo al chunk) donde se detectó.
     * @param string|null $nombre_excel nombre del producto en esa fila, para ubicarla en el Excel.
     * @return void
     */
    function registrar_sin_identificador($fila, $nombre_excel = null): void
    {
        $this->conflictos[] = [
            'fila'          => $fila,
            'fila_ganadora' => null,
            'tipo'          => 'sin_identificador',
            'campo'         => null,
            'valor'         => null,
            'article_ids'   => null,
            'nombre_excel'  => $nombre_excel,
        ];

        $this->log('Fila ' . $fila . ' sin identificador utilizable (id/bar_code/sku/provider_code)');
    }

    /**
     * Registra que un valor numerico de una columna (cost, price, percentage_gain,
     * stock_min, unidades_individuales, medida, etc.) no se pudo procesar de forma
     * segura: o no se pudo interpretar como numero, o se interpreto pero no entra
     * en la columna destino. Reemplaza el comportamiento anterior de get_number(),
     * que devolvia null en silencio y dejaba al usuario sin enterarse de que ese
     * costo (por ejemplo) no se importo (grupo 229, prompt 07).
     *
     * Acumula la entrada unificada en $conflictos, que
     * ActualizarBBDD::persistir_conflictos() inserta en bloque en
     * `import_conflicts` al cerrar el lote.
     *
     * @param int         $fila         número de fila (relativo al chunk) donde se detectó.
     * @param string      $campo        campo numérico afectado (cost, price, medida, etc.).
     * @param string      $original     valor original tal cual vino del Excel, sin parsear.
     * @param string      $motivo       'no_numerico' | 'fuera_de_rango'.
     * @param string|null $nombre_excel nombre del producto en esa fila, para ubicarla en el Excel.
     * @return void
     */
    function registrar_conflicto_numerico($fila, $campo, $original, $motivo, $nombre_excel = null): void
    {
        $this->conflictos[] = [
            'fila'          => $fila,
            'fila_ganadora' => null,
            'tipo'          => $motivo === 'fuera_de_rango' ? 'numero_fuera_de_rango' : 'numero_invalido',
            'campo'         => $campo,
            'valor'         => (string) $original,
            'article_ids'   => null,
            'nombre_excel'  => $nombre_excel,
        ];

        $this->log('Conflicto numerico en fila ' . $fila . ', campo ' . $campo . ' (' . $motivo . '): "' . $original . '"');
    }

    /**
     * Registra que una fila de este Excel fue sobrescrita por otra posterior con el
     * mismo identificador ("última fila gana" — decisión de Lucas, 29/7/2026, prompt
     * 03 grupo 265). A diferencia de los demás tipos de conflicto, ESTE no representa
     * una fila que no se pudo procesar: se resolvió bien (la fila ganadora prevalece).
     * Por eso NO suma a conflicts_count (ver ActualizarBBDD::persistir_conflictos()).
     *
     * IMPORTANTE sobre los nombres: $fila es la fila que PIERDE (la que ya estaba
     * encolada), $fila_ganadora es la fila que se está procesando AHORA mismo
     * ($this->fila_actual en el llamador). Suena al revés pero no lo es: en el
     * momento en que se detecta la sobrescritura, la fila ganadora es la actual y
     * la perdedora es la que ya estaba en la cola de antes.
     *
     * @param  int|null    $fila          número de fila (relativo al chunk) que PIERDE.
     * @param  int         $fila_ganadora número de fila que GANA (la que se está procesando).
     * @param  string|null $campo         escalón que detectó la repetición ('bar_code'|'sku'|'provider_code'|'id'|'name').
     * @param  mixed       $valor         valor del identificador que se repitió.
     * @param  string|null $nombre_excel  nombre del producto en la fila ganadora, para ubicarla en el Excel.
     * @return void
     */
    function registrar_fila_sobrescrita($fila, $fila_ganadora, $campo, $valor, $nombre_excel = null): void
    {
        $this->conflictos[] = [
            'fila'          => $fila,
            'fila_ganadora' => $fila_ganadora,
            'tipo'          => 'fila_sobrescrita',
            'campo'         => $campo,
            'valor'         => is_null($valor) ? null : (string) $valor,
            'article_ids'   => null,
            'nombre_excel'  => $nombre_excel,
        ];

        $this->log('Fila ' . $fila . ' sobrescrita por fila ' . $fila_ganadora . ' (campo ' . $campo . ' = "' . $valor . '")');
    }

    /**
     * Cantidad de filas salteadas por match ambiguo en este chunk.
     */
    function get_filas_ambiguas() {
        return $this->filas_ambiguas;
    }

    /**
     * Cantidad de identificadores descartados por ser placeholders en este chunk.
     */
    function get_identificadores_descartados() {
        return $this->identificadores_descartados;
    }

    /**
     * Asigna el resultado de la fila actual. El primer llamado gana: si por algun camino
     * se llama dos veces para la misma fila, el segundo se ignora en vez de duplicar.
     *
     * @param  string $bucket
     * @return void
     */
    protected function contar_fila($bucket)
    {
        if (!is_null($this->bucket_fila_actual)) {
            return;
        }

        if (!array_key_exists($bucket, $this->conteo_matching)) {
            $bucket = 'sin_clasificar';
        }

        $this->bucket_fila_actual = $bucket;
        $this->conteo_matching[$bucket]++;
    }

    /**
     * Cierra la fila anterior y abre la nueva. Si la fila anterior salio por un camino
     * que no llama a contar_fila(), cae en sin_clasificar en vez de desbalancear el total.
     *
     * @return void
     */
    protected function abrir_conteo_fila()
    {
        $this->cerrar_conteo_fila();

        $this->bucket_fila_actual = null;
        $this->filas_contadas++;
        $this->fila_abierta = true;
    }

    /**
     * Cierra la fila en curso, si hay alguna abierta sin clasificar. Es idempotente:
     * se puede llamar tanto al abrir la fila siguiente como al leer los contadores.
     *
     * @return void
     */
    protected function cerrar_conteo_fila()
    {
        if (!$this->fila_abierta) {
            return;
        }

        if (is_null($this->bucket_fila_actual)) {
            $this->conteo_matching['sin_clasificar']++;
            $this->bucket_fila_actual = 'sin_clasificar';
        }

        $this->fila_abierta = false;
    }

    /**
     * Conteo por resultado de fila de este chunk. Cierra la ultima fila antes de devolver,
     * para que el invariante valga tambien para la fila final del chunk.
     *
     * @return array
     */
    function get_conteo_matching()
    {
        $this->cerrar_conteo_fila();

        return $this->conteo_matching;
    }

    /**
     * Filas que entraron a procesar() en este chunk. Es el total contra el que tiene que
     * cerrar la suma de get_conteo_matching().
     *
     * @return int
     */
    function get_filas_contadas()
    {
        return $this->filas_contadas;
    }

    /**
     * Detalle unificado de todos los conflictos detectados en este chunk (ambiguos,
     * placeholders descartados y filas sin identificador), listo para que
     * ActualizarBBDD::persistir_conflictos() lo inserte en bloque en `import_conflicts`.
     *
     * @return array
     */
    function get_conflictos(): array
    {
        return $this->conflictos;
    }

    public function buffer_provider_relation(int $article_id, int $provider_id, array $pivot_data): void
    {
        if (!isset($this->provider_relations_buffer[$article_id])) {
            $this->provider_relations_buffer[$article_id] = [];
        }

        // última fila gana (si viene repetido en el excel)
        $this->provider_relations_buffer[$article_id][$provider_id] = $pivot_data;
        $this->log('Se agregao al buffer');
    }

    public function get_provider_relations_buffer(): array
    {
        return $this->provider_relations_buffer;
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

        $variant_price = self::get_number(ImportHelper::getColumnValue($row, 'precio', $this->columns), 2, $this->interpretacion_punto);
        $variant_stock_parsed = self::get_number(ImportHelper::getColumnValue($row, 'stock_actual', $this->columns), 0, $this->interpretacion_punto);
        $image_url     = ImportHelper::getColumnValue($row, 'imagen', $this->columns); // si mapeás una columna 'imagen'
        $sku           = ImportHelper::getColumnValue($row, 'sku', $this->columns);
        
        // 👇 NUEVO: extraer stocks por address desde columnas stock_*
        $address_stocks = $this->extract_address_stocks($row);
        $address_display = $this->extract_address_display_by_street($row);
        
        return [
            'properties' => $props,                  // ['color'=>'Rojo','talle'=>'42', ...]
            'price'      => $variant_price ?? null,  // num o null
            'stock'      => is_null($variant_stock_parsed) ? null : (int) $variant_stock_parsed,
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

                // Normalizamos cantidad a entero >= 0 tolerando formatos locales y símbolos de moneda.
                $amount_parsed = self::get_number($address_excel, 0, $this->interpretacion_punto);
                $amount = is_null($amount_parsed) ? 0 : (int) round((float) $amount_parsed);
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
    //     $this->log('purge_zero_stock_diffs:');
    //     $this->log($stock_addresses);
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

        // $this->log('stock addresses:');
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

            // $this->log('');
            // $this->log('');
            if ($existing) {
                // $this->log($existing->street.':');
            }

            // $this->log('actual:');
            // $this->log('stock: '.$old_amount);
            // $this->log('min: '.$old_min);
            // $this->log('max: '.$old_max);

            // $this->log('');
            // $this->log('nuevo:');
            // $this->log('stock: '.$new_amount);
            // $this->log('min: '.$new_min);
            // $this->log('max: '.$new_max);

            // $this->log('');
            // $this->log('diff:');
            // $this->log('stock: '.$diff_amount);
            // $this->log('min: '.$diff_min);
            // $this->log('max: '.$diff_max);

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

        // $this->log('Out:');
        // $this->log($out);

        return $out;
    }

    private function filter_only_changed_price_types($article, array $price_types_data): array
    {
        if (!$article || empty($price_types_data)) return [];

        // $this->log('filter_only_changed_price_types, price_types_data:');
        // $this->log($price_types_data);

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

        // $this->log('current:');
        // $this->log($current);

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
                // $this->log('No cambio el precio');
            }
        }

        return $only_changed;
    }



    private function get_discounts_diff($article, $row)
    {
        $discounts_percent_str = ImportHelper::getColumnValue($row, 'descuentos', $this->columns);
        $discounts_amount_str = ImportHelper::getColumnValue($row, 'descuentos_montos', $this->columns);

        $diffs = [];


        // Si se ignoraron ambos columnas de descuentos, se turna empty para que no modifique en la bbdd
        if (
            ImportHelper::isIgnoredColumn('descuentos', $this->columns)
            && ImportHelper::isIgnoredColumn('descuentos_montos', $this->columns)
        ) {
            return $diffs;
        }

        // Parsear las cadenas del Excel
        $new_percents = [];
        if ($discounts_percent_str) {
            $new_percents = array_filter(array_map(fn($chunk) => self::get_number_forgiving($chunk, $this->interpretacion_punto), explode('_', $discounts_percent_str)));
        }

        $new_amounts = [];
        if ($discounts_amount_str) {
            $new_amounts = array_filter(array_map(fn($chunk) => self::get_number_forgiving($chunk, $this->interpretacion_punto), explode('_', $discounts_amount_str)));
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

        // Comparar porcentajes: si la columna está mapeada (no ignorada), siempre comparar aunque esté vacía.
        // Celda vacía + old con valores → diff con new:[] → dispara el borrado del descuento %.
        // Si la columna está ignorada se omite para no tocar datos existentes.
        if (!ImportHelper::isIgnoredColumn('descuentos', $this->columns)) {

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

        // Comparar montos: misma lógica que porcentajes.
        // Celda vacía + old con valores → diff → borra los montos existentes.
        if (!ImportHelper::isIgnoredColumn('descuentos_montos', $this->columns)) {

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


        // Si se ignoraron ambos columnas de recargos, se retorna empty para que no modifique en la bbdd
        if (
            ImportHelper::isIgnoredColumn('recargos', $this->columns)
            && ImportHelper::isIgnoredColumn('recargos_montos', $this->columns)
        ) {
            return $diffs;
        }

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

                $value = self::get_number_forgiving($chunk, $this->interpretacion_punto);
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

                $value = self::get_number_forgiving($chunk, $this->interpretacion_punto);
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
            // $this->log('article_surchages:');
            // $this->log($article->article_surchages);
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

    /**
     * Verifica si el bar_code o el provider_code del artículo a crear ya existe en la BD.
     * Usa el snapshot $this->article_index para evitar queries adicionales.
     * Solo considera IDs reales (no fakes), ya que los fakes son artículos del mismo chunk.
     *
     * @param array $data Datos del artículo a crear.
     * @return bool True si alguno de los códigos ya existe en un artículo real de la BD.
     */
    protected function bar_code_or_provider_code_already_in_bd(array $data): bool
    {
        /*
         * Chequear bar_code contra el índice de bar_codes.
         * La entrada es una LISTA de ids (formato nuevo) o un escalar (índice viejo
         * cacheado); index_entry_to_ids soporta ambos. Alcanza con que UNO de los ids
         * sea real (no fake) para considerar que el código ya existe en BD.
         */
        if (!empty($data['bar_code'])) {
            $ids_bc = ArticleIndexCache::index_entry_to_ids($this->article_index['bar_codes'][(string)$data['bar_code']] ?? null);
            foreach ($ids_bc as $id_val) {
                if (strncmp((string)$id_val, 'fake_', strlen('fake_')) !== 0) {
                    return true;
                }
            }
        }

        /* Chequear provider_code contra el índice de provider_codes por proveedor. */
        if (!empty($data['provider_code']) && !empty($data['provider_id'])) {
            $pc_index = $this->article_index['provider_codes'][(int)$data['provider_id']]
                [(string)$data['provider_code']] ?? null;

            if (is_array($pc_index)) {
                foreach ($pc_index as $id) {
                    if (strncmp((string)$id, 'fake_', strlen('fake_')) !== 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    function log($text) {
        if (config('app.APP_ENV') == 'local') {
            Log::info($text);
        }
    }

}