<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialPrice extends Model
{
    protected $fillable = ['user_id', 'name'];

    public function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('price');
    }
}
