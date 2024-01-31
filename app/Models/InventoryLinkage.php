<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLinkage extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('client', 'categories');        
    }

    function client() {
        return $this->belongsTo(Client::class);
    }

    function categories() {
        return $this->belongsToMany(Category::class)->withPivot('percentage_discount');
    }
}
