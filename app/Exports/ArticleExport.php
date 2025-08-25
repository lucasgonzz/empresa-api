<?php

namespace App\Exports;

use App\Models\Article;
use App\Http\Controllers\Helpers\ExportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\Log;

class ArticleExport implements FromCollection, WithHeadings, WithMapping
{

    public $models = null;
    public $archivo_base = false;

    function __construct($models, $archivo_base = false) {
        $this->models = $models;
        $this->archivo_base = $archivo_base;
    }

    public function map($row): array
    {
        $article = $row; // alias más claro

        $map = [
            $article->id,
            $article->bar_code,
            $article->provider_code,
            $article->name,
            !is_null($article->category) ? $article->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            !is_null($article->brand) ? $article->brand->name : '',
            $row->is_variant ? $row->variant->stock : $article->stock,
            $article->stock_min,
            !is_null($article->iva) ? $article->iva->percentage : '',
            !is_null($article->provider) ? $article->provider->name : '',
            $article->cost,
            $article->percentage_gain,
            $article->discounts_percentage_formated,
            $article->surchages_percentage_formated,
            $article->discounts_amount_formated,
            $article->surchages_amount_formated,
            $article->price,
            $this->getCostInDollars($article),
            $this->getUnidadMedida($article),
            $article->final_price,
        ];

        // Ahora continuás con los helpers
        $map = ExportHelper::map_unidades_individuales($map, $article);
        $map = ExportHelper::map_propiedades_de_distribuidora($map, $article);


        // Si es variante, completamos columnas extras
        if ($row->is_variant && $row->variant) {
            // $variant = $row->variant;
            // Por ejemplo, índice 20: Talle, 21: Color, 22: Stock variante, 23+: stock por depósito
            $map = ExportHelper::map_variant_stock_addresses($map, $row);

            $map = ExportHelper::map_property_types($map, $row);
           

        } else {
            // Si no es variante, rellenar con valores vacíos en esas columnas
            $map = ExportHelper::mapAddresses($map, $article);

            $map = ExportHelper::map_property_types_vacios($map);
        }


        $map = ExportHelper::mapPriceTypes($map, $article);
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
            $articles = Article::where('user_id', UserHelper::userId())
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
        $headings = [
            'Numero',
            'Codigo de barras',
            'Codigo de proveedor',
            'Nombre',
            'Categoria',
            'Sub Categoria',
            'Marca',
            'Stock actual',
            'Stock minimo',
            'Iva',
            'Proveedor',
            'Costo',
            'Margen de ganancia',
            'Descuentos',
            'Recargos',
            'Descuentos montos',
            'Recargos montos',
            'Precio',
            'Moneda',
            'Unidad medida',
            'Precio Final',
        ];
        
        $headings = ExportHelper::set_unidades_individuales($headings);
        
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
