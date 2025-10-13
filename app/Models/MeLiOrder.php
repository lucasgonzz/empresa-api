<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeliOrder extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('meli_buyer', 'articles.images', 'tags', 'cancel_detail', 'sale');
    }


    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    public function meli_buyer()
    {
        return $this->belongsTo(MeliBuyer::class);
    }

    public function articles()
    {
        return $this->belongsToMany(Article::class, 'meli_order_article')
                    ->withPivot(['amount', 'price'])
                    ->withTimestamps();
    }

    public function tags()
    {
        return $this->hasMany(MeliOrderTag::class, 'meli_order_id');
    }

    public function cancel_detail()
    {
        return $this->hasOne(MeliOrderCancelDetail::class, 'meli_order_id');
    }
}
