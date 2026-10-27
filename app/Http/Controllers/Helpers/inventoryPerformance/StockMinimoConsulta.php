<?php

namespace App\Http\Controllers\Helpers\inventoryPerformance;

use App\Http\Controllers\Helpers\article\PermisoStockPorSucursalHelper;
use App\Models\Address;
use App\Models\InventoryPerformance;
use Illuminate\Http\Request;

/** Alcance único de filas, resumen y Excel del ticket #21. Nunca confía en el selector de la SPA. */
class StockMinimoConsulta
{
    public $owner_id;
    public $restricted;
    public $address_id;
    public $addresses;
    public $search;

    public function __construct($persona, $owner_id, Request $request)
    {
        abort_if(is_null($persona), 401);
        $this->owner_id = $owner_id;
        $this->restricted = !PermisoStockPorSucursalHelper::es_admin($persona)
            && PermisoStockPorSucursalHelper::tiene_permiso($persona, 'article.stock_only_sucursal');
        $this->addresses = Address::sucursales_de($owner_id)->orderBy('id')->get(['id', 'street']);
        if ($this->restricted) {
            $this->addresses = $this->addresses->filter(function ($address) use ($persona) {
                return (int) $address->id === (int) $persona->address_id;
            })->values();
        }
        $request->validate(['address_id' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $this->address_id = $request->filled('address_id') ? (int) $request->address_id : null;
        if (!is_null($this->address_id) && !$this->addresses->contains('id', $this->address_id)) {
            abort(403, 'No tenés autorización para consultar esa sucursal.');
        }
        if ($this->restricted) {
            // Sin asignación válida se cierra el alcance: null JAMÁS significa general para un restringido.
            $this->address_id = $this->addresses->count() ? (int) $this->addresses->first()->id : null;
        }
        $this->search = (string) $request->input('search', '');
    }

    public function metadata()
    {
        return ['restricted' => $this->restricted, 'address_id' => $this->address_id,
            'can_select' => !$this->restricted, 'addresses' => $this->addresses->values()];
    }

    public function reporte()
    {
        return InventoryPerformance::where('user_id', $this->owner_id)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->first();
    }

    public function query($reporte)
    {
        $query = $reporte->articles_stock_minimo()->where('articles.user_id', $this->owner_id);
        $ids = $this->addresses->pluck('id')->all();
        if ($this->restricted && is_null($this->address_id)) {
            $query->whereRaw('1 = 0');
        } elseif (!is_null($this->address_id)) {
            $query->wherePivot('address_id', $this->address_id);
        } else {
            $query->where(function ($q) use ($ids) {
                $q->whereNull('article_inventory_performance.address_id')
                    ->orWhereIn('article_inventory_performance.address_id', $ids);
            });
        }
        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('articles.name', 'like', '%'.$search.'%')
                    ->orWhere('articles.bar_code', 'like', '%'.$search.'%')
                    ->orWhere('articles.provider_code', 'like', '%'.$search.'%');
            });
        }
        return $query;
    }

    public function resumen($reporte)
    {
        if (is_null($reporte)) {
            return ['stock_minimo' => 0, 'sin_stock' => 0, 'stock_negativo' => 0, 'costo_reposicion_stock_minimo' => 0];
        }
        // SQL agregado sobre las alertas, sin hidratar el catálogo completo en cada pedido.
        $stock = 'CASE WHEN article_inventory_performance.address_id IS NULL THEN COALESCE(article_inventory_performance.stock_address, articles.stock) ELSE article_inventory_performance.stock_address END';
        $min = 'CASE WHEN article_inventory_performance.address_id IS NULL THEN COALESCE(article_inventory_performance.stock_min_address, articles.stock_min) ELSE article_inventory_performance.stock_min_address END';
        $cost = 'articles.cost * COALESCE(articles.presentacion, 1) / CASE WHEN articles.unidades_individuales > 0 THEN articles.unidades_individuales ELSE 1 END';
        $fila = $this->query($reporte)->selectRaw('COUNT(*) AS cantidad, SUM(CASE WHEN '.$stock.' <= 0 THEN 1 ELSE 0 END) AS sin_stock, SUM(CASE WHEN '.$stock.' < 0 THEN 1 ELSE 0 END) AS negativo, SUM(GREATEST(0, '.$min.' - '.$stock.') * ('.$cost.')) AS costo')->toBase()->first();
        return ['stock_minimo' => (int) $fila->cantidad, 'sin_stock' => (int) $fila->sin_stock,
            'stock_negativo' => (int) $fila->negativo, 'costo_reposicion_stock_minimo' => (float) $fila->costo];
    }

    public function preparar_fila($article)
    {
        if (!is_null($article->pivot->address_id)) {
            // Las columnas configurables también leen stock/stock_min: no deben revelar el global.
            $article->stock = $article->pivot->stock_address;
            $article->stock_min = $article->pivot->stock_min_address;
            $article->stock_max = null;
        } elseif (!is_null($article->pivot->stock_address)) {
            $article->stock = $article->pivot->stock_address;
            $article->stock_min = $article->pivot->stock_min_address;
        }
        return $article;
    }
}
