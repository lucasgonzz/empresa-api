<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndiceToArticleProvider extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_provider', function (Blueprint $table) {
            $table->unique(['article_id', 'provider_id'], 'uniq_article_provider');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_provider', function (Blueprint $table) {
            $table->dropUnique('uniq_article_provider');
        });
    }
}
