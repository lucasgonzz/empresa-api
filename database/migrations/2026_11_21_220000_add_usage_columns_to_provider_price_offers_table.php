<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de "uso" del histórico de precios por proveedor (misión
 * historial-precios-proveedor).
 *
 * `provider_price_offers` guardaba "a qué costo ofrecía tal proveedor tal
 * artículo". Estas columnas dicen además qué pasó con esa oferta:
 *  - si se usó para actualizar `articles.cost` (y a cuánto estaba antes),
 *  - con qué evento de `price_changes` quedó vinculada,
 *  - si además cambió el proveedor titular del artículo (y quién lo era antes),
 *  - y cuándo fue la última vez que una importación/compra volvió a ver ese
 *    mismo precio sin que cambiara (para no multiplicar filas idénticas).
 *
 * Todo nullable y sin default de negocio: las filas anteriores a esta versión
 * quedan con NULL = "sin dato" (la pantalla lo dice), sin backfill. Sin
 * foreign keys. El unique de la tabla NO cambia.
 */
class AddUsageColumnsToProviderPriceOffersTable extends Migration
{
    /**
     * Agrega las columnas (cada una con guard hasColumn para poder re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('provider_price_offers')) {
            return;
        }

        Schema::table('provider_price_offers', function (Blueprint $table) {

            /* 1 = se usó para actualizar articles.cost; 0 = sólo registro; NULL = fila anterior (sin dato) */
            if (!Schema::hasColumn('provider_price_offers', 'updated_article_cost')) {
                $table->tinyInteger('updated_article_cost')->nullable()->default(null);
            }

            /* Costo del artículo antes de usar la oferta (NULL si no se usó o si el artículo nació con ella) */
            if (!Schema::hasColumn('provider_price_offers', 'previous_article_cost')) {
                $table->decimal('previous_article_cost', 22, 6)->nullable();
            }

            /* Evento de price_changes que produjo el uso (NULL si no hubo evento). Sin FK */
            if (!Schema::hasColumn('provider_price_offers', 'price_change_id')) {
                $table->unsignedBigInteger('price_change_id')->nullable();
                $table->index('price_change_id', 'ppo_price_change_idx');
            }

            /* 1 = además articles.provider_id pasó a ser el de la oferta */
            if (!Schema::hasColumn('provider_price_offers', 'changed_article_provider')) {
                $table->tinyInteger('changed_article_provider')->nullable()->default(null);
            }

            /* Titular (articles.provider_id) ANTES de la operación */
            if (!Schema::hasColumn('provider_price_offers', 'previous_article_provider_id')) {
                $table->integer('previous_article_provider_id')->nullable();
            }

            /* Última vez que una importación/compra volvió a ver este mismo precio sin cambio */
            if (!Schema::hasColumn('provider_price_offers', 'last_seen_at')) {
                $table->dateTime('last_seen_at')->nullable();
            }

            /* import_history_id / provider_order_id de esa última vez */
            if (!Schema::hasColumn('provider_price_offers', 'last_seen_reference_id')) {
                $table->integer('last_seen_reference_id')->nullable();
            }
        });
    }

    /**
     * Quita las columnas y el índice agregados (reversible).
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('provider_price_offers')) {
            return;
        }

        Schema::table('provider_price_offers', function (Blueprint $table) {
            if (Schema::hasColumn('provider_price_offers', 'price_change_id')) {
                $table->dropIndex('ppo_price_change_idx');
            }
        });

        $columnas = [
            'updated_article_cost',
            'previous_article_cost',
            'price_change_id',
            'changed_article_provider',
            'previous_article_provider_id',
            'last_seen_at',
            'last_seen_reference_id',
        ];

        Schema::table('provider_price_offers', function (Blueprint $table) use ($columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn('provider_price_offers', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
