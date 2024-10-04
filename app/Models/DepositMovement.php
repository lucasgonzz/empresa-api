<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepositMovement extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles.article_variants');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'article_variant_id');
    }

    function deposit_movement_status() {
        return $this->belongsTo(DepositMovementStatus::class);
    }

    function from_address() {
        return $this->belongsTo(Address::class, 'from_address_id');
    }

    function to_address() {
        return $this->belongsTo(Address::class, 'to_address_id');
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
