<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: el catálogo de bancos de cheques, por dueño (misión cheques-endoso-y-bancos,
 * 21/9/2026).
 *
 * Hasta acá el banco de un cheque era texto libre (`cheques.banco`), y cada comercio terminaba con
 * "Bco Nacion", "banco nación" y "BNA" como tres bancos distintos. Esta tabla es la lista prolija:
 * el cheque la referencia por `cheques.cheque_banco_id` y el texto viejo se conserva como
 * compatibilidad hacia atrás (y como la fuente que el asistente lee para unificar).
 *
 * Arranca VACÍA a propósito (decisión de Lucas): se carga desde el ABM de Tesorería, desde el
 * "+ nuevo banco" al lado del select, o pidiéndoselo al asistente. Sin foreign keys físicas, como el
 * resto del schema.
 */
class CreateChequeBancosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('cheque_bancos')) {
            return;
        }

        Schema::create('cheque_bancos', function (Blueprint $table) {
            $table->id();

            /** Nombre prolijo del banco, tal como se muestra en el select. */
            $table->string('name');

            /** Dueño del comercio: el catálogo es por cuenta, no global. */
            $table->integer('user_id')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cheque_bancos');
    }
}
