<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega iva_percentage al pivot article_sale para persistir la alícuota
 * de IVA usada al momento de vender, independientemente de cambios futuros
 * en el artículo. Esto garantiza que notas de crédito y devoluciones usen
 * siempre el IVA que correspondió a la factura original.
 */
class AddIvaPercentageToArticleSaleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            /**
             * Alícuota de IVA al momento de la venta.
             * Nullable para compatibilidad con registros históricos.
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
        Schema::table('article_sale', function (Blueprint $table) {
            $table->dropColumn('iva_percentage');
        });
    }
}
