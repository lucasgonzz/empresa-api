<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserConfigurationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('current_acount_pagandose_details')->nullable();
            $table->string('current_acount_pagado_details')->nullable();
            $table->boolean('show_articles_without_stock')->default(true);
            $table->boolean('iva_included')->default(true);
            $table->boolean('set_articles_updated_at_always')->default(false);
            $table->integer('limit_items_in_sale_per_page')->nullable();
            $table->boolean('show_google_login')->default(true);
            $table->boolean('apply_price_type_in_services')->default(false);
            $table->boolean('can_make_afip_tickets')->default(0);
            $table->integer('user_id')->unsigned();
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
        Schema::dropIfExists('user_configurations');
    }
}
