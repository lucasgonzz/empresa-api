<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 128)->nullable();
            $table->string('doc_number', 128)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image_url')->nullable();
            $table->string('hosting_image_url')->nullable();
            $table->string('company_name', 128)->nullable();
            $table->enum('type', ['commerce', 'provider'])->default('commerce');
            $table->string('password', 128);
            $table->string('visible_password', 128)->nullable();
            $table->string('prev_password', 128)->nullable();
            $table->integer('owner_id')->nullable()->unsigned();
            $table->integer('plan_id')->unsigned()->nullable();
            $table->integer('percentage_card')->nullable();
            $table->string('dni')->nullable();
            $table->boolean('has_delivery')->default(1);
            $table->decimal('delivery_price')->nullable();
            $table->enum('online_prices', ['all', 'only_registered', 'only_buyers_with_comerciocity_client'])->nullable();
            $table->string('order_description')->nullable();
            $table->decimal('dollar', 10,2)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('online')->nullable();
            $table->text('online_description')->nullable();
            $table->boolean('show_articles_without_images')->default(1);
            $table->boolean('show_articles_without_stock')->default(1);
            $table->string('default_article_image_url')->nullable();
            $table->boolean('from_cloudinary')->default(0);
            $table->integer('articles_pages')->nullable();
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
            $table->text('quienes_somos')->nullable();
            $table->text('mensaje_contacto')->nullable();
            $table->timestamp('payment_expired_at')->nullable();
            $table->integer('online_price_type_id')->nullable();
            $table->decimal('online_price_surchage', 12,2)->nullable();
            $table->integer('max_items_in_sale')->nullable();
            $table->rememberToken();

            $table->enum('status', ['commerce', 'admin', 'super']);

            // $table->foreign('owner_id')->references('id')->on('users');
            // $table->foreign('admin_id')->references('id')->on('users');
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
        Schema::dropIfExists('users');
    }
}
