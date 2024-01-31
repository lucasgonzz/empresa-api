<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeLiOrder extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles.images', 'me_li_payments', 'me_li_buyer');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'price');
    }

    function me_li_payments() {
        return $this->hasMany(MeLiPayment::class);
    }

    function me_li_buyer() {
        return $this->belongsTo(MeLiBuyer::class);
    }
}
