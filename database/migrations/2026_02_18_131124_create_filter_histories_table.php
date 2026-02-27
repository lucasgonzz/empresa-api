<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFilterHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('filter_histories', function (Blueprint $table) {
            $table->id();   

            $table->integer('user_id')->index();
            $table->integer('auth_user_id')->nullable();

            $table->string('action', 20)->index(); // busqueda | actualizacion | eliminacion
            $table->string('model_name')->index();

            $table->integer('filtrados_count')->nullable();
            $table->integer('afectados_count')->nullable();

            $table->json('used_filters')->nullable();
            $table->text('used_filters_text')->nullable();

            $table->json('extra_data')->nullable();

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
        Schema::dropIfExists('filter_histories');
    }
}
