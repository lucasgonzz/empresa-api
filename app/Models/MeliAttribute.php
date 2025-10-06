<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeliAttribute extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
    }

    function meli_attributes_values() {
        return $this->hasMany(MeliAttributeValue::class);
    }

    function meli_attributes_tags() {
        return $this->hasMany(MeliAttributeTag::class);
    }
}
