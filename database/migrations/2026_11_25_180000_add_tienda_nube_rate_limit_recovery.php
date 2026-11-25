<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddTiendaNubeRateLimitRecovery extends Migration
{
    private const INDEX = 'tn_sync_owner_rate_limit_idx';

    public function up()
    {
        if (!Schema::hasColumn('sync_to_t_n_articles', 'rate_limited_until')) {
            Schema::table('sync_to_t_n_articles', function (Blueprint $table) {
                $table->dateTime('rate_limited_until')->nullable();
            });
        }
        if (!Schema::hasColumn('sync_to_t_n_articles', 'rate_limit_attempts')) {
            Schema::table('sync_to_t_n_articles', function (Blueprint $table) {
                $table->unsignedTinyInteger('rate_limit_attempts')->default(0);
            });
        }
        if (!$this->has_index()) {
            Schema::table('sync_to_t_n_articles', function (Blueprint $table) {
                $table->index(['user_id', 'rate_limited_until'], self::INDEX);
            });
        }
        if (!Schema::hasColumn('images', 'tn_pending_image_confirmation')) {
            Schema::table('images', function (Blueprint $table) {
                $table->string('tn_pending_image_confirmation', 64)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('images', 'tn_pending_image_confirmation')) {
            Schema::table('images', function (Blueprint $table) {
                $table->dropColumn('tn_pending_image_confirmation');
            });
        }
        if ($this->has_index()) {
            Schema::table('sync_to_t_n_articles', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        foreach (['rate_limited_until', 'rate_limit_attempts'] as $column) {
            if (Schema::hasColumn('sync_to_t_n_articles', $column)) {
                Schema::table('sync_to_t_n_articles', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function has_index(): bool
    {
        return count(DB::select('SHOW INDEX FROM sync_to_t_n_articles WHERE Key_name = ?', [self::INDEX])) > 0;
    }
}
