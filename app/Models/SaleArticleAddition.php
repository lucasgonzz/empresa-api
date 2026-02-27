<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleArticleAddition extends Model
{
    protected $table = 'sale_article_additions';

    protected $fillable = [
        'sale_id',
        'article_id',
        'article_variant_id',
        'price_type_personalizado_id',
        'previous_amount',
        'new_amount',
        'added_amount',
    ];
}
