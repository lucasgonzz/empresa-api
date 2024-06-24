<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePagadoPorTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pagado_por', function (Blueprint $table) {
            $table->id();
            $table->integer('debe_id');
            $table->integer('haber_id');
            $table->decimal('pagado', 12,2);
            $table->decimal('total_pago', 12,2);
            $table->decimal('a_cubrir', 12,2)->nullable();
            $table->decimal('fondos_iniciales', 12,2)->nullable();
            $table->decimal('nuevos_fondos', 12,2)->nullable();
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
        Schema::dropIfExists('pagado_por');
    }
}
