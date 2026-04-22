<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncedVersionNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    function scopeWithAll($query) {
        $query->with('synced_version', 'reads');
    }

    public function synced_version() {
        return $this->belongsTo(SyncedVersion::class);
    }

    public function reads() {
        return $this->hasMany(SyncedVersionNotificationRead::class);
    }
}
