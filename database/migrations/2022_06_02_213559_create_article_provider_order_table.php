<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleProviderOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_provider_order', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id')->unsigned();
            $table->integer('provider_order_id')->unsigned();
            $table->integer('amount')->nullable();
            $table->integer('received')->default(0);
            $table->integer('iva_id')->nullable();
            $table->decimal('cost', 12,2)->nullable();
            $table->decimal('received_cost', 12,2)->nullable();
            $table->boolean('update_cost')->default(1)->nullable();
            $table->boolean('cost_in_dollars')->default(0)->nullable();
            $table->boolean('add_to_articles')->default(1)->nullable();
            $table->boolean('update_provider')->default(1)->nullable();
            $table->text('notes')->nullable();
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
        Schema::dropIfExists('article_provider_order');
    }
}
