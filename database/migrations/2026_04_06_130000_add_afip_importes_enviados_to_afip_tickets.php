<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAfipImportesEnviadosToAfipTickets extends Migration
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
             * Snapshot de importes enviados al momento de autorizar comprobante.
             * Se guardan campos escalares para consulta rápida y JSON para detalle por alícuota.
             */
            $table->decimal('imp_total_enviado', 32, 2)->nullable();
            $table->decimal('imp_tot_conc_enviado', 32, 2)->nullable();
            $table->decimal('imp_neto_enviado', 32, 2)->nullable();
            $table->decimal('imp_op_ex_enviado', 32, 2)->nullable();
            $table->decimal('imp_iva_enviado', 32, 2)->nullable();
            $table->longText('iva_detalle_enviado_json')->nullable();
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
