<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOnlineConfigurationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('online_configurations', function (Blueprint $table) {
            $table->id();
            $table->boolean('pausar_tienda_online')->default(0)->nullable();
            $table->integer('online_price_type_id')->nullable();
            $table->decimal('online_price_surchage', 12,2)->nullable();
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
            $table->text('quienes_somos')->nullable();
            $table->string('default_article_image_url')->nullable();
            $table->text('mensaje_contacto')->nullable();
            $table->boolean('show_articles_without_images')->default(1)->nullable();
            $table->boolean('show_articles_without_stock')->default(1)->nullable();
            $table->boolean('stock_null_equal_0')->default(0)->nullable();
            $table->text('online_description')->nullable();
            $table->boolean('has_delivery')->default(1)->nullable();
            $table->boolean('register_to_buy')->default(1)->nullable();
            $table->boolean('scroll_infinito_en_home')->default(1)->nullable();
            $table->boolean('save_sale_after_finish_order')->default(1)->nullable();
            $table->string('order_description')->nullable();
            $table->integer('user_id');
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
        Schema::dropIfExists('online_configurations');
    }
}
