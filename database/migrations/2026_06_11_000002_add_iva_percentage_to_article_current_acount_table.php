<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega iva_percentage al pivot article_current_acount para persistir
 * la alícuota de IVA al momento de crear la nota de crédito.
 * Al tomarse del pivot original de la venta, se garantiza que AfipNotaCreditoHelper
 * use el mismo IVA que tuvo la factura, sin importar cambios posteriores al artículo.
 */
class AddIvaPercentageToArticleCurrentAcountTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_current_acount', function (Blueprint $table) {
            /**
             * Alícuota de IVA heredada del pivot de la venta original.
             * Nullable para compatibilidad con notas de crédito históricas.
             */
            $table->decimal('iva_percentage', 8, 2)->nullable()->after('discount');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_current_acount', function (Blueprint $table) {
            $table->dropColumn('iva_percentage');
        });
    }
}
