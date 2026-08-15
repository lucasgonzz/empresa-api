<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Dos indices sobre `article_purchases`, la tabla analitica de lineas vendidas
 * (mision sugerencias inteligentes de stock):
 *
 *   - (address_id, article_id, created_at) -> article_purchases_address_article_created_index
 *     Es la agregacion caliente del calculo de prioridad por dias hasta quiebre:
 *     SUM(amount) agrupado por (article_id, address_id) filtrando por ventana de
 *     created_at, repetida por cada chunk de 5000 articulos de una sugerencia.
 *     Hoy la tabla no tiene NINGUN indice, asi que cada agregacion es un full
 *     scan sobre un volumen esperable de 10^5 a 10^6 filas.
 *   - (created_at) -> article_purchases_created_at_index
 *     Cubre los barridos que filtran solo por rango de fechas, sin sucursal ni
 *     articulo (la ventana interanual completa y los reportes por periodo).
 *
 * Nota de operacion (igual que 2026_07_30_150000 y 2026_07_23_120100): en MySQL
 * 5.7 y 8, crear un indice secundario usa ALGORITHM=INPLACE por default y no
 * bloquea escrituras, pero en una tabla de 10^5 a 10^6 filas la creacion puede
 * tardar varios minutos y consumir I/O. Correr esta migracion fuera del horario
 * de atencion del comercio. Va en migracion propia, separada del resto del
 * esquema de la mision, para que el despliegue pueda declararla y correrla
 * aparte del resto.
 */
class AddIndexesToArticlePurchasesTable extends Migration
{
    /**
     * Nombre del indice -> columnas que lo componen, en orden. Se itera igual
     * en up() y down().
     *
     * @var array
     */
    private $indices = [
        'article_purchases_address_article_created_index' => ['address_id', 'article_id', 'created_at'],
        'article_purchases_created_at_index'              => ['created_at'],
    ];

    /**
     * Crea los dos indices, cada uno con chequeo previo de existencia
     * (SHOW INDEX) para que la migracion se pueda correr mas de una vez sin
     * explotar por "Duplicate key name".
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->indices as $nombre => $columnas) {

            $index_existente = DB::select("SHOW INDEX FROM article_purchases WHERE Key_name = '{$nombre}'");

            if (empty($index_existente)) {
                Schema::table('article_purchases', function (Blueprint $table) use ($nombre, $columnas) {
                    $table->index($columnas, $nombre);
                });
            }
        }
    }

    /**
     * Elimina los dos indices, solo los que existan.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->indices as $nombre => $columnas) {

            $index_existente = DB::select("SHOW INDEX FROM article_purchases WHERE Key_name = '{$nombre}'");

            if (!empty($index_existente)) {
                Schema::table('article_purchases', function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }
    }
}
