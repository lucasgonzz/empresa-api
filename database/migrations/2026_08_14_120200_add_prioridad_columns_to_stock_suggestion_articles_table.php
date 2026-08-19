<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migración: columnas de priorización + primeros índices de
 * stock_suggestion_articles.
 *
 * Columnas (todas nullable a propósito):
 * - velocidad_diaria: unidades vendidas por día en el destino (mezcla 90 días
 *   recientes + misma ventana del año anterior, ver CoberturaService).
 * - cobertura_dias: stock_destino / velocidad_diaria = días hasta quiebre.
 *   NULL = velocidad cero (cobertura infinita, ordena al final).
 * - stock_destino: stock del destino al momento del cálculo, para que la
 *   sugerencia quede auditable (una venta de mañana no reescribe la foto).
 * - prioridad: ranking 1..N materializado en una pasada final cuando la
 *   sugerencia queda 'terminado'. Se persiste (no se calcula al leer) para que
 *   cada página del endpoint ordene por columna indexada sin tocar
 *   article_purchases.
 *
 * Las sugerencias viejas quedan con las cuatro columnas en NULL (sin backfill:
 * calcular cobertura de una corrida vieja sería inventar el dato con ventas
 * que no son las de ese día) y ordenan al final.
 *
 * Índices: hoy la tabla no tiene NINGUNO — where('stock_suggestion_id', $id)
 * es full scan y la tabla acumula todas las corridas históricas. Los tres
 * índices cubren el orden por prioridad y los filtros por origen/destino del
 * endpoint paginado; el primero cubre además el filtro simple por prefijo
 * izquierdo. Guard SHOW INDEX para poder re-ejecutar sin "Duplicate key name".
 *
 * Sin foreign keys físicas (regla del schema).
 */
class AddPrioridadColumnsToStockSuggestionArticlesTable extends Migration
{
    /**
     * Nombre del índice => columnas que lo componen. Se itera igual en
     * up()/down().
     *
     * @var array
     */
    private $indices = [
        'stock_suggestion_articles_suggestion_prioridad_index' => ['stock_suggestion_id', 'prioridad'],
        'stock_suggestion_articles_suggestion_from_index'      => ['stock_suggestion_id', 'from_address_id'],
        'stock_suggestion_articles_suggestion_to_index'        => ['stock_suggestion_id', 'to_address_id'],
    ];

    /**
     * Ejecuta la migración: columnas con guard hasColumn e índices con guard
     * SHOW INDEX (ambos re-ejecutables).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('stock_suggestion_articles', 'velocidad_diaria')) {
            Schema::table('stock_suggestion_articles', function (Blueprint $table) {
                $table->decimal('velocidad_diaria', 14, 4)->nullable();
            });
        }

        if (! Schema::hasColumn('stock_suggestion_articles', 'cobertura_dias')) {
            Schema::table('stock_suggestion_articles', function (Blueprint $table) {
                $table->decimal('cobertura_dias', 14, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('stock_suggestion_articles', 'stock_destino')) {
            Schema::table('stock_suggestion_articles', function (Blueprint $table) {
                $table->decimal('stock_destino', 20, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('stock_suggestion_articles', 'prioridad')) {
            Schema::table('stock_suggestion_articles', function (Blueprint $table) {
                $table->integer('prioridad')->nullable();
            });
        }

        foreach ($this->indices as $nombre => $columnas) {

            $index_existente = DB::select("SHOW INDEX FROM stock_suggestion_articles WHERE Key_name = '{$nombre}'");

            if (empty($index_existente)) {
                Schema::table('stock_suggestion_articles', function (Blueprint $table) use ($nombre, $columnas) {
                    $table->index($columnas, $nombre);
                });
            }
        }
    }

    /**
     * Revierte la migración: quita los índices y columnas solo si existen.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->indices as $nombre => $columnas) {

            $index_existente = DB::select("SHOW INDEX FROM stock_suggestion_articles WHERE Key_name = '{$nombre}'");

            if (! empty($index_existente)) {
                Schema::table('stock_suggestion_articles', function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }

        $columnas = ['velocidad_diaria', 'cobertura_dias', 'stock_destino', 'prioridad'];

        foreach ($columnas as $columna) {
            if (Schema::hasColumn('stock_suggestion_articles', $columna)) {
                Schema::table('stock_suggestion_articles', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
