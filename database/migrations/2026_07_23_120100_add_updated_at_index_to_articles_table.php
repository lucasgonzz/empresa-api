<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Indice compuesto (user_id, updated_at, id) en `articles` para que el export incremental
 * de articulos (prompt 02 del grupo 211) pueda paginar por cursor (`updated_at`, `id`) sin
 * full scan ni filesort sobre un inventario de hasta 500.000 filas por comercio.
 *
 * El orden de las columnas es a proposito: `user_id` primero porque el export siempre
 * filtra por comercio, despues `updated_at` que es el rango de la consulta incremental,
 * y `id` al final para que el keyset (updated_at, id) del prompt 02 se resuelva entero
 * desde el indice sin ordenar en memoria.
 *
 * Nota de operacion: en MySQL 5.7 y 8, crear un indice secundario usa
 * ALGORITHM=INPLACE por default y no bloquea escrituras en la tabla. Aun asi, en una
 * tabla de 500.000 filas la creacion puede tardar varios minutos y conviene correr esta
 * migracion fuera del horario de atencion del comercio para no competir por I/O con el
 * trafico normal.
 */
class AddUpdatedAtIndexToArticlesTable extends Migration
{
    /**
     * Nombre del indice, corto y explicito, usado tanto en up() como en down() para
     * chequear si ya existe antes de crearlo/borrarlo (migracion reejecutable).
     *
     * @var string
     */
    private $index_name = 'articles_user_updated_id_index';

    /**
     * Rellena los `updated_at` en NULL (filas insertadas sin pasar por Eloquent, que no
     * disparan los timestamps automaticos) y despues crea el indice compuesto, si todavia
     * no existe.
     *
     * @return void
     */
    public function up()
    {
        // Paso previo obligatorio: un articulo con updated_at NULL es invisible para el
        // filtro `>= fecha` del export incremental y ademas rompe la comparacion del
        // keyset por cursor (NULL no es ni mayor ni menor que nada en SQL). Se usa
        // created_at como mejor aproximacion, y NOW() si tampoco hay created_at.
        DB::statement('UPDATE articles SET updated_at = COALESCE(created_at, NOW()) WHERE updated_at IS NULL');

        // Chequeo de existencia previo para que la migracion se pueda correr mas de una
        // vez (rollback + migrate, o migrate sobre una base que ya tenia el indice) sin
        // que explote por "Duplicate key name".
        $index_existente = DB::select("SHOW INDEX FROM articles WHERE Key_name = '{$this->index_name}'");

        if (empty($index_existente)) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index(['user_id', 'updated_at', 'id'], $this->index_name);
            });
        }
    }

    /**
     * Elimina el indice compuesto, solo si existe.
     *
     * @return void
     */
    public function down()
    {
        $index_existente = DB::select("SHOW INDEX FROM articles WHERE Key_name = '{$this->index_name}'");

        if (!empty($index_existente)) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropIndex($this->index_name);
            });
        }
    }
}
