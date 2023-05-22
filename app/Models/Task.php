<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('from_user', 'to_users');        
    }

    function from_user() {
        return $this->belongsTo(User::class, 'from_user_id');
    } 

    function to_users() {
        return $this->belongsToMany(User::class)->withPivot('is_finished');
    } 
}
