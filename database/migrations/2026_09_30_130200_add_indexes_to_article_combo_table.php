<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices por `article_id` y por `combo_id` en `article_combo`
 * (misión combos-calculados, 30/9/2026).
 *
 * `article_combo` nació en 2022 con solo la PK. Hasta ahora nadie preguntaba "¿qué combos contienen
 * este artículo?", solo "¿qué artículos tiene este combo?" (una tabla de a lo sumo unas decenas de
 * filas por cliente). Los combos calculados agregan la pregunta inversa en el peor lugar posible:
 * cada vez que se guarda un artículo y cada vez que termina un recálculo masivo de precios hay que
 * buscar los combos que lo incluyen (`ComboCalculadoHelper::recalcular_por_articulos()`), y sin
 * índice por `article_id` esa búsqueda recorre la tabla entera. El de `combo_id` acelera la
 * pregunta de siempre (los artículos de un combo) y la que hace tienda-api para el stock del combo.
 *
 * Mismo mecanismo que `2026_09_28_120000_add_article_id_indexes_to_article_price_tables`: sin
 * foreign keys, idempotente por nombre, y no crea nada si la tabla ya tiene algún índice cuya
 * primera columna sea esa (alguien pudo haberlo creado a mano con otro nombre). Nombres cortos.
 */
class AddIndexesToArticleComboTable extends Migration
{
    /**
     * Columna => nombre del índice.
     */
    const INDICES = [
        'article_id' => 'ac_article_idx',
        'combo_id'   => 'ac_combo_idx',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('article_combo')) {
            return;
        }

        foreach (self::INDICES as $columna => $nombre) {
            if (!Schema::hasColumn('article_combo', $columna)) {
                continue;
            }

            if ($this->existe_indice_por_nombre($nombre) || $this->hay_indice_que_empieza_por($columna)) {
                continue;
            }

            Schema::table('article_combo', function (Blueprint $table) use ($columna, $nombre) {
                $table->index($columna, $nombre);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('article_combo')) {
            return;
        }

        foreach (self::INDICES as $nombre) {
            if ($this->existe_indice_por_nombre($nombre)) {
                Schema::table('article_combo', function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }
    }

    /**
     * ¿La tabla ya tiene un índice con ESE NOMBRE? SHOW INDEX porque Schema::hasIndex() no existe
     * en el Laravel del proyecto.
     *
     * @param  string  $nombre
     * @return bool
     */
    private function existe_indice_por_nombre($nombre)
    {
        return count(DB::select('SHOW INDEX FROM `article_combo` WHERE Key_name = ?', [$nombre])) > 0;
    }

    /**
     * ¿La tabla ya tiene algún índice cuya primera columna sea `$columna`?
     *
     * @param  string  $columna
     * @return bool
     */
    private function hay_indice_que_empieza_por($columna)
    {
        return count(DB::select(
            'SHOW INDEX FROM `article_combo` WHERE Column_name = ? AND Seq_in_index = 1',
            [$columna]
        )) > 0;
    }
}
