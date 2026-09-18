<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué falló el cálculo asincrónico de los hechos de un informe del mostrador
 * (misión modulo-ia-mostrador, 14/9/2026).
 *
 * Los hechos de `compras` y `stock` de un catálogo grande los calcula
 * CalcularHechosMostradorJob en la cola, con la fila en estado 'calculando'. Si el job
 * revienta, la fila queda en 'error' con el mensaje acá, para que la skill /mostrador
 * lo vea en el polling (GET admin-sync/mostrador/reportes/{id}) en vez de esperar para
 * siempre. Se limpia en cada recálculo.
 *
 * Sin foreign keys, siguiendo el estilo del resto del schema.
 */
class AddErrorMensajeToMostradorReportesTable extends Migration
{
    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('mostrador_reportes') || Schema::hasColumn('mostrador_reportes', 'error_mensaje')) {
            return;
        }

        Schema::table('mostrador_reportes', function (Blueprint $table) {

            /* Mensaje del fallo del cálculo asincrónico (estado 'error'); null si no falló */
            $table->string('error_mensaje', 300)->nullable()->after('estado');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('mostrador_reportes') || !Schema::hasColumn('mostrador_reportes', 'error_mensaje')) {
            return;
        }

        Schema::table('mostrador_reportes', function (Blueprint $table) {
            $table->dropColumn('error_mensaje');
        });
    }
}
