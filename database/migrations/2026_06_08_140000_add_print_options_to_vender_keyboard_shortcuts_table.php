<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega print_options JSON para configurar qué imprime el atajo de teclado en Vender.
 */
class AddPrintOptionsToVenderKeyboardShortcutsTable extends Migration
{
    /**
     * Agrega la columna print_options.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vender_keyboard_shortcuts', function (Blueprint $table) {
            $table->json('print_options')->nullable()->after('shortcuts');
        });
    }

    /**
     * Elimina la columna print_options.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vender_keyboard_shortcuts', function (Blueprint $table) {
            $table->dropColumn('print_options');
        });
    }
}
