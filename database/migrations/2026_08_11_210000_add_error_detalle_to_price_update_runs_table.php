<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega error_detalle a price_update_runs.
 *
 * Ahora el recálculo avisa al FINAL, así que si el final no llega no llega nada: una
 * corrida que muere en el camino tiene que poder decir por qué. Sin esta columna una
 * corrida en 'error' no dice nada y el soporte queda a ciegas.
 *
 * El texto que se guarda es el mismo que viaja en la notificación y tiene que ser legible
 * por una persona, no un stack trace.
 */
class AddErrorDetalleToPriceUpdateRunsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('price_update_runs')) {
            return;
        }

        if (Schema::hasColumn('price_update_runs', 'error_detalle')) {
            return;
        }

        Schema::table('price_update_runs', function (Blueprint $table) {
            $table->string('error_detalle', 500)->nullable()->after('stats_json');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('price_update_runs')) {
            return;
        }

        if (!Schema::hasColumn('price_update_runs', 'error_detalle')) {
            return;
        }

        Schema::table('price_update_runs', function (Blueprint $table) {
            $table->dropColumn('error_detalle');
        });
    }
}
