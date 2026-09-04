<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de trabajos de impresion para los agentes.
 *
 * El SPA encola un ticket y contesta enseguida; el agente lo levanta en su proximo sondeo y lo
 * manda a la comandera. La cola es la que permite que el ticket se pueda mandar desde un celular
 * o desde otra sucursal: quien imprime no necesita estar en la misma maquina que la impresora.
 */
class CreatePrintJobsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('print_agent_id')->unsigned()->index();

            /* Quien mando a imprimir, para poder mostrarle el resultado. */
            $table->integer('user_id')->unsigned()->index();

            /* Nombre exacto de la impresora en Windows, tal como la reporto el agente. */
            $table->string('printer_name');

            /*
             * Los bytes ESC/POS del ticket, en base64. Es el mismo contenido que hoy se le pasa a
             * QZ con encoding ISO-8859-1: base64 lo hace sobrevivir a JSON sin que ningun caracter
             * de control se rompa en el camino.
             */
            $table->longText('payload_base64');

            /*
             * pendiente -> tomado -> impreso | error
             *
             * "tomado" existe para que dos sondeos seguidos del mismo agente no impriman el ticket
             * dos veces: se marca al entregarlo, antes de que el agente conteste como le fue.
             */
            $table->string('status', 20)->default('pendiente')->index();

            $table->text('error')->nullable();

            $table->timestamp('tomado_at')->nullable();
            $table->timestamp('terminado_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('print_jobs');
    }
}
