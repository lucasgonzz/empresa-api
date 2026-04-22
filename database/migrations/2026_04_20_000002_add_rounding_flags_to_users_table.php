<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRoundingFlagsToUsersTable extends Migration
{
    /**
     * Agrega flags de redondeo de precios al usuario.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('redondear_precios_en_decenas')->default(false)->after('redondear_centenas_en_vender');
            $table->boolean('redondear_de_a_50')->default(false)->after('redondear_precios_en_decenas');
            $table->boolean('redondear_precios_en_centavos')->default(false)->after('redondear_de_a_50');
        });
    }

    /**
     * Revierte los flags de redondeo agregados en users.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'redondear_precios_en_decenas',
                'redondear_de_a_50',
                'redondear_precios_en_centavos',
            ]);
        });
    }
}
