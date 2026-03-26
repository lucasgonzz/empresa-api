<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePdfColumnOptionsTable extends Migration
{
    public function up()
    {
        Schema::create('pdf_column_options', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 60);
            $table->string('name', 120);
            $table->string('label', 120);
            $table->string('value_resolver', 120);
            $table->unsignedInteger('default_width')->default(80);
            $table->boolean('allow_wrap_content')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['model_name'], 'pco_model_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pdf_column_options');
    }
}

