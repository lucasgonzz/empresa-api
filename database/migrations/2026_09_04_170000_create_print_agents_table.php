<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipos con el agente de impresion de ComercioCity instalado.
 *
 * Un "agente" es una computadora concreta del comercio -- la PC de la caja 1, la de la caja 2 --,
 * no una persona. Por eso la fila guarda el nombre del equipo y la lista de impresoras que ESE
 * equipo ve: dos cajas del mismo comercio tienen impresoras distintas y cada una imprime en la suya.
 *
 * Reemplaza a QZ Tray. La diferencia de fondo es la direccion de la conexion: QZ escucha en un
 * puerto local y el navegador le habla, mientras que este agente sale hacia la API y pregunta si
 * hay trabajo. Eso esquiva de una sola vez el prompt de Local Network Access que Chrome 141
 * introdujo sobre localhost, el mixed content, el firewall y el dialogo de permiso por impresion.
 */
class CreatePrintAgentsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('print_agents', function (Blueprint $table) {
            $table->increments('id');

            /*
             * Persona que vinculo el equipo. La impresion es por PUESTO, no por cuenta: un empleado
             * en la caja 2 vincula su propia maquina sin depender del dueño.
             */
            $table->integer('user_id')->unsigned()->index();

            /*
             * Dueño de la cuenta, desnormalizado a proposito: sirve para que el dueño pueda ver y
             * desvincular los equipos de todo su comercio sin recorrer la tabla de usuarios.
             */
            $table->integer('owner_id')->unsigned()->index();

            /* Nombre de la computadora, tal como lo reporta Windows. */
            $table->string('nombre_equipo')->nullable();

            /*
             * Hash del token permanente del agente. Se guarda hasheado y no en claro por la misma
             * razon que una password: quien lea la base no puede hacerse pasar por el equipo.
             */
            $table->string('token_hash', 64)->nullable()->unique();

            /*
             * Hash del codigo de vinculacion, de un solo uso. Queda en null apenas se canjea.
             */
            $table->string('link_code_hash', 64)->nullable()->index();
            $table->timestamp('link_code_expira_at')->nullable();

            /* Impresoras que el equipo reporta, como array JSON de nombres. */
            $table->text('impresoras')->nullable();

            /*
             * Ultima vez que el agente pregunto por trabajos. Es lo que define si el equipo esta
             * en linea: no hay conexion persistente que mirar.
             */
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamp('vinculado_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('print_agents');
    }
}
