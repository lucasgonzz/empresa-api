<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Índices sobre `views`, la tabla polimórfica de vistas de la tienda vieja
 * (misión tracking-buyers-tienda). Cierra un hallazgo abierto del relevamiento:
 * `views` no tiene NINGÚN índice fuera de su primary key, así que toda consulta
 * por viewable o por comprador es un full scan.
 *
 *   - (viewable_type, viewable_id) -> views_viewable_index
 *     Es la consulta natural de una tabla polimórfica —"todas las vistas de este
 *     artículo"— y hoy no tiene por dónde entrar. El orden es el de Laravel para
 *     un morphIndex: el tipo primero, porque es el que discrimina la tabla.
 *   - (buyer_id) -> views_buyer_index
 *     Cubre Buyer::views(), que hoy escanea la tabla entera por cada comprador.
 *
 * 🔴 `last_searches` queda SIN TOCAR, y el nombre de esta migración la nombra
 * igual para que quede escrito por qué. Sus dos candidatos a índice ya están
 * cubiertos: `buyer_id` tiene el índice implícito que MySQL creó junto con la
 * foreign key física de 2021 (2021_03_24_175131:22), y agregarle otro sería un
 * índice duplicado — el mismo árbol mantenido dos veces en cada insert. Y esa
 * foreign key TAMPOCO se saca acá: es preexistente, sacarla es un cambio no
 * aditivo, y esta misión es aditiva de punta a punta. Queda como hallazgo.
 *
 * Nota de operación (igual que 2026_08_14_120400 y 2026_07_30_150000): en MySQL 5.7
 * y 8, crear un índice secundario usa ALGORITHM=INPLACE por default y no bloquea
 * escrituras, pero en una tabla de 10^5 a 10^6 filas la creación puede tardar
 * varios minutos y consumir I/O — correrla fuera del horario de atención. Hoy
 * `views` está en 0 filas en las instancias medidas, así que es gratis; la nota
 * vale para el día que no lo esté.
 */
class AddIndexesToViewsAndLastSearchesTables extends Migration
{
    /**
     * Nombre del índice -> columnas que lo componen, en orden. Se itera igual
     * en up() y down().
     *
     * @var array
     */
    private $indices = [
        'views_viewable_index' => ['viewable_type', 'viewable_id'],
        'views_buyer_index'    => ['buyer_id'],
    ];

    /**
     * Crea los dos índices, cada uno con chequeo previo de existencia
     * (SHOW INDEX) para que la migración se pueda correr más de una vez sin
     * explotar por "Duplicate key name".
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('views')) {
            return;
        }

        foreach ($this->indices as $nombre => $columnas) {

            $index_existente = DB::select("SHOW INDEX FROM views WHERE Key_name = '{$nombre}'");

            if (empty($index_existente)) {
                Schema::table('views', function (Blueprint $table) use ($nombre, $columnas) {
                    $table->index($columnas, $nombre);
                });
            }
        }
    }

    /**
     * Elimina los dos índices, sólo los que existan.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('views')) {
            return;
        }

        foreach ($this->indices as $nombre => $columnas) {

            $index_existente = DB::select("SHOW INDEX FROM views WHERE Key_name = '{$nombre}'");

            if (!empty($index_existente)) {
                Schema::table('views', function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }
    }
}
