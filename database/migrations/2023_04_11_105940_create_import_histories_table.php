<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_histories', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unsigned();
            $table->integer('employee_id')->nullable();
            $table->string('model_name');
            $table->integer('created_models')->default(0);
            $table->integer('updated_models')->default(0);
            $table->integer('provider_id')->nullable();
            $table->text('observations')->nullable();
            $table->text('excel_url')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('no_actualizar_articulos_de_otro_proveedor')->nullable();
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
        Schema::dropIfExists('import_histories');
    }
}
