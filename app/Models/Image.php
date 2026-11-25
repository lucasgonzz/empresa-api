<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $guarded = [];

    protected $hidden = ['tn_pending_image_confirmation'];

    function imageable() {
        return $this->morphTo();
    }
}
