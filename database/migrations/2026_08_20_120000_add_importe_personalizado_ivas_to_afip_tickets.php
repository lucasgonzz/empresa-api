<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImportePersonalizadoIvasToAfipTickets extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('afip_tickets', function (Blueprint $table) {
            /**
             * Reparto por alicuota del importe personalizado, tal como lo cargo el usuario
             * en el modal de emitir factura. Es dato de ENTRADA (arbitrario, no se deriva de
             * los items), a diferencia de `iva_detalle_enviado_json`, que es el snapshot de
             * SALIDA que persiste AfipWsfeHelper despues de armar el comprobante.
             *
             * Formato: [ { "key": "21", "importe": 12100.00 }, { "key": "10", "importe": 5525.00 } ]
             * donde `key` es la clave interna de AfipImportesCalculator::default_ivas()
             * ('10' es 10,5 % y '2' es 2,5 %), y `importe` es el total de esa alicuota CON IVA.
             *
             * longText por consistencia con `iva_detalle_enviado_json`.
             */
            $table->longText('importe_personalizado_ivas_json')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('afip_tickets', function (Blueprint $table) {
            //
        });
    }
}
