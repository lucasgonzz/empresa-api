<?php

namespace App\Models;

use App\Http\Controllers\Helpers\combo\ComboCalculadoEsquemaHelper;
use App\Http\Controllers\Helpers\combo\ComboStockHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Combo extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * `stock_disponible` viaja en TODA serialización del combo (listado, búsqueda de Vender, alta y
     * edición) sin que el SPA tenga que pedirlo. Es calculado y no una columna: ver
     * ComboStockHelper.
     *
     * @var array
     */
    protected $appends = ['stock_disponible'];

    /**
     * 🔴 `price_types` va detrás de la guarda de esquema: la tabla `combo_price_type` la crea una
     * migración de la misión combos-calculados y, en la ventana de un deploy (código nuevo, base
     * todavía sin migrar), un `with('price_types')` es un `SQLSTATE[42S02]` que tumba el listado de
     * combos y CADA venta que los cargue. Sin la tabla, el combo se serializa sin `price_types` y
     * el SPA usa `price` (que es lo que hacía antes).
     */
    function scopeWithAll($query) {

        $relaciones = ['articles', 'images'];

        if (ComboCalculadoEsquemaHelper::disponible()) {
            $relaciones[] = 'price_types';
        }

        $query->with($relaciones);
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount');
    }

    /**
     * Las fotos del combo, en la tabla `images` de siempre (`imageable_type = 'combo'`, alias del
     * morph map de AppServiceProvider). Es la misma relación y el mismo formato que
     * `Article::images()`, así que el componente de imágenes del SPA y la tienda las leen igual.
     */
    function images() {
        return $this->morphMany('App\Models\Image', 'imageable');
    }

    /**
     * El precio del combo en cada lista de precios (solo combos calculados en cuentas con listas).
     * Mismo formato que `Article::price_types()` pero el pivote lleva `price`, no `final_price`.
     * Ver ComboCalculadoHelper y la migración de `combo_price_type`.
     */
    function price_types() {
        return $this->belongsToMany('App\Models\PriceType', 'combo_price_type')->withPivot('price');
    }

    public function sales() {
        return $this->belongsToMany('App\Models\Sale')->withPivot('amount', 'price');
    }

    /**
     * Cuántos combos se pueden armar con el stock actual de sus componentes (int), o null si
     * ningún componente lleva stock (sin control). La regla completa está en ComboStockHelper.
     *
     * Se calcula sobre la relación `articles` (que trae los borrados y el `pivot->amount`); si no
     * estaba cargada la carga acá. Es lo que se paga por serializar un combo sin sus artículos:
     * una consulta. Los caminos normales (`scopeWithAll`, `Sale::scopeWithAll` con
     * `combos.articles`) ya la traen.
     *
     * Un combo que todavía no se guardó no tiene componentes en la base: null.
     *
     * @return int|null
     */
    public function getStockDisponibleAttribute() {

        if (!$this->exists) {
            return null;
        }

        $this->loadMissing('articles');

        return ComboStockHelper::calcular_de_articulos($this->articles);
    }
}
