<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeliCategory extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('meli_attributes.meli_attributes_values', 'meli_attributes.meli_attributes_tags');
    }

    function meli_attributes() {
        return $this->hasMany(MeliAttribute::class);
    }
}
