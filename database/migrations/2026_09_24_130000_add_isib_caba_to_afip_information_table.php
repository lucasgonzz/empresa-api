<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alicuota de Ingresos Brutos de CABA que el emisor tiene que informar en los comprobantes a
 * consumidor final (Res. 169/AGIP/2026, plazo de adecuacion prorrogado por la Res. 339/AGIP/2026
 * hasta el 1/1/2027).
 *
 * Va por punto de venta (tabla `afip_information`, en singular) y no por usuario: cada CUIT tiene
 * su propio encuadre en Ingresos Brutos. Las dos columnas nacen apagadas (alicuota en null), asi
 * que ningun comprobante cambia hasta que el negocio la carga.
 */
class AddIsibCabaToAfipInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('afip_information', function (Blueprint $table) {
            /**
             * Alicuota ISIB CABA en porcentaje (3.00 = 3%). Null = el emisor no la informa.
             */
            $table->decimal('isib_caba_alicuota', 5, 2)->nullable();

            /**
             * Si el emisor tributa por Convenio Multilateral: la leyenda suma
             * "APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA".
             */
            $table->boolean('isib_caba_convenio_multilateral')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('afip_information', function (Blueprint $table) {
            $table->dropColumn(['isib_caba_alicuota', 'isib_caba_convenio_multilateral']);
        });
    }
}
