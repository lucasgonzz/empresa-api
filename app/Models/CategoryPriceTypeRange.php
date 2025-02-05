<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryPriceTypeRange extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('category', 'sub_category', 'price_type');
    }

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function sub_category() {
        return $this->belongsTo(SubCategory::class);
    }

    public function price_type() {
        return $this->belongsTo(PriceType::class);
    }
}
