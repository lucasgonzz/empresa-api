<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedVersionNotificationRead extends Model
{
    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
        'synced_to_admin_at' => 'datetime',
    ];

    function scopeWithAll($query) {
        $query->with('synced_version_notification', 'user');
    }

    public function synced_version_notification() {
        return $this->belongsTo(SyncedVersionNotification::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}
