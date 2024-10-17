<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticlePurchase extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function article()
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }
}
