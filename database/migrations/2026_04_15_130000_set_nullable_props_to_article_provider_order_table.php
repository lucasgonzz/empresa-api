<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SetNullablePropsToArticleProviderOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->integer('amount')->nullable()->change();
            $table->integer('amount_pedida')->nullable()->change();
            $table->integer('received')->nullable()->default(null)->change();
            $table->integer('iva_id')->nullable()->change();

            $table->decimal('cost', 12, 2)->nullable()->change();
            $table->decimal('received_cost', 12, 2)->nullable()->change();
            $table->decimal('discount', 12, 2)->nullable()->change();
            $table->decimal('price', 22, 2)->nullable()->change();

            $table->boolean('update_cost')->nullable()->default(null)->change();
            $table->boolean('cost_in_dollars')->nullable()->default(null)->change();
            $table->boolean('add_to_articles')->nullable()->default(null)->change();
            $table->boolean('update_provider')->nullable()->default(null)->change();
            $table->boolean('address_id')->nullable()->default(null)->change();

            $table->text('notes')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->integer('amount')->nullable()->change();
            $table->integer('amount_pedida')->nullable()->change();
            $table->integer('received')->default(0)->change();
            $table->integer('iva_id')->nullable()->change();

            $table->decimal('cost', 12, 2)->nullable()->change();
            $table->decimal('received_cost', 12, 2)->nullable()->change();
            $table->decimal('discount', 12, 2)->nullable()->change();
            $table->decimal('price', 22, 2)->nullable()->change();

            $table->boolean('update_cost')->nullable()->default(1)->change();
            $table->boolean('cost_in_dollars')->nullable()->default(0)->change();
            $table->boolean('add_to_articles')->nullable()->default(1)->change();
            $table->boolean('update_provider')->nullable()->default(1)->change();
            $table->boolean('address_id')->nullable()->change();

            $table->text('notes')->nullable()->change();
        });
    }
}
