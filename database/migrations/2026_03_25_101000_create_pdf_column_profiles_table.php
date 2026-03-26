<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePdfColumnProfilesTable extends Migration
{
    public function up()
    {
        Schema::create('pdf_column_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('model_name', 60);
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('paper_width_mm')->default(297);
            $table->unsignedInteger('printable_width_mm')->default(277);
            $table->json('columns');
            $table->timestamps();

            $table->index(['user_id', 'model_name'], 'pcp_user_model_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pdf_column_profiles');
    }
}

