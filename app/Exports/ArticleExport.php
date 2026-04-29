<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\ExportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\User;
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

    function __construct($models, $user_id, $archivo_base = false) {
        $this->models = $models;
        $this->user_id = $user_id;
        $this->archivo_base = $archivo_base;

        $this->user = User::find($user_id);

        Log::info('user_id: '.$user_id);
        Log::info('user name: '.$this->user->name);
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

    public function map($row): array
    {
        $article = $row; // alias más claro

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

            !is_null($article->category) ? $article->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            !is_null($article->brand) ? $article->brand->name : '',
            $article->descripcion,
            $this->getUnidadMedida($article),
            $article->unidades_individuales,
            
            $row->is_variant ? $row->variant->stock : $article->stock,
            $article->stock_min,
        ];

        // Ahora continuás con los helpers
        // $map = ExportHelper::map_unidades_individuales($map, $article);

        $map = ExportHelper::map_autopartes($map, $article);

        $map = ExportHelper::map_propiedades_de_distribuidora($map, $article);

        $addresses = ExportHelper::getAddresses();
        if (count($addresses) >= 1) {
            $map = ExportHelper::unset_map_columns_by_titles(
                $map,
                $this->get_headings_pre_addresses(),
                ['Stock actual', 'Stock minimo']
            );
        }

        // Si es variante, completamos columnas extras
        if ($row->is_variant && $row->variant) {
            // $variant = $row->variant;
            // Por ejemplo, índice 20: Talle, 21: Color, 22: Stock variante, 23+: stock por depósito
     
            $map = ExportHelper::map_variant_stock_addresses($map, $row);

            $map = ExportHelper::map_property_types($map, $row);
           

        } else {
            // Si no es variante, rellenar con valores vacíos en esas columnas
            $map = ExportHelper::mapAddresses($map, $article);

            if (UserHelper::hasExtencion('article_variants', $this->user)) {
                $map = ExportHelper::map_property_types_vacios($map);
            }
        }

        $price_types = ExportHelper::getPriceTypes();
        if (count($price_types) >= 1) {

            // 1) sacar columnas viejas (ya lo hacés)
            $map = ExportHelper::unset_map_columns_by_titles(
                $map,
                $this->get_headings_pre_price_types(),
                ['Margen de ganancia', 'Precio', 'Precio Final']
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
    protected function expandWithVariants($articles)
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


    public function collection()
    {
        set_time_limit(999999);
        if ($this->archivo_base) {
            $articles = collect();
        } else if (!is_null($this->models)) {
            $articles = $this->models;
        } else {
            $articles = Article::where('user_id', $this->user->id)
                            ->where('status', 'active')
                            ->with('iva')
                            ->with('article_discounts')
                            ->with('article_surchages')
                            ->with('article_discounts_blanco')
                            ->with('article_surchages_blanco')
                            ->with('providers')
                            ->with('sub_category')
                            ->with('addresses')
                            ->with('price_types')
                            ->with('tipo_envase')
                            ->with('brand')
                            ->with('article_variants')
                            ->orderBy('id', 'DESC')
                            ->get();
        }

        // Aplico descuentos y recargos, en negro y en blanco
        $articles = ExportHelper::set_descuentos_y_recargos($articles);
        $articles = ExportHelper::setAddresses($articles);
        $articles = ExportHelper::setPriceTypes($articles);
        
        // Expandir colección con variantes
        return $this->expandWithVariants($articles);
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