<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExportHistoriesTable extends Migration
{
    /**
     * Crea la tabla de historial de exportaciones excel en cola.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('export_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('employee_id')->nullable()->index();
            $table->string('model_name', 60);
            $table->string('status', 30)->default('pending');
            $table->string('file_name', 255)->nullable();
            $table->text('excel_url')->nullable();
            $table->integer('exported_count')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'model_name'], 'export_hist_user_model_idx');
        });
    }

    /**
     * Elimina la tabla de historial de exportaciones.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('export_histories');
    }
}
