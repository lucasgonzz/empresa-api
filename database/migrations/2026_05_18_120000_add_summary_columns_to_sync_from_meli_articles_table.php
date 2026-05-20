<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen numérico de cada sincronización entrante ML → artículos locales.
 */
class AddSummaryColumnsToSyncFromMeliArticlesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('sync_from_meli_articles', function (Blueprint $table) {
            $table->unsignedInteger('meli_items_total')->default(0)->after('user_id');
            $table->unsignedInteger('articles_created_count')->default(0)->after('meli_items_total');
            $table->unsignedInteger('articles_skipped_count')->default(0)->after('articles_created_count');
            $table->unsignedInteger('articles_error_count')->default(0)->after('articles_skipped_count');
            $table->unsignedInteger('articles_linked_total_count')->default(0)->after('articles_error_count');
            $table->text('summary_message')->nullable()->after('error_message_crudo');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('sync_from_meli_articles', function (Blueprint $table) {
            $table->dropColumn([
                'meli_items_total',
                'articles_created_count',
                'articles_skipped_count',
                'articles_error_count',
                'articles_linked_total_count',
                'summary_message',
            ]);
        });
    }
}
