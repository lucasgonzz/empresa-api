<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleImportResultObservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_import_result_observations', function (Blueprint $table) {
            $table->id();

            $table->integer('article_import_result_id');
            
            $table->integer('fila')->nullable();
            $table->decimal('duration', 10,2)->nullable();
            
            $table->json('procesos')->nullable();
            
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
        Schema::dropIfExists('article_import_result_observations');
    }
}
