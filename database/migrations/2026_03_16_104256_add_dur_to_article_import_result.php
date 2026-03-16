<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDurToArticleImportResult extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_import_results', function (Blueprint $table) {
            $table->decimal('duration', 10,2)->nullable();

            $table->json('article_import_observations')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_import_result', function (Blueprint $table) {
            //
        });
    }
}
