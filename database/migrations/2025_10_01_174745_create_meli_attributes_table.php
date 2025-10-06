<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeliAttributesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meli_attributes', function (Blueprint $table) {
            $table->id();

            $table->string('meli_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('relevance')->nullable();
            $table->string('value_type')->nullable();
            $table->integer('value_max_length')->nullable();
            $table->string('default_unit')->nullable();
            $table->text('tooltip')->nullable();
            $table->text('hint')->nullable();
            $table->string('hierarchy')->nullable();
            $table->string('example')->nullable();
            $table->string('attribute_group_id')->nullable();
            $table->string('attribute_group_name')->nullable();


            $table->integer('meli_category_id')->nullable();
            
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
        Schema::dropIfExists('meli_attributes');
    }
}
