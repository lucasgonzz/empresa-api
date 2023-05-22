<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLinkage extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('client');        
    }

    function client() {
        return $this->belongsTo(Client::class);
    }
}
