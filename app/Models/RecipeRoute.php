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
}