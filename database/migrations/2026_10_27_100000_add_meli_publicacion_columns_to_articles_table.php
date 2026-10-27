<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión mercado-libre-integracion (27/10/2026): datos de la publicación de Mercado Libre de cada
 * artículo, para que el formulario muestre el estado sin consultar a ML y para que la
 * sincronización mande solo lo que cambió.
 *
 * - `meli_permalink`: link público de la publicación (lo devuelve ML al crear/consultar el ítem).
 * - `meli_status`: estado del ítem en ML (`active`, `paused`, `closed`, `under_review`, ...).
 * - `meli_user_product_id`: id del User Product (`MLAU...`) cuando la cuenta publica con el modelo
 *   nuevo de ML. Sirve para el stock por UP y para reconstruir la familia.
 * - `meli_sync_state`: JSON con los hashes de lo último que se mandó (fotos, atributos,
 *   descripción) y la marca `pausado_por_sistema`. Sin esto, cada cambio de precio volvía a
 *   mandar fotos y descripción, que es justo lo que gasta llamadas del cupo compartido de la app.
 *
 * Aditiva, nullable y sin índices: `articles` la lee también `tienda-api`, y una columna nueva
 * nullable no la afecta. Cada columna se agrega solo si falta (idempotente ante un upgrade que
 * se corta a la mitad).
 */
class AddMeliPublicacionColumnsToArticlesTable extends Migration
{
    /**
     * Agrega las columnas que falten.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('articles')) {
            return;
        }

        $faltan = [];
        foreach (['meli_permalink', 'meli_status', 'meli_user_product_id', 'meli_sync_state'] as $columna) {
            if (!Schema::hasColumn('articles', $columna)) {
                $faltan[] = $columna;
            }
        }

        if (count($faltan) === 0) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) use ($faltan) {
            if (in_array('meli_permalink', $faltan, true)) {
                $table->string('meli_permalink', 255)->nullable();
            }
            if (in_array('meli_status', $faltan, true)) {
                $table->string('meli_status', 40)->nullable();
            }
            if (in_array('meli_user_product_id', $faltan, true)) {
                $table->string('meli_user_product_id', 60)->nullable();
            }
            if (in_array('meli_sync_state', $faltan, true)) {
                $table->text('meli_sync_state')->nullable();
            }
        });
    }

    /**
     * Saca las columnas. Lo que se pierde se vuelve a completar en la próxima sincronización.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('articles')) {
            return;
        }

        foreach (['meli_permalink', 'meli_status', 'meli_user_product_id', 'meli_sync_state'] as $columna) {
            if (Schema::hasColumn('articles', $columna)) {
                Schema::table('articles', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
