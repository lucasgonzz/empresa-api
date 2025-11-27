<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_statuses', function (Blueprint $table) {
            $table->id();

            $table->integer('total_chunks')->default(0);
            $table->integer('processed_chunks')->default(0);

            $table->enum('status', ['pendiente', 'en_proceso', 'completado', 'fallo'])->default('pendiente');
            $table->text('error_message')->nullable();

            $table->integer('user_id');

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
        Schema::dropIfExists('import_statuses');
    }
}
