<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTableColumnPreferencesTable extends Migration
{
    public function up()
    {
        Schema::create('table_column_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('model_name');
            $table->json('columns');
            $table->timestamps();

            $table->unique(['user_id', 'model_name']);
            $table->index('model_name');
        });
    }

    public function down()
    {
        Schema::dropIfExists('table_column_preferences');
    }
}

