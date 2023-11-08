<?php

namespace App\Exports;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Helpers\ExportHelper;
use App\Models\Article;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArticleClientsExport implements FromCollection, WithHeadings, WithMapping
{

    public function map($article): array
    {
        $map = [
            $article->num,
            $article->bar_code,
            $article->provider_code,
            $article->name,
        ];
        $map = ExportHelper::mapPriceTypes($map, $article);
        return $map;
    }


    public function collection()
    {
        set_time_limit(999999);
        $articles = Article::where('user_id', UserHelper::userId())
                        ->where('status', 'active')
                        ->orderBy('created_at', 'DESC')
                        ->get();
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
        ];
        $headings = ExportHelper::setPriceTypesHeadings($headings);
        return $headings;
    }
}
