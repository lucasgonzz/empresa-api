<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddToImportStatuses extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $table->integer('total_chunks')->nullable();
            $table->integer('processed_chunks')->nullable();
            $table->timestamp('terminado_at')->nullable();
            $table->json('operaciones')->nullable();
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
