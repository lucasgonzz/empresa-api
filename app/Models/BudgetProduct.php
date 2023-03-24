<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetProduct extends Model
{
    protected $guarded = [];

    function article_stocks() {
        return $this->hasMany('App\Models\BudgetProductArticleStock');
    }

    function deliveries() {
        return $this->hasMany('App\Models\BudgetProductDelivery');
    }
}
