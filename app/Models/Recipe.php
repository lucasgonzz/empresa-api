<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('article.images', 'articles.addresses', 'recipe_routes.articles.images', 'recipe_routes.recipe_route_type');
    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withPivot('amount', 'notes', 'order_production_status_id', 'address_id');
    }

    function recipe_routes() {
        return $this->hasMany(RecipeRoute::class);
    }
}
