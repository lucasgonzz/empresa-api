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

    function __construct($models) {
        $this->models = $models;
    }

    public function map($article): array
    {
        $map = [
            $article->num,
            $article->bar_code,
            $article->provider_code,
            $article->name,
            !is_null($article->category) ? $article->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            $article->stock,
            $article->stock_min,
            !is_null($article->iva) ? $article->iva->percentage : '',
            !is_null($article->provider) ? $article->provider->name : '',
            $article->cost,
            $article->percentage_gain,
            $article->discounts_formated,
            $article->price,
            $this->getCostInDollars($article),
            $this->getUnidadMedida($article),
            $article->final_price,
            $article->created_at,
            $article->updated_at,
        ];
        $map = ExportHelper::mapAddresses($map, $article);
        $map = ExportHelper::mapPriceTypes($map, $article);
        return $map;
    }


    public function collection()
    {
        set_time_limit(999999);
        if (!is_null($this->models)) {
            $articles = $this->models;
        } else {
            $articles = Article::where('user_id', UserHelper::userId())
                            ->where('status', 'active')
                            ->with('iva')
                            ->with('article_discounts')
                            ->with('providers')
                            ->with('sub_category')
                            ->with('addresses')
                            ->with('price_types')
                            ->orderBy('created_at', 'DESC')
                            ->get();
        }

        $articles = $this->setDiscounts($articles);
        $articles = ExportHelper::setAddresses($articles);
        $articles = ExportHelper::setPriceTypes($articles);
        
        return $articles;
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
            'Stock actual',
            'Stock minimo',
            'Iva',
            'Proveedor',
            'Costo',
            'Margen de ganancia',
            'Descuentos',
            'Precio',
            'Moneda',
            'Unidad medida',
            'Precio Final',
            'Ingresado',
            'Actualizado',
        ];
        $headings = ExportHelper::setAddressesHeadings($headings);
        $headings = ExportHelper::setPriceTypesHeadings($headings);
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

    function setDiscounts($articles) {
        foreach ($articles as $article) {
            $article->discounts_formated = '';
            if (count($article->article_discounts) >= 1) {
                foreach ($article->article_discounts as $discount) {
                    $article->discounts_formated .= $discount->percentage.'_';
                }
                $article->discounts_formated = substr($article->discounts_formated, 0, strlen($article->discounts_formated)-1);
            }
        }
        return $articles;
    }

}
