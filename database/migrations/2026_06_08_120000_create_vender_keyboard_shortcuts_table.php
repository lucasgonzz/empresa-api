<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de atajos de teclado del módulo Vender por usuario autenticado
 * (dueño o empleado). Un registro por user_id con el mapa action => tecla F1-F10.
 */
class CreateVenderKeyboardShortcutsTable extends Migration
{
    /**
     * Crea la tabla vender_keyboard_shortcuts.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vender_keyboard_shortcuts', function (Blueprint $table) {
            $table->id();

            // Usuario autenticado (empleado o dueño); relación lógica en Eloquent, sin FK en BD
            $table->unsignedBigInteger('user_id');

            // Mapa JSON: action => tecla (F1..F10)
            $table->json('shortcuts');

            $table->timestamps();

            $table->unique('user_id', 'vks_user_id_uniq');
            $table->index('user_id', 'vks_user_id_idx');
        });
    }

    /**
     * Elimina la tabla vender_keyboard_shortcuts.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vender_keyboard_shortcuts');
    }
}
