<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: el endoso en un gasto y el banco por catálogo, en `cheques` (misión
 * cheques-endoso-y-bancos, 21/9/2026).
 *
 * - `cheque_banco_id`: el banco elegido del catálogo (`cheque_bancos`). El texto `banco` NO se
 *   saca: es la compatibilidad con la SPA anterior, con el Excel y con el mostrador, y es lo que el
 *   asistente lee para armar el catálogo.
 * - `expense_id`: el gasto en el que se cargó el cheque (nuevo o endosado). Hasta esta misión el
 *   id del gasto quedaba en `current_acount_id`, apuntando a una cuenta corriente cualquiera.
 * - `endosado_en_expense_id`: en un cheque RECIBIDO, el gasto en el que se endosó. Es la segunda
 *   forma de salir de cartera (la primera es `endosado_a_provider_id`), y por eso "sigue en
 *   cartera" se mira siempre por ChequeHelper::sin_endosar() / en_cartera(), nunca por una columna.
 * - `endosado_desde_cheque_id`: en la copia EMITIDA que nace de un endoso, el recibido del que
 *   salió. Hasta ahora solo se guardaba el cliente (`endosado_desde_client_id`).
 *
 * Todas nullable y sin foreign keys, y cada una detrás de `hasColumn` para que la migración se pueda
 * correr dos veces sobre una base que ya la tenga a medias.
 */
class AddEndosoYBancoAChequesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('cheques', function (Blueprint $table) {

            if (!Schema::hasColumn('cheques', 'cheque_banco_id')) {
                $table->integer('cheque_banco_id')->nullable()->after('banco');
            }

            if (!Schema::hasColumn('cheques', 'expense_id')) {
                $table->integer('expense_id')->nullable()->after('current_acount_id');
            }

            if (!Schema::hasColumn('cheques', 'endosado_en_expense_id')) {
                $table->integer('endosado_en_expense_id')->nullable()->after('endosado_a_provider_id');
            }

            if (!Schema::hasColumn('cheques', 'endosado_desde_cheque_id')) {
                $table->integer('endosado_desde_cheque_id')->nullable()->after('endosado_desde_client_id');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('cheques', function (Blueprint $table) {

            foreach (['cheque_banco_id', 'expense_id', 'endosado_en_expense_id', 'endosado_desde_cheque_id'] as $columna) {

                if (Schema::hasColumn('cheques', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
