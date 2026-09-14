<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mision comision-vendedor-liquidacion-iva — agrega el check de liquidacion de comision con/sin
 * IVA al vendedor.
 *
 * Default 1 (con IVA): es el comportamiento que el motor generico de comisiones
 * (ComisionPorcentajeGeneral) ya tenia antes de esta mision, asi que ningun vendedor existente
 * cambia de comportamiento al correr esta migracion.
 */
class AddCommissionWithIvaToSellersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->integer('commission_with_iva')->default(1)->after('commission_after_pay_sale');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('commission_with_iva');
        });
    }
}
