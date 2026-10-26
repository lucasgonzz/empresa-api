<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Http\Controllers\Helpers\inventoryPerformance\StockMinimoConsulta;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArticleStockMinimoExport implements FromCollection, WithHeadings, WithMapping
{

    protected $consulta;

    public function __construct(StockMinimoConsulta $consulta = null)
    {
        $this->consulta = $consulta ?: new StockMinimoConsulta(request()->user(), UserHelper::userId(), request());
    }

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
            optional($article->stock_updated_at)->format('d/m/Y'),
            $article->created_at->format('d/m/Y'),
            $article->updated_at->format('d/m/Y'),
            $this->consulta->addresses->firstWhere('id', $article->pivot->address_id)->street ?? 'General',
        ];
        return $map;
    }


    public function collection()
    {
        $reporte = $this->consulta->reporte();
        if (is_null($reporte)) { return collect(); }
        return $this->consulta->query($reporte)->select(Article::columnas_sin_embedding())
            ->with(['category', 'sub_category', 'provider'])->orderBy('articles.id')->get()
            ->map(function ($article) { return $this->consulta->preparar_fila($article); });
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
            'Depósito',
        ];
        return $headings;
    }
}
