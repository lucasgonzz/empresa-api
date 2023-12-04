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
            !is_null($article->provider) ? $article->provider->name : null,
            $article->name,
            // $article->rentabilidad,
        ];
        $map = ArticleSalesExportHelper::mapCharts($map, $article);
        return $map;
    }


    public function collection()
    {
        set_time_limit(999999);
        $articles = ArticleSalesExportHelper::setCharts($this->user);
        return $articles;
    }

    public function headings(): array
    {
        $headings = [
            'Numero',
            'Codigo de barras',
            'Codigo de proveedor',
            'Proveedor',
            'Nombre',
            // 'Rentabilidad',
        ];
        $headings = ArticleSalesExportHelper::setChartsheadings($headings);
        return $headings;
    }
}
