<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identificador de la venta para que su alta sea idempotente (misión ingreso-sin-internet,
 * 23/10/2026).
 *
 * `sales.offline_uuid` lo genera la SPA UNA vez por venta y viaja igual en cada reintento del
 * `POST /sale`: el POST online de VENDER y la subida de una venta que quedó guardada en el equipo
 * sin conexión. Con él `SaleController::store()` reconoce una venta que ya subió (respuesta cortada,
 * reintento) y la devuelve en vez de crearla dos veces —stock y caja dos veces—, y deja de depender
 * de la guarda de 5 segundos por contenido, que descartaba la segunda de dos ventas iguales subidas
 * seguidas. La regla completa está en `VentaOfflineHelper`.
 *
 * - `string(36)`, nullable, sin default: el largo de un UUID canónico. Las ventas existentes y las
 *   que llegan sin uuid (el asistente de IA, una SPA vieja) quedan en NULL. Sin relleno.
 * - Índice ÚNICO `sales_user_offline_uuid_uq (user_id, offline_uuid)`: es la última red contra dos
 *   altas simultáneas con el mismo uuid que el candado `GET_LOCK` no llegó a serializar, y es el
 *   índice por el que va la búsqueda del uuid. Por comercio y no global: el uuid lo genera cada
 *   equipo, y dos bases compartidas por varios comercios no tienen por qué chocar entre sí. MySQL
 *   permite varios NULL en un índice único, así que las ventas sin uuid no chocan. Nombre corto a
 *   propósito (el que arma Laravel por defecto pasa los 64 caracteres en otras tablas del repo), y
 *   es el mismo que `VentaOfflineHelper::INDICE_UNICO` reconoce en el error 1062.
 * - Sin foreign keys (convención del repo).
 *
 * 🔴 El deploy sube los archivos ANTES de migrar: el código que escribe la columna pasa por
 * `OfflineUuidEsquemaHelper`, que la saca del INSERT mientras no exista.
 *
 * Re-ejecutable: la columna y el índice se miran por separado, así que una corrida que se cortó
 * entre los dos pasos se completa en la siguiente sin chocar con "Duplicate column" ni "Duplicate
 * key name".
 */
class AddOfflineUuidToSalesTable extends Migration
{
    /** Nombre del índice único (el mismo de `VentaOfflineHelper::INDICE_UNICO`). */
    const INDICE = 'sales_user_offline_uuid_uq';

    /**
     * Agrega la columna y el índice único, cada uno solo si falta.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('sales', 'offline_uuid')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('offline_uuid', 36)->nullable()->after('num');
            });
        }

        if (!$this->existe_indice()) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique(['user_id', 'offline_uuid'], self::INDICE);
            });
        }
    }

    /**
     * Saca el índice y la columna, cada uno solo si está.
     *
     * @return void
     */
    public function down()
    {
        if ($this->existe_indice()) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique(self::INDICE);
            });
        }

        if (Schema::hasColumn('sales', 'offline_uuid')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('offline_uuid');
            });
        }
    }

    /**
     * ¿La tabla `sales` de esta base ya tiene el índice único?
     *
     * Por `information_schema.STATISTICS` de la base conectada, igual que las migraciones de índices
     * del repo (`2026_09_10_190000`): Laravel 8 no trae un `hasIndex()`.
     *
     * @return bool
     */
    protected function existe_indice()
    {
        return DB::table('information_schema.STATISTICS')
                    ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                    ->where('TABLE_NAME', 'sales')
                    ->where('INDEX_NAME', self::INDICE)
                    ->exists();
    }
}
