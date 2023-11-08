<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\ArticleSalesExportHelper;
use App\Models\Article;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArticleSalesExport implements FromCollection, WithHeadings, WithMapping
{

    function __construct($company_name) {
        $this->user = User::where('company_name', $company_name)
                            ->first();
    }

    public function map($article): array
    {
        $map = [
            $article->num,
            $article->bar_code,
            $article->provider_code,
            $article->name,
        ];
        $map = ArticleSalesExportHelper::mapCharts($map, $article);
        return $map;
    }


    public function collection()
    {
        set_time_limit(999999);
        // $articles = Article::where('user_id', $this->user->id)
        //                 ->where('status', 'active')
        //                 ->orderBy('created_at', 'DESC')
        //                 ->get();
        // $articles = ArticleSalesExportHelper::setCharts($articles, $this->user);
        $articles = ArticleSalesExportHelper::setCharts($this->user);
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
        $headings = ArticleSalesExportHelper::setChartsheadings($headings);
        return $headings;
    }
}
