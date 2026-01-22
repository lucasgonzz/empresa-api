<?php

namespace App\Exports;

use App\Models\ProviderOrder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProviderOrderExport implements FromArray, WithHeadings
{
    protected $provider_order_id;

    public function __construct($provider_order_id)
    {
        $this->provider_order_id = $provider_order_id;
    }

    public function headings(): array
    {
        return [
            'Nombre',
            'Código de Barras',
            'Código Proveedor',
            'Cantidad',
        ];
    }

    public function array(): array
    {
        $provider_order = ProviderOrder::with('articles')->findOrFail($this->provider_order_id);

        return $provider_order->articles->map(function ($article) {
            return [
                $article->name,
                $article->bar_code,
                $article->provider_code,
                $article->pivot->amount,
            ];
        })->toArray();
    }
}
