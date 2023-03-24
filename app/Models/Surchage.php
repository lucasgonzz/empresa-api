<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Surchage extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    function scopeWithAll($q) {
        
    }
}
