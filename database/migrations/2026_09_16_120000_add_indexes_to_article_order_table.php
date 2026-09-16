<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos índices sobre el pivot `article_order` (misión tienda-ficha-estilo-ml, 16/9/2026).
 *
 * El pivot nació en 2020 sin ningún índice más que la PRIMARY sobre `id`, y hasta ahora nadie lo
 * recorría en caliente. Lo cambia la nueva sección "quienes compraron este producto también
 * compraron" de la ficha del ecommerce: esa consulta vive en `tienda-api` y corre en CADA vista de
 * ficha, que es la pantalla más visitada de la tienda.
 *
 * La consulta entra al pivot dos veces y necesita un índice distinto en cada una:
 *
 *   - `article_id` -> article_order_article_id_idx
 *     Es la entrada: de qué pedidos formó parte este artículo
 *     (`WHERE ao1.article_id = :article_id`).
 *   - `order_id` -> article_order_order_id_idx
 *     Es la vuelta: qué otros artículos traían esos pedidos
 *     (`JOIN article_order ao2 ON ao2.order_id = ao1.order_id`). También lo usa el último paso de
 *     "quienes vieron este producto también compraron", que agrega sobre los pedidos que salieron
 *     del tracking de compradores.
 *
 * Sin estos dos índices cada apertura de ficha se lleva dos full scans del pivot entero. Sin
 * foreign keys físicas, como el resto del esquema.
 *
 * Es puramente aditivo y por eso no rompe nada en ninguna dirección: un índice no cambia
 * resultados, así que la tienda devuelve exactamente lo mismo con o sin él — nada más que más
 * lenta sin él. Eso importa porque la tienda se despliega a mano y el índice llega por release de
 * empresa: el escenario normal es tienda nueva contra base sin el índice, y tiene que funcionar.
 *
 * Nota de operación (igual que 2026_08_14_120400 y 2026_09_09_150000): en MySQL 5.7 y 8 crear un
 * índice secundario usa ALGORITHM=INPLACE por default y no bloquea escrituras, pero en una tabla
 * grande la creación puede tardar varios minutos y consumir I/O. En un cliente con muchos pedidos
 * conviene correr esta migración fuera del horario de atención del comercio.
 *
 * La guarda por `SHOW INDEX` es para las instancias donde alguien los haya creado a mano por SSH
 * antes de este upgrade: un `ADD INDEX` repetido tira "Duplicate key name" y dejaría el upgrade a
 * medias.
 */
class AddIndexesToArticleOrderTable extends Migration
{
    /** Nombre corto y fijo del índice de entrada, para que la guarda y el down() miren el mismo. */
    const INDICE_ARTICLE_ID = 'article_order_article_id_idx';

    /** Nombre corto y fijo del índice de vuelta, para que la guarda y el down() miren el mismo. */
    const INDICE_ORDER_ID = 'article_order_order_id_idx';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->crear_indice('article_id', self::INDICE_ARTICLE_ID);
        $this->crear_indice('order_id', self::INDICE_ORDER_ID);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->borrar_indice(self::INDICE_ORDER_ID);
        $this->borrar_indice(self::INDICE_ARTICLE_ID);
    }

    /**
     * Crea el índice solo si todavía no está.
     *
     * @param  string  $columna
     * @param  string  $nombre
     * @return void
     */
    private function crear_indice($columna, $nombre)
    {
        if ($this->existe_el_indice($nombre)) {
            return;
        }

        Schema::table('article_order', function (Blueprint $table) use ($columna, $nombre) {
            $table->index($columna, $nombre);
        });
    }

    /**
     * Borra el índice solo si está.
     *
     * @param  string  $nombre
     * @return void
     */
    private function borrar_indice($nombre)
    {
        if (! $this->existe_el_indice($nombre)) {
            return;
        }

        Schema::table('article_order', function (Blueprint $table) use ($nombre) {
            $table->dropIndex($nombre);
        });
    }

    /**
     * ¿Ya existe el índice en la base conectada?
     *
     * @param  string  $nombre
     * @return bool
     */
    private function existe_el_indice($nombre)
    {
        return count(DB::select("SHOW INDEX FROM article_order WHERE Key_name = '{$nombre}'")) > 0;
    }
}
