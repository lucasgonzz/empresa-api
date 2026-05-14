<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot Caja ↔ User para empleados que pueden ver la caja en el módulo de tesorería (listado de cajas).
 * Si no hay filas, se usa la relación `users` (uso en vender) como fallback en el front.
 */
class CreateCajaTreasuryUserTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('caja_treasury_user', function (Blueprint $table) {
            $table->id();

            $table->integer('caja_id');
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
        Schema::dropIfExists('caja_treasury_user');
    }
}
