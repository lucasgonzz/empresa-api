<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepositMovement extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount');
    }

    function deposit_movement_status() {
        return $this->belongsTo(DepositMovementStatus::class);
    }
}
