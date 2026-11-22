<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `purchase_suggestion_articles` las dos columnas con la demanda no satisfecha del
 * artículo (misión demanda-sin-stock-vender):
 *  - demanda_no_satisfecha_veces: cuántos frenos de Vender (sin cancelar, últimos 30 días) hubo.
 *  - demanda_no_satisfecha_cantidad: cuántas unidades se pidieron en total (un freno sin cantidad
 *    cuenta como 1 unidad).
 *
 * El job de la corrida inserta en bloque las líneas tal cual las devuelve el motor, así que toda
 * clave nueva de la línea tiene que existir como columna. Ambas con default 0, sin índice y sin
 * foreign key. Guard idempotente por columna.
 */
class AddDemandaNoSatisfechaToPurchaseSuggestionArticles extends Migration
{
    /**
     * Agrega las columnas que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('purchase_suggestion_articles', 'demanda_no_satisfecha_veces')) {
            Schema::table('purchase_suggestion_articles', function (Blueprint $table) {
                $table->integer('demanda_no_satisfecha_veces')->default(0);
            });
        }

        if (! Schema::hasColumn('purchase_suggestion_articles', 'demanda_no_satisfecha_cantidad')) {
            Schema::table('purchase_suggestion_articles', function (Blueprint $table) {
                $table->decimal('demanda_no_satisfecha_cantidad', 20, 2)->default(0);
            });
        }
    }

    /**
     * Quita las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        foreach (['demanda_no_satisfecha_veces', 'demanda_no_satisfecha_cantidad'] as $columna) {
            if (Schema::hasColumn('purchase_suggestion_articles', $columna)) {
                Schema::table('purchase_suggestion_articles', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
