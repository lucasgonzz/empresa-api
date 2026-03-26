<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePdfColumnOptionProfileTable extends Migration
{
    public function up()
    {
        Schema::create('pdf_column_option_profile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pdf_column_profile_id');
            $table->unsignedBigInteger('pdf_column_option_id');
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->unsignedInteger('width')->default(80);
            $table->boolean('wrap_content')->default(false);
            $table->boolean('fade_when_truncated')->default(true);
            $table->timestamps();

            $table->index(['pdf_column_profile_id'], 'pcop_profile_idx');
            $table->index(['pdf_column_option_id'], 'pcop_option_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pdf_column_option_profile');
    }
}

