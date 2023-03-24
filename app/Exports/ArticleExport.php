<?php

namespace App\Exports;

use App\Models\Article;
use App\Http\Controllers\Helpers\ExportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArticleExport implements FromCollection, WithHeadings, WithMapping
{

    public function map($article): array
    {
        $map = [
            $article->num,
            $article->bar_code,
            $article->provider_code,
            $article->name,
            !is_null($article->sub_category) ? $article->sub_category->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            $article->stock,
            $article->stock_min,
            !is_null($article->iva) ? $article->iva->percentage : '',
            count($article->providers) >= 1 ? $article->providers[count($article->providers)-1]->name : '',
            $article->cost,
            $article->percentage_gain,
            $article->discounts_formated,
            $article->price,
            $this->getCostInDollars($article),
            $article->final_price,
            $article->created_at,
            $article->updated_at,
        ];
        $map = ExportHelper::mapPriceTypes($map, $article);
        return $map;
    }


    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $articles = Article::where('user_id', UserHelper::userId())
                        // ->select('bar_code', 'provider_code', 'name', 'iva_id', 'cost', 'percentage_gain', 'price', 'stock', 'stock_min', 'created_at', 'updated_at')
                        ->where('status', 'active')
                        ->with('iva')
                        ->with('article_discounts')
                        ->with('providers')
                        ->with('sub_category')
                        ->orderBy('created_at', 'DESC')
                        ->get();
        // $articles = ArticleHelper::setPrices($articles);
        $articles = $this->setDiscounts($articles);
        // $articles = ArticleHelper::setDiscount($articles);
        $articles = ExportHelper::setPriceTypes($articles);
        return $articles;
    }

    public function headings(): array
    {
        $headings = [
            'Codigo',
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
            'Precio Final',
            'Ingresado',
            'Actualizado',
        ];
        $headings = ExportHelper::setPriceTypesHeadings($headings);
        return $headings;
    }

    function getCostInDollars($article) {
        if ($article->cost_in_dollars) {
            return 'USD';
        }
        return 'ARS';
    }

    function setDiscounts($articles) {
        foreach ($articles as $article) {
            $article->discounts_formated = '';
            if (count($article->article_discounts) >= 1) {
                foreach ($article->article_discounts as $discount) {
                    $article->discounts_formated .= $discount->percentage.'-';
                }
                $article->discounts_formated = substr($article->discounts_formated, 0, strlen($article->discounts_formated)-1);
            }
        }
        return $articles;
    }

}
