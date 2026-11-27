<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de PDF de ofertas: flag para el formato "pagando en efectivo o transferencia"
 * (misión soporte-t173-m44, ticket 173 de La Martina).
 *
 * Con el flag prendido el cartel muestra el precio de siempre y, al lado, un recuadro con el precio
 * con el descuento de efectivo/transferencia (el mismo porcentaje que se aplica al cobrar), y deja
 * de imprimir "Precio sin impuestos" y "Precio anterior". Sin foreign keys ni índices: es un
 * booleano que se lee junto con la plantilla. Default 0: las plantillas existentes no cambian.
 */
class AddMostrarPrecioEfectivoToArticlePdfsTable extends Migration
{
    /**
     * Agrega la columna `mostrar_precio_efectivo` (apagada por defecto).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_pdfs', function (Blueprint $table) {
            $table->boolean('mostrar_precio_efectivo')->default(0)->after('motrar_fecha_impresion');
        });
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_pdfs', function (Blueprint $table) {
            $table->dropColumn('mostrar_precio_efectivo');
        });
    }
}
