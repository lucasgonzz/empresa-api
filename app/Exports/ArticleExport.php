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
            $article->id,
            $article->bar_code,
            $article->sku,
            $article->provider_code,
            $article->name,
            !is_null($article->provider) ? $article->provider->name : '',

            $this->getCostInDollars($article),
            $article->cost,

            $article->discounts_percentage_formated,
            $article->surchages_percentage_formated,
            $article->discounts_amount_formated,
            $article->surchages_amount_formated,

            !is_null($article->iva) ? $article->iva->percentage : '',
            $article->aplicar_iva ? 'Si' : 'No',
            $article->percentage_gain,
            $article->price,
            $article->final_price,
            $article->previus_final_price,

            !is_null($article->category) ? $article->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            !is_null($article->brand) ? $article->brand->name : '',
            $article->descripcion,
            $this->getUnidadMedida($article),
            $article->unidades_individuales,
        ];

        $map = ExportHelper::map_autopartes($map, $article);

        $map = ExportHelper::map_propiedades_de_distribuidora($map, $article);

        // Depósitos del usuario: reemplazan "Stock actual" / "Stock minimo" por columnas por sucursal.
        $addresses = ExportHelper::getAddresses();
        if (count($addresses) >= 1) {

            if ($row->is_variant && $row->variant) {

                $map = ExportHelper::map_variant_stock_addresses($map, $row);
            } else {

                $map = ExportHelper::mapAddresses($map, $article);
            }
        } else {

            // Sin sucursales: conservar stock del artículo o stock de la variante en la misma posición que headings.
            $map[] = $row->is_variant && $row->variant ? $row->variant->stock : $article->stock;
            $map[] = $article->stock_min;
        }

        if ($row->is_variant && $row->variant) {

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

            // 2) insertar valores en la MISMA posición que headings (después de Aplicar Iva)
            $headings_pre_price_types = $this->get_headings_pre_price_types();
            $aplicar_iva_index = array_search('Aplicar Iva', $headings_pre_price_types);

            $values = ExportHelper::get_price_types_values_in_order($article);

            // insertar después de aplicar_iva
            array_splice($map, $aplicar_iva_index + 1, 0, $values);

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

        $query = Article::where('articles.user_id', $this->user_id)
                        ->select($this->columnas_cache)
                        ->with(self::relaciones());

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
        if ($article->cost_in_dollars) {
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