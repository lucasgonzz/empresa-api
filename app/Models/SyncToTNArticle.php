<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncToTNArticle extends Model
{
    protected $guarded = [];

    protected $hidden = ['rate_limited_until', 'rate_limit_attempts'];

    public function scopePendingForOwner($query, int $userId)
    {
        return $query->where('user_id', $userId)->where('status', 'pendiente');
    }

    public static function owner_rate_limited(int $userId): bool
    {
        return static::where('user_id', $userId)
            ->where('rate_limited_until', '>', now())
            ->exists();
    }

    function scopeWithAll($q) {
        $q->with('article');
    }

    function article() {
        return $this->belongsTo(Article::class);
    }
}
