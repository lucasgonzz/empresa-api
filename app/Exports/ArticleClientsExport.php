<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\Excel\Article\ArticleClientsExportHelper;
use App\Http\Controllers\Helpers\ExportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ArticleClientsExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{

    public function __construct($price_type_id) {
        $this->price_type_id = $price_type_id;

        $this->price_types = PriceType::where('user_id', UserHelper::userId())
                                        ->get();

        $this->columnas_cambio_precio = [];
    }

    public function map($article): array
    {
        $map = [
            $article->num,
            $article->bar_code,
            $article->provider_code,
            $article->name,
            $article->category ? $article->category->name : '',
            date_format($article->updated_at, 'd/m/Y'),
        ];

        if (count($this->price_types) >= 1) {
            $map = ArticleClientsExportHelper::map_price_types($map, $article, $this->price_type_id);
        } else {
            $map[] = $article->final_price;
        }

        return $map;
    }

    
    public function registerEvents(): array {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Obtener última fila con datos
                $rowCount = $sheet->getHighestRow();

                foreach ($this->columnas_cambio_precio as $col) {
                    for ($row = 2; $row <= $rowCount; $row++) {
                        $cell = $col . $row;
                        $value = $sheet->getCell($cell)->getValue();

                        if ($value === 'Aumento') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFCCFFCC'); // verde claro
                        } elseif ($value === 'Disminuyo') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFFFCCCC'); // rojo claro
                        }
                    }
                }
            },
        ];
    }


    public function collection()
    {
        set_time_limit(999999);
        $articles = $this->get_articles();
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
            'Fecha modificacion',
        ];

        if (count($this->price_types) >= 1) {
            
            $res = ArticleClientsExportHelper::set_price_types_headings($headings, $this->price_type_id);
            $headings = $res['headings'];
            $this->columnas_cambio_precio = $res['columnas_cambio_precio'];

        } else {

            $headings[] = 'Precio';
        }
        
        return $headings;
    }

    function get_articles() {

        $articles = [];

        if (UserHelper::hasExtencion('elegir_si_incluir_lista_de_precios_de_excel')) {

            $price_type_id = $this->price_type_id;

            $articles = Article::whereHas('price_types', function ($query) use ($price_type_id) {
                                    $query->where('price_type_id', $price_type_id)
                                          ->where('incluir_en_excel_para_clientes', 1);
                                })
                                ->orderBy('id', 'DESC')
                                ->get();
        } else {

            $articles = Article::where('user_id', UserHelper::userId())
                                    ->where('status', 'active')
                                    ->orderBy('id', 'DESC')
                                    ->get();
        }

        return $articles;
    }
}
