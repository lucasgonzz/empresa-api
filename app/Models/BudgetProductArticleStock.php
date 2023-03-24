<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetProductArticleStock extends Model
{
    protected $guarded = [];

    function article() {
        return $this->belongsTo('App\Models\Article');
    }
}
