<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeRoute extends Model
{
    protected $guarded = [];

    function scopeWithAll($query)
    {
        $query->with('recipe', 'articles');
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function recipe_route_type()
    {
        return $this->belongsTo(RecipeRouteType::class);
    }
    
    public function articles()
    {
        return $this->belongsToMany(Article::class)
            ->withPivot('amount', 'notes', 'order_production_status_id', 'address_id')
            ->withTimestamps();
    }

    /*
     * Las dos relaciones de abajo NO se agregan a scopeWithAll a proposito: la ruta viaja anidada
     * adentro de cada receta y de cada lote, y son dos queries por ruta que ningun consumidor
     * necesita — la SPA resuelve los dos nombres contra sus propios stores.
     */

    public function order_production_status_group()
    {
        return $this->belongsTo(OrderProductionStatusGroup::class);
    }

    public function end_order_production_status()
    {
        return $this->belongsTo(OrderProductionStatus::class, 'end_order_production_status_id');
    }
}