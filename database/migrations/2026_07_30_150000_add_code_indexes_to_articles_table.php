<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tres indices compuestos en `articles` para el cruce de codigos que hace la
 * importacion de Excel (grupo 291, prompt 06):
 *
 *   - (user_id, provider_code) -> articles_user_provider_code_index
 *     Acelera el whereIn de ExcelDuplicateStats::analyze() (hasta 5.000
 *     codigos por consulta, varias consultas con un Excel de 20.000 filas) y
 *     el mismo cruce que corre desde refreshProviderStats() (prompt 03) cada
 *     vez que el usuario cambia de proveedor en el paso 2 del modal.
 *   - (user_id, bar_code) -> articles_user_bar_code_index
 *     Lo usa ArticleIndexCache::build() al armar el indice de matching por
 *     codigo de barras.
 *   - (user_id, sku) -> articles_user_sku_index
 *     Mismo modulo, escalon sku.
 *
 * `user_id` va primero en los tres porque TODAS las consultas del modulo de
 * importacion filtran por comercio. Sin estos indices, MySQL recorre todas
 * las filas del comercio para resolver el whereIn -- la migracion
 * 2026_07_23_120100 documenta inventarios de hasta 500.000 filas por
 * comercio.
 *
 * No se indexa `name`: es text(), no se puede indexar sin prefijo, y el
 * matching por nombre pasa por el indice en memoria (ArticleIndexCache), no
 * por la base.
 *
 * No son indices unicos a proposito: hay clientes con codigos de proveedor
 * repetidos legitimos (flag permitir_provider_code_repetido). Un indice
 * unico rompería la migracion en el momento de correrla para esos clientes.
 *
 * Nota de operacion (igual que 2026_07_23_120100): en MySQL 5.7 y 8, crear un
 * indice secundario usa ALGORITHM=INPLACE por default y no bloquea
 * escrituras, pero en una tabla de 500.000 filas la creacion puede tardar
 * varios minutos y conviene correr esta migracion fuera del horario de
 * atencion del comercio.
 */
class AddCodeIndexesToArticlesTable extends Migration
{
    /**
     * Columna -> [nombre del indice, longitud varchar esperada]. Se itera
     * igual en up()/down(), y la longitud se usa para verificar el tipo real
     * de la columna antes de crear cada indice: varios clientes pueden tener
     * el esquema tocado a mano.
     *
     * @var array
     */
    private $indices = [
        'provider_code' => ['nombre' => 'articles_user_provider_code_index', 'longitud' => 128],
        'bar_code'      => ['nombre' => 'articles_user_bar_code_index',      'longitud' => 128],
        'sku'           => ['nombre' => 'articles_user_sku_index',           'longitud' => 120],
    ];

    /**
     * Crea los tres indices, uno por uno. Si la columna real de algun cliente
     * no es el varchar esperado, ese indice puntual se saltea con un log en
     * vez de fallar toda la migracion -- el resto de los clientes no tienen
     * por que quedarse sin sus indices por el esquema tocado a mano de uno.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->indices as $columna => $datos) {

            if (!$this->columna_es_la_esperada($columna, $datos['longitud'])) {
                Log::warning('AddCodeIndexesToArticlesTable: se salteo el indice porque la columna no tiene el tipo esperado', [
                    'columna'           => $columna,
                    'longitud_esperada' => $datos['longitud'],
                    'indice'            => $datos['nombre'],
                ]);
                continue;
            }

            // Chequeo de existencia previo para que la migracion se pueda
            // correr mas de una vez sin explotar por "Duplicate key name".
            $index_existente = DB::select("SHOW INDEX FROM articles WHERE Key_name = '{$datos['nombre']}'");

            if (empty($index_existente)) {
                Schema::table('articles', function (Blueprint $table) use ($columna, $datos) {
                    $table->index(['user_id', $columna], $datos['nombre']);
                });
            }
        }
    }

    /**
     * Elimina los tres indices, solo los que existan.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->indices as $columna => $datos) {

            $index_existente = DB::select("SHOW INDEX FROM articles WHERE Key_name = '{$datos['nombre']}'");

            if (!empty($index_existente)) {
                Schema::table('articles', function (Blueprint $table) use ($datos) {
                    $table->dropIndex($datos['nombre']);
                });
            }
        }
    }

    /**
     * Verifica que `$columna` en `articles` sea un varchar de la longitud
     * esperada, antes de crear un indice compuesto sobre ella. Un cliente con
     * el esquema tocado a mano podria tener un tipo distinto (text, por
     * ejemplo, que no se puede indexar sin prefijo).
     *
     * @param  string $columna
     * @param  int    $longitud_esperada
     * @return bool
     */
    private function columna_es_la_esperada($columna, $longitud_esperada)
    {
        $fila = DB::selectOne(
            "SELECT DATA_TYPE as data_type, CHARACTER_MAXIMUM_LENGTH as longitud
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'articles'
               AND COLUMN_NAME = ?",
            [$columna]
        );

        if (is_null($fila)) {
            return false;
        }

        return $fila->data_type === 'varchar' && (int) $fila->longitud === (int) $longitud_esperada;
    }
}
