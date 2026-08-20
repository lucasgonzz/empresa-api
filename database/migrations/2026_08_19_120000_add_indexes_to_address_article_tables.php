<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indices para el filtro por sucursal del Listado de articulos (19/8/2026).
 *
 * El operador `address_stock_seteado` de ExtraFiltersHelper arma dos EXISTS correlacionados:
 *
 *   exists (select ... from addresses join address_article
 *           where articles.id = address_article.article_id and addresses.id = ?)
 *   exists (select ... from article_variants
 *           where articles.id = article_variants.article_id and exists (
 *               select ... from addresses join address_article_variant
 *               where article_variants.id = address_article_variant.article_variant_id
 *                 and addresses.id = ?))
 *
 * Medido el 19/8/2026 contra el schema real: las tres tablas involucradas tenian UNICAMENTE su
 * PRIMARY(id) --article_variants tenia ademas el de bar_code y nada mas--. Sin indice, cada fila
 * candidata de `articles` dispara un table scan de `address_article`, y las que llegan al segundo
 * EXISTS disparan ademas uno de `article_variants` y otro de `address_article_variant` por
 * variante. Sobre un catalogo de decenas de miles eso es cuadratico, y `paginate()` corre la query
 * DOS veces (el count y la pagina).
 *
 * Los dos indices de los pivotes son COMPUESTOS y en ese orden a proposito: la columna de la
 * correlacion va primera (es la que el EXISTS busca fila por fila) y la de la sucursal segunda, con
 * lo cual el mismo indice resuelve el WHERE entero sin ir a la tabla. Un indice suelto por columna
 * serviria para la correlacion pero obligaria a leer la fila para chequear la sucursal.
 *
 * `article_variants.article_id` se indexa aparte porque es la correlacion del EXISTS de afuera.
 *
 * ---
 *
 * 🔴 LA GUARDA PREGUNTA POR EL NOMBRE EXACTO DEL INDICE, NO POR SU PRIMERA COLUMNA. Es la
 * diferencia con `2026_08_08_120000_add_indexes_to_remaining_sale_pivot_tables` (grupo 375), de
 * donde sale este patron, y NO es un capricho de estilo: aquella guarda --"existe algun indice cuya
 * primera columna sea X"-- tiene tres modos de falla, los tres reproducidos en una base scratch el
 * 19/8/2026 antes de escribir esto:
 *
 *   a) FALSO POSITIVO EN up(). Si la base ya tiene un indice SIMPLE por `article_id` --puesto a
 *      mano por alguien alguna vez-- la guarda dice "ya esta" y el compuesto
 *      (article_id, address_id) NO SE CREA NUNCA. MySQL tiene que ir a la fila para chequear
 *      address_id, o sea exactamente la degradacion que esta migracion viene a evitar, y en
 *      silencio. Es el unico de los tres que afecta el camino normal y no solo el rollback.
 *
 *   b) down() EXPLOTA CON 1091. En esa misma base, la guarda da true y se ejecuta
 *      `DROP INDEX address_article_article_id_address_id_index`, que no existe:
 *      "Can't DROP ...; check that column/key exists". Lo absurdo es que ese escenario es
 *      justamente el que el docblock del up() declara soportar.
 *
 *   c) down() BORRA INDICES QUE NO CREO. Si `address_article_address_id_index` ya existia, up() no
 *      lo toca (por la guarda) pero down() lo dropea igual. Un down() que destruye en vez de
 *      revertir.
 *
 * Preguntando por `Key_name` los tres se cierran de una: up() crea lo que falta aunque haya otros
 * indices encima de la misma columna, y down() dropea unicamente lo que up() creo.
 *
 * Los nombres van declarados como constantes y se le pasan explicitamente a index()/dropIndex(),
 * en vez de depender de como Laravel los deriva: si el derivador cambia, un down() que confia en el
 * nombre derivado deja de encontrar lo que el up() creo.
 *
 * DETECCION de esta familia --indice guardado por columna en vez de por nombre-- en el resto del
 * repo:
 *
 *   grep -rn "Seq_in_index" database/migrations/
 *
 * Corrido el 19/8/2026: devuelve esta migracion y la del grupo 375. Esa ultima ya esta mergeada y
 * corrida en produccion, asi que NO se toca desde aca (cambiarla no re-ejecutaria nada); queda
 * declarada como hallazgo fuera de alcance en el informe de esta mision.
 */
class AddIndexesToAddressArticleTables extends Migration
{
    /**
     * Nombres de los cinco indices. Explicitos y no derivados: ver el docblock de la clase.
     * El mas largo mide 59 caracteres, debajo del limite de 64 de MySQL (error 1059).
     */
    const INDICES = [
        'address_article' => [
            'address_article_article_id_address_id_index' => ['article_id', 'address_id'],
            'address_article_address_id_index'            => ['address_id'],
        ],
        'address_article_variant' => [
            'address_article_variant_article_variant_id_address_id_index' => ['article_variant_id', 'address_id'],
            'address_article_variant_address_id_index'                    => ['address_id'],
        ],
        'article_variants' => [
            'article_variants_article_id_index' => ['article_id'],
        ],
    ];

    /**
     * Crea los indices que falten, por nombre exacto.
     *
     * Cada uno va guardado: hay ~40 bases de clientes en estados distintos y en alguna el indice
     * pudo haberse agregado a mano. Sin la guarda, el primer "Duplicate key name" deja la migracion
     * a medias y sin fila en `migrations`.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::INDICES as $tabla => $indices) {

            Schema::table($tabla, function (Blueprint $table) use ($tabla, $indices) {

                foreach ($indices as $nombre => $columnas) {

                    if (!$this->ya_existe_el_indice($tabla, $nombre)) {
                        $table->index($columnas, $nombre);
                    }
                }
            });
        }
    }

    /**
     * Dropea UNICAMENTE los indices que up() crea, y solo si existen con ese nombre exacto.
     *
     * @return void
     */
    public function down()
    {
        foreach (self::INDICES as $tabla => $indices) {

            Schema::table($tabla, function (Blueprint $table) use ($tabla, $indices) {

                foreach ($indices as $nombre => $columnas) {

                    if ($this->ya_existe_el_indice($tabla, $nombre)) {
                        $table->dropIndex($nombre);
                    }
                }
            });
        }
    }

    /**
     * Devuelve true si la tabla tiene un indice con ESE NOMBRE.
     *
     * Se consulta information_schema y no `SHOW INDEX FROM` porque este chequeo corre adentro del
     * closure de Schema::table() y necesita un bind parametrizado limpio; y no se usa
     * Schema::hasIndex() porque ese metodo no existe en la version de Laravel del proyecto, ni
     * doctrine/dbal porque no queremos depender de que este instalado en cada cliente.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return bool
     */
    private function ya_existe_el_indice($tabla, $nombre)
    {
        $indices = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabla, $nombre]
        );

        return count($indices) >= 1;
    }
}
