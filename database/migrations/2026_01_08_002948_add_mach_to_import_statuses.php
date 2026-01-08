<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMachToImportStatuses extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_statuses', function (Blueprint $table) {
            $table->integer('articles_match')->nullable();
            $table->integer('created_models')->nullable();
            $table->integer('updated_models')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_statuses', function (Blueprint $table) {
            //
        });
    }
}
