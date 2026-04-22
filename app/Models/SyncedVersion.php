<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'is_current' => 'boolean',
    ];

    function scopeWithAll($query) {
        $query->with('notifications');
    }

    public function notifications() {
        return $this->hasMany(SyncedVersionNotification::class)->orderBy('sort_order');
    }
}
