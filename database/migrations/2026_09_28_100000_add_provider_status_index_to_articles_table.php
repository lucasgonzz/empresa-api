<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice `articles_user_provider_status_idx` (misión importacion-lento-vender-y-historial,
 * 28/9/2026), mismo patrón idempotente que
 * `2026_09_10_190000_add_hot_path_indexes_to_articles_and_inventory_pivot.php` y
 * `2026_09_14_130000_add_deleted_index_to_articles_table.php`.
 *
 * Origen: diagnóstico en producción de Servian (27-28/9/2026) sobre el slow log real de MySQL,
 * durante la ventana de una importación de Excel en curso. Una de las dos causas de contención
 * detectadas fue una búsqueda con `provider_id` fijo (además de `status`/`deleted_at`) y
 * `provider_code LIKE '%texto%'` (comodín inicial, sin índice posible sobre esa columna),
 * escaneando el catálogo completo del cliente por PRIMARY en 3-5s cada vez que la importación
 * competía con ella. El endpoint concreto que arma ese WHERE con `provider_id` no se identificó
 * con certeza (ver nota al final); el índice ayuda igual a CUALQUIER consulta con este patrón,
 * lo dispare quien lo dispare.
 *
 * 🔴 FULLTEXT con `WITH PARSER ngram` queda descartado de entrada (no es lo que resuelve esta
 * migración): es la única forma de indexar substrings dentro de una palabra en MySQL, pero
 * MariaDB —que corre en el shared hosting de Hostinger, confirmado por SSH— no soporta ese
 * parser. Esta migración NO cambia el comportamiento de la búsqueda (sigue siendo
 * `LIKE '%texto%'`, "contiene en cualquier posición"): solo acelera el filtro de igualdades que
 * va ANTES del LIKE (`user_id`, `provider_id`, `status`, `deleted_at`), que es lo que reduce el
 * conjunto de filas sobre el que el LIKE tiene que evaluar carácter por carácter.
 *
 * Medido con EXPLAIN + timing real en `empresa_testing_s8`, que ya tenía cargados 750.010
 * artículos sintéticos de un solo owner (`user_id=500`, 8 `provider_id` repartidos ~93.750 c/u,
 * `status='active'`, `deleted_at IS NULL` — mismo volumen que Servian). Se corrió la consulta
 * real (`user_id` + `provider_id` fijo + `provider_code LIKE '%150%'` + `status='active'` +
 * `deleted_at IS NULL`, `ORDER BY id DESC LIMIT 50`) dropeando y recreando el índice para medir
 * los dos lados sobre los mismos datos, no en máquinas ni corridas distintas:
 *
 * | Caso                                             | EXPLAIN                                                                  | Tiempo real (3 corridas) |
 * |---------------------------------------------------|---------------------------------------------------------------------------|--------------------------|
 * | Sin `articles_user_provider_status_idx`            | `type=index`, `key=PRIMARY`, rows=100, Using where; Backward index scan   | 4,88s / 5,50s / 3,12s    |
 * | Con `articles_user_provider_status_idx` (este índice) | `type=ref`, `key=articles_user_provider_status_idx`, key_len=19, Using where; Backward index scan | 0,46s / 0,52s / 0,57s    |
 *
 * El optimizador elige el índice nuevo SOLO (sin `FORCE INDEX`): las cuatro columnas del WHERE
 * (`user_id`, `provider_id`, `status`, `deleted_at`) son igualdades exactas que calzan con las
 * cuatro columnas del índice en orden, así que MySQL resuelve el filtro completo por `ref` antes
 * de evaluar el LIKE fila por fila sobre el subconjunto que queda (en vez de sobre las 750.010
 * filas del owner). La mejora medida es de ~9× en esta corrida (varía por carga de la máquina;
 * el plan original, con menos ruido de fondo, había medido ~4×, mismo orden de magnitud).
 *
 * `provider_id` va segundo (después de `user_id`, que es igual para toda la tabla en una
 * instancia real) porque es la columna de mayor selectividad disponible antes del LIKE: reparte
 * el catálogo en ~8 franjas iguales. `status` y `deleted_at` van después porque en la práctica
 * son casi constantes dentro de "catálogo activo" (la inmensa mayoría de las filas activas tiene
 * el mismo valor en las dos), pero como son las columnas que el resto del WHERE fija en igualdad,
 * completan el índice sin costo extra y evitan un lookup a la fila solo para confirmarlas
 * (quedan resueltas por el índice, "Using where" sin ir a datos para esas dos).
 *
 * Nota sobre el endpoint real: se buscó por grep (no se adivinó) qué controlador arma
 * exactamente `provider_id = ? AND provider_code LIKE ? AND status = ? AND deleted_at IS NULL
 * ORDER BY id DESC` combinando las cuatro condiciones en una sola consulta. No se encontró un
 * único lugar que arme las cuatro juntas tal cual — el hallazgo completo, con lo que sí se
 * descartó y lo que quedó sin resolver, va en el informe final de la misión. La migración vale
 * por sí sola: ayuda a cualquier consulta futura o dinámica (filtros de Listado, Compras) que
 * combine estas cuatro columnas, sin necesidad de identificar el disparador exacto.
 *
 * La guarda por `SHOW INDEX` (por NOMBRE) es para las instancias donde alguien lo haya creado a
 * mano por SSH antes de este upgrade, o donde ya exista un índice de prueba con este mismo
 * nombre (como en este mismo slot, que ya lo tenía cargado para la investigación previa al plan).
 */
class AddProviderStatusIndexToArticlesTable extends Migration
{
    /** Nombre corto y fijo del índice: la guarda y el down() miran exactamente este. */
    const INDICE = 'articles_user_provider_status_idx';

    /** Columnas del índice, en el orden que decidió el EXPLAIN (ver docblock: NO reordenar). */
    const COLUMNAS = ['user_id', 'provider_id', 'status', 'deleted_at'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ($this->existe_indice()) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) {
            $table->index(self::COLUMNAS, self::INDICE);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! $this->existe_indice()) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }

    /**
     * ¿La tabla ya tiene un índice con ESE NOMBRE en la base conectada?
     *
     * SHOW INDEX en vez de Schema::hasIndex() porque ese método no existe en el Laravel del
     * proyecto, y en vez de doctrine/dbal para no depender de que esté instalado en cada cliente.
     *
     * @return bool
     */
    private function existe_indice()
    {
        return count(DB::select('SHOW INDEX FROM `articles` WHERE Key_name = ?', [self::INDICE])) > 0;
    }
}
