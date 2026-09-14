<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión agenda-tareas-calendario (14/9/2026): una tarea recurrente hasta hoy se repetía para
 * siempre. Esta columna le pone un tope opcional a la expansión de ocurrencias (ver
 * AgendaHelper::ocurrencias_entre()): con `null` se comporta igual que antes, así que la
 * migración es compatible hacia atrás con la SPA vieja, que nunca la manda.
 *
 * Es `date` y no `timestamp` a propósito: la recurrencia se razona por día, la hora no existe.
 * Sin foreign keys, como todo el repo.
 */
class AddFechaFinRecurrenciaToPendingsTable extends Migration
{
    /**
     * Agrega la fecha de fin de la recurrencia.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pendings', function (Blueprint $table) {
            /** Última fecha (inclusive) en la que se genera una ocurrencia. `null` = sin fin. */
            $table->date('fecha_fin_recurrencia')->nullable()->after('cantidad_frecuencia');
        });
    }

    /**
     * Elimina la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pendings', function (Blueprint $table) {
            $table->dropColumn('fecha_fin_recurrencia');
        });
    }
}
