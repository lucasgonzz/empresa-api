<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportStatus extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('provider');
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }
}
