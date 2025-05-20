<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoadMapClientPosition extends Model
{

    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function roadMap() {
        return $this->belongsTo(RoadMap::class);
    }

    public function client() {
        return $this->belongsTo(Client::class);
    }
}
