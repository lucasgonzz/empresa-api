<?php

namespace App\Exports;

use App\Models\Address;
use App\Models\ProviderOrder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Excel de una compra: artículos con su cantidad y el stock actual (global y por sucursal).
 *
 * WithStrictNullComparison: sin él, Laravel Excel trata el 0 como celda vacía y un artículo sin
 * stock se vería igual que uno sin dato. Con él, solo null queda en blanco y el 0 sale como 0.
 */
class ProviderOrderExport implements FromArray, WithHeadings, WithStrictNullComparison
{
    protected $provider_order_id;

    /**
     * Compra con sus artículos y el stock de cada uno por sucursal. Se carga una sola vez para que
     * headings() y array() lean la misma compra.
     *
     * @var \App\Models\ProviderOrder|null
     */
    protected $provider_order = null;

    /**
     * Sucursales del dueño de la compra, en orden de alta. Vacía si el comercio no tiene.
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $addresses = null;

    public function __construct($provider_order_id)
    {
        $this->provider_order_id = $provider_order_id;
    }

    /**
     * Compra a exportar, con `articles.addresses` ya cargado (sin consultas por artículo).
     *
     * @return \App\Models\ProviderOrder
     */
    protected function provider_order()
    {
        if (is_null($this->provider_order)) {
            $this->provider_order = ProviderOrder::with('articles.addresses')->findOrFail($this->provider_order_id);
        }

        return $this->provider_order;
    }

    /**
     * Sucursales del dueño de la compra.
     *
     * Se toma el dueño de la propia compra y no el de la sesión: la ruta del Excel se abre con
     * window.open y no lleva autenticación, así que no hay usuario logueado de dónde sacarlo.
     * Mismo criterio que ArticleExport: con una sola sucursal ya cuenta como "tiene sucursales".
     *
     * @return \Illuminate\Support\Collection
     */
    protected function addresses()
    {
        if (is_null($this->addresses)) {
            $this->addresses = Address::where('user_id', $this->provider_order()->user_id)
                                        ->orderBy('id', 'ASC')
                                        ->get();
        }

        return $this->addresses;
    }

    public function headings(): array
    {
        $headings = [
            'Nombre',
            'Código de Barras',
            'Código Proveedor',
            'Cantidad',
            'Stock actual',
        ];

        // Las columnas nuevas van al final: el importador de compras mapea por columna elegida,
        // así que el orden de las cuatro originales no se toca.
        foreach ($this->addresses() as $address) {
            $headings[] = 'Stock '.$address->street;
        }

        return $headings;
    }

    public function array(): array
    {
        $addresses = $this->addresses();

        return $this->provider_order()->articles->map(function ($article) use ($addresses) {
            $row = [
                $article->name,
                $article->bar_code,
                $article->provider_code,
                $article->pivot->amount,
                $article->stock,
            ];

            // Un artículo sin fila en una sucursal tiene 0 ahí, no celda vacía.
            $stock_por_sucursal = $article->addresses->unique('id')->keyBy('id');

            foreach ($addresses as $address) {
                $article_address = $stock_por_sucursal->get($address->id);

                $row[] = $article_address ? (float) $article_address->pivot->amount : 0;
            }

            return $row;
        })->toArray();
    }
}
