<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleImportResultsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_import_results', function (Blueprint $table) {
            $table->id();

            $table->uuid('import_uuid')->index();
            $table->unsignedBigInteger('created_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_import_results');
    }
}
