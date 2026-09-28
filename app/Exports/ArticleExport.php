<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\ExportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArticleExport implements FromCollection, WithHeadings, WithMapping
{

    public $models = null;
    public $user_id = null;
    public $archivo_base = false;

    protected $headings_pre_addresses_cache = null;
    protected $headings_pre_price_types_cache = null;

    protected $columnas_cache = null;

    protected $relaciones_cache = null;

    function __construct($models, $user_id, $archivo_base = false) {
        $this->models = $models;
        $this->user_id = $user_id;
        $this->archivo_base = $archivo_base;

        $this->user = User::find($user_id);

        // En cola no hay sesión: ExportHelper debe usar el mismo dueño que este Excel (PriceType, Address, listas_de_precio).
        if ($this->user) {
            ExportHelper::set_article_export_owner_user($this->user);
        }

        Log::info('user_id: '.$user_id);
        Log::info('user name: '.($this->user ? $this->user->name : 'null'));
    }

    /**
     * Libera el contexto estático de ExportHelper al finalizar el ciclo de vida del export.
     *
     * @return void
     */
    public function __destruct()
    {
        ExportHelper::clear_article_export_owner_user();
    }

    protected function get_base_headings(): array
    {
        return [
            
            // Datos Generales
            'Numero',
            'Codigo de barras',
            'Sku',
            'Codigo de proveedor',
            'Nombre',
            'Proveedor',


            // Precio
            'Moneda',
            'Costo',
            'Descuentos',
            'Recargos',
            'Descuentos montos',
            'Recargos montos',
            'Iva',
            'Aplicar Iva',
            'Margen de ganancia',
            'Precio',
            'Precio Final',
            'Precio Final Anterior',

            // Categoria
            'Categoria',
            'Sub Categoria',
            'Marca',
            'Descripcion',
            'Unidad medida',
            'U individuales',

            // Stock
            'Stock actual',
            'Stock minimo',
        ];
    }

    protected function get_headings_pre_addresses(): array
    {
        if (!is_null($this->headings_pre_addresses_cache)) {
            return $this->headings_pre_addresses_cache;
        }

        $headings = $this->get_base_headings();

        // $headings = ExportHelper::set_unidades_individuales($headings);

        $headings = ExportHelper::set_props_autopartes($headings);
        
        $headings = ExportHelper::set_propiedades_de_distribuidora($headings);

        $this->headings_pre_addresses_cache = $headings;

        return $headings;
    }

    protected function get_headings_pre_price_types(): array
    {
        if (!is_null($this->headings_pre_price_types_cache)) {
            return $this->headings_pre_price_types_cache;
        }

        $headings = $this->get_base_headings();

        // $headings = ExportHelper::set_unidades_individuales($headings);

        $headings = ExportHelper::set_props_autopartes($headings);
        
        $headings = ExportHelper::set_propiedades_de_distribuidora($headings);
        
        $headings = ExportHelper::setAddressesHeadings($headings);
        
        $headings = ExportHelper::setPropertyTypesHeadings($headings);

        $this->headings_pre_price_types_cache = $headings;

        return $headings;
    }

    /**
     * Mapea una fila (artículo o artículo+variante) al orden de columnas del Excel.
     * Orden: datos base hasta "U individuales", autopartes, distribuidora, stock global o por sucursal,
     * tipos de propiedad de variantes, listas de precio (splice), precios en blanco y fechas.
     *
     * @param object $row Clon de artículo con flags `is_variant` y `variant` opcional.
     * @return array Valores de celda en el mismo orden que headings().
     */
    public function map($row): array
    {
        // Referencia al modelo base (mismo objeto que $row pero nombre más claro para lectura).
        $article = $row;

        // Bloque base sin columnas de stock: el stock va después de autopartes/distribuidora o se reemplaza por sucursales.
        $map = [
            ExportHelper::valor($article, 'id'),
            ExportHelper::valor($article, 'bar_code'),
            ExportHelper::valor($article, 'sku'),
            ExportHelper::valor($article, 'provider_code'),
            ExportHelper::valor($article, 'name'),
            !is_null($article->provider) ? $article->provider->name : '',

            $this->getCostInDollars($article),
            ExportHelper::valor($article, 'cost'),

            ExportHelper::valor($article, 'discounts_percentage_formated'),
            ExportHelper::valor($article, 'surchages_percentage_formated'),
            ExportHelper::valor($article, 'discounts_amount_formated'),
            ExportHelper::valor($article, 'surchages_amount_formated'),

            !is_null($article->iva) ? $article->iva->percentage : '',
            ExportHelper::valor($article, 'aplicar_iva') ? 'Si' : 'No',
            ExportHelper::valor($article, 'percentage_gain'),
            ExportHelper::valor($article, 'price'),
            ExportHelper::valor($article, 'final_price'),
            ExportHelper::valor($article, 'previus_final_price'),

            !is_null($article->category) ? $article->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            !is_null($article->brand) ? $article->brand->name : '',
            ExportHelper::valor($article, 'descripcion'),
            $this->getUnidadMedida($article),
            ExportHelper::valor($article, 'unidades_individuales'),
        ];

        $map = ExportHelper::map_autopartes($map, $article);

        $map = ExportHelper::map_propiedades_de_distribuidora($map, $article);

        // Depósitos del usuario: reemplazan "Stock actual" / "Stock minimo" por columnas por sucursal.
        $addresses = ExportHelper::getAddresses();
        if (count($addresses) >= 1) {

            if (ExportHelper::valor($row, 'is_variant') && ExportHelper::valor($row, 'variant')) {

                $map = ExportHelper::map_variant_stock_addresses($map, $row);
            } else {

                $map = ExportHelper::mapAddresses($map, $article);
            }
        } else {

            // Sin sucursales: conservar stock del artículo o stock de la variante en la misma posición que headings.
            $map[] = ExportHelper::valor($row, 'is_variant') && ExportHelper::valor($row, 'variant') ? $row->variant->stock : ExportHelper::valor($article, 'stock');
            $map[] = ExportHelper::valor($article, 'stock_min');
        }

        if (ExportHelper::valor($row, 'is_variant') && ExportHelper::valor($row, 'variant')) {

            $map = ExportHelper::map_property_types($map, $row);
        } else {

            if (ExportHelper::tiene_extencion('article_variants')) {
                $map = ExportHelper::map_property_types_vacios($map);
            }
        }

        $price_types = ExportHelper::getPriceTypes();
        if (count($price_types) >= 1) {

            // 1) sacar columnas viejas (ya lo hacés)
            // Debe coincidir con setPriceTypesHeadings: allí se quitan las cuatro columnas de precio "base".
            $map = ExportHelper::unset_map_columns_by_titles(
                $map,
                $this->get_headings_pre_price_types(),
                ['Margen de ganancia', 'Precio', 'Precio Final', 'Precio Final Anterior']
            );

            /*
             * 2) insertar los valores en la MISMA posición que setPriceTypesHeadings() puso los
             * encabezados. Con listas_de_precio van después de "Aplicar Iva" (tres columnas por
             * lista). Sin listas_de_precio los encabezados van AL FINAL (antes de precios en
             * blanco y fechas), una columna por lista.
             *
             * 🔴 Hasta el 28/9/2026 el caso sin listas_de_precio también insertaba después de
             * "Aplicar Iva": desde "Categoria" en adelante cada valor caía en la columna de al
             * lado (Categoria, Marca y Descripcion vacías o con precios; el stock bajo el nombre
             * de una lista). Es el caso de Servian. Reimportar ese Excel pisaba datos.
             */
            if (ExportHelper::usa_listas_de_precio()) {

                $headings_pre_price_types = $this->get_headings_pre_price_types();
                $aplicar_iva_index = array_search('Aplicar Iva', $headings_pre_price_types);

                $values = ExportHelper::get_price_types_values_in_order($article);

                // insertar después de aplicar_iva
                array_splice($map, $aplicar_iva_index + 1, 0, $values);
            } else {

                $map = array_merge($map, ExportHelper::valores_de_listas_al_final($article));
            }
        }

        // $map = ExportHelper::mapPriceTypes($map, $article);
        $map = ExportHelper::mapPreciosBlanco($map, $article);
        $map = ExportHelper::mapDates($map, $article);
        return $map;
    }

    /**
     * Expande la colección de artículos incluyendo filas por cada variante.
     *
     * @param \Illuminate\Support\Collection $articles
     * @return \Illuminate\Support\Collection
     */
    public function expandWithVariants($articles)
    {
        $rows = collect();

        foreach ($articles as $article) {
            // Fila base del artículo (puede omitirse si no la querés)
            $row = clone $article;
            $row->is_variant = false;
            $row->variant = null;
            $rows->push($row);

            foreach ($article->article_variants as $variant) {
                $vRow = clone $article;
                $vRow->is_variant = true;
                $vRow->variant = $variant;
                $rows->push($vRow);
            }
        }

        return $rows;
    }


    /**
     * Todas las relaciones que leen map() y ExportHelper, para cargarlas por lote y no por fila.
     *
     * Antes faltaban `provider`, `category` y `unidad_medida` (una consulta por fila cada una) y
     * `price_type_monedas` y los depósitos y valores de las variantes; y sobraba `providers`, que
     * el Excel no lee.
     *
     * @return array
     */
    public static function relaciones()
    {
        return [
            'iva',
            'provider',
            'category',
            'sub_category',
            'brand',
            'unidad_medida',
            'tipo_envase',
            'article_discounts',
            'article_surchages',
            'article_discounts_blanco',
            'article_surchages_blanco',
            'addresses',
            'price_types',
            'price_type_monedas',
            'article_variants.addresses',
            'article_variants.article_property_values',
        ];
    }

    /**
     * Relaciones de relaciones() que el Excel de ESTE comercio no va a leer, según sus
     * extensiones, listas de precio y depósitos. Cada relación cuesta una consulta y su
     * hidratación por lote aunque venga vacía; en Servian eran cinco de dieciséis.
     *
     * @return array Nombre de relación => true si es de un modelo (belongsTo), false si es colección.
     */
    public function relaciones_omitidas()
    {
        $omitidas = [];

        // Solo mapPreciosBlanco() usa las columnas en blanco, y solo con la extensión.
        if (!ExportHelper::tiene_extencion('articulos_precios_en_blanco')) {
            $omitidas['article_discounts_blanco'] = false;
            $omitidas['article_surchages_blanco'] = false;
        }

        // Solo map_propiedades_de_distribuidora() lee el tipo de envase, y solo con la extensión.
        if (!ExportHelper::tiene_extencion('propiedades_de_distribuidora')) {
            $omitidas['tipo_envase'] = true;
        }

        if (count(ExportHelper::getAddresses()) < 1) {
            $omitidas['addresses'] = false;
        }

        /*
         * Las listas de precio se leen de uno de tres lugares (get_price_types_values_in_order):
         * price_type_monedas con listas_de_precio + ventas_en_dolares; el pivot price_types con
         * listas_de_precio sin dólares o con lista_de_precios_por_categoria; o un atributo con el
         * nombre de la lista (el "caso Colman"), que no necesita ninguno de los dos.
         */
        $hay_listas = count(ExportHelper::getPriceTypes()) >= 1;
        $usa_listas = ExportHelper::usa_listas_de_precio();
        $en_dolares = ExportHelper::tiene_extencion('ventas_en_dolares');

        if (!($hay_listas && $usa_listas && $en_dolares)) {
            $omitidas['price_type_monedas'] = false;
        }

        $lee_pivot = $usa_listas
            ? !$en_dolares
            : ExportHelper::tiene_extencion('lista_de_precios_por_categoria');

        if (!($hay_listas && $lee_pivot)) {
            $omitidas['price_types'] = false;
        }

        return $omitidas;
    }

    /**
     * relaciones() menos las omitidas para este comercio.
     *
     * @return array
     */
    public function relaciones_a_cargar()
    {
        $omitidas = $this->relaciones_omitidas();

        return array_values(array_filter(self::relaciones(), function ($relacion) use ($omitidas) {
            return !array_key_exists($relacion, $omitidas);
        }));
    }

    /**
     * Deja vacías las relaciones omitidas, para que un helper que igual las toque (setPriceTypes()
     * ordena price_types siempre que haya listas) no dispare una consulta por artículo.
     *
     * @param \Illuminate\Support\Collection $articles
     * @return void
     */
    protected function completar_relaciones_omitidas($articles)
    {
        $omitidas = $this->relaciones_omitidas();

        foreach ($articles as $article) {
            foreach ($omitidas as $relacion => $es_modelo) {
                if (!$article->relationLoaded($relacion)) {
                    $article->setRelation($relacion, $es_modelo ? null : new EloquentCollection());
                }
            }
        }
    }

    /**
     * Columnas de `articles` que lee el Excel (map() y ExportHelper). `articles` tiene más de cien
     * columnas y el Excel usa menos de cuarenta: hidratar solo éstas es la mitad del trabajo por
     * artículo, y deja afuera `embedding` (hasta 29 KB por fila).
     *
     * Las listas de precio sin listas_de_precio (el "caso Colman") y el stock por depósito se leen
     * como $article->{nombre}: si alguno de esos nombres es una columna real, se trae también,
     * para que salga exactamente lo mismo que antes.
     *
     * @return array Columnas prefijadas con la tabla.
     */
    public function columnas()
    {
        $leidas = [
            'id', 'user_id', 'status',
            'bar_code', 'sku', 'provider_code', 'name', 'provider_id',
            'cost_in_dollars', 'cost', 'iva_id', 'aplicar_iva',
            'percentage_gain', 'price', 'final_price', 'previus_final_price',
            'category_id', 'sub_category_id', 'brand_id', 'descripcion', 'unidad_medida_id', 'unidades_individuales',
            'stock', 'stock_min',
            'espesor', 'modelo', 'pastilla', 'diametro', 'litros', 'contenido', 'cm3', 'calipers', 'juego',
            'tipo_envase_id', 'unidades_por_bulto',
            'percentage_gain_blanco', 'final_price_blanco',
            'created_at', 'updated_at',
        ];

        foreach (ExportHelper::getPriceTypes() as $price_type) {
            $leidas[] = $price_type->name;
        }
        foreach (ExportHelper::getAddresses() as $address) {
            $leidas[] = $address->street;
            $leidas[] = 'stock_min_'.$address->street;
            $leidas[] = 'stock_max_'.$address->street;
        }

        $leidas = array_flip($leidas);
        $columnas = [];

        // columnas_sin_embedding() ya viene prefijada ('articles.x') y cacheada.
        foreach (Article::columnas_sin_embedding() as $columna) {
            $partes = explode('.', $columna);
            if (isset($leidas[end($partes)])) {
                $columnas[] = $columna;
            }
        }

        return $columnas;
    }

    /**
     * Consulta de los artículos del dueño con las columnas y relaciones que usa el Excel.
     *
     * @param bool $solo_activos
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function consulta_articulos($solo_activos = true)
    {
        if (is_null($this->columnas_cache)) {
            $this->columnas_cache = $this->columnas();
        }

        if (is_null($this->relaciones_cache)) {
            $this->relaciones_cache = $this->relaciones_a_cargar();
        }

        $query = Article::where('articles.user_id', $this->user_id)
                        ->select($this->columnas_cache)
                        ->with($this->relaciones_cache);

        if ($solo_activos) {
            $query->where('articles.status', 'active');
        }

        return $query;
    }

    /**
     * Arma las filas del Excel para un lote de artículos: descuentos y recargos formateados,
     * stock por depósito, listas de precio ordenadas y una fila más por cada variante.
     *
     * @param \Illuminate\Support\Collection $articles Artículos con relaciones() cargadas.
     * @return \Illuminate\Support\Collection
     */
    public function preparar_filas($articles)
    {
        $this->completar_relaciones_omitidas($articles);

        // Aplico descuentos y recargos, en negro y en blanco
        $articles = ExportHelper::set_descuentos_y_recargos($articles);
        $articles = ExportHelper::setAddresses($articles);
        $articles = ExportHelper::setPriceTypes($articles);

        // Expandir colección con variantes
        return $this->expandWithVariants($articles);
    }

    /**
     * Camino de maatwebsite (Excel::download / Excel::store). La exportación del listado ya no
     * pasa por acá sino por ArticleExportStreamer, que escribe por lotes; esto queda para la
     * planilla base (archivo_base, solo encabezados) y para cualquier llamada con pocos modelos.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        set_time_limit(999999);
        if ($this->archivo_base) {
            $articles = collect();
        } else if (!is_null($this->models)) {
            $articles = $this->models;
            if ($articles instanceof EloquentCollection) {
                $articles->loadMissing(self::relaciones());
            }
        } else {
            $articles = $this->consulta_articulos(true)
                            ->orderBy('articles.id', 'DESC')
                            ->get();
        }

        return $this->preparar_filas($articles);
    }

    public function headings(): array
    {
        $headings = $this->get_base_headings();
        
        // $headings = ExportHelper::set_unidades_individuales($headings);

        $headings = ExportHelper::set_props_autopartes($headings);
        
        $headings = ExportHelper::set_propiedades_de_distribuidora($headings);
        
        $headings = ExportHelper::setAddressesHeadings($headings);
        
        $headings = ExportHelper::setPropertyTypesHeadings($headings);
        
        $headings = ExportHelper::setPriceTypesHeadings($headings);
        
        $headings = ExportHelper::setPreciosBlancoHeadings($headings);
        
        $headings = ExportHelper::setDatesHeadings($headings);
        // $headings = ExportHelper::setChartsheadings($headings);
        return $headings;
    }

    function getCostInDollars($article) {
        if (ExportHelper::valor($article, 'cost_in_dollars')) {
            return 'USD';
        }
        return 'ARS';
    }

    function getUnidadMedida($article) {
        if (!is_null($article->unidad_medida)) {
            return $article->unidad_medida->name;
        }
        return null;
    }

    // function setDiscounts($articles) {
    //     foreach ($articles as $article) {
    //         $article->discounts_formated = '';
    //         if (count($article->article_discounts) >= 1) {
    //             foreach ($article->article_discounts as $discount) {
    //                 $article->discounts_formated .= $discount->percentage.'_';
    //             }
    //             $article->discounts_formated = substr($article->discounts_formated, 0, strlen($article->discounts_formated)-1);
    //         }
    //     }
    //     return $articles;
    // }

}