<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArticleStockMinimoExport implements FromCollection, WithHeadings, WithMapping
{

    public function map($article): array
    {
        $map = [
            $article->id,
            $article->bar_code,
            $article->provider_code,
            $article->name,
            !is_null($article->category) ? $article->category->name : '',
            !is_null($article->sub_category) ? $article->sub_category->name : '',
            $article->stock,
            $article->stock_min,
            !is_null($article->provider) ? $article->provider->name : '',
            $article->final_price,
            $article->stock_updated_at->format('d/m/Y'),
            $article->created_at->format('d/m/Y'),
            $article->updated_at->format('d/m/Y'),
        ];
        return $map;
    }


    public function collection()
    {
        
        
        $articles = Article::where('user_id', UserHelper::userId())
                            ->orderBy('id', 'ASC')
                            ->get();

        $stock_minimo = [];

        foreach ($articles as $article) {
            
            if (
                !is_null($article->stock_min)
                && !is_null($article->stock)
                && $article->stock < $article->stock_min
            ) {

                $stock_minimo[] = $article;
            }
        }

        return collect($stock_minimo);
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
            'Proveedor',
            'Precio Final',
            'Stock Actualizado',
            'Creado',
            'Actualizado',
        ];
        return $headings;
    }
}
