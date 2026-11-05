<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUpdateProviderExplicitToArticleProviderOrder extends Migration
{
    public function up()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->boolean('update_provider_explicit')->default(false);
        });
    }

    public function down()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->dropColumn('update_provider_explicit');
        });
    }
}
