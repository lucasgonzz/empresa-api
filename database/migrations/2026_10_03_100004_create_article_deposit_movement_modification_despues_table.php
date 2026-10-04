<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: crea `article_deposit_movement_modification_despues` (misión
 * movimientos-deposito-auditoria, 3/10/2026).
 *
 * Foto de los artículos de un movimiento de depósito JUSTO DESPUÉS de una modificación: una fila
 * por renglón, con su cantidad y su variante, leída del pivot ya sincronizado. La hermana
 * `..._antes` guarda la foto de antes. Mismo patrón que
 * `article_sale_modification_despues_de_actualizar` en las ventas.
 *
 * `LimpiarInventarioHelper` la limpia junto con `article_deposit_movement` (tipo `article_id`).
 *
 * Sin foreign keys físicas (regla del repo) y con nombres de índice cortos.
 */
class CreateArticleDepositMovementModificationDespuesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('article_deposit_movement_modification_despues')) {
            return;
        }

        Schema::create('article_deposit_movement_modification_despues', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            $table->index('article_id', 'admm_desp_art_idx');

            $table->integer('deposit_movement_modification_id');
            $table->index('deposit_movement_modification_id', 'admm_desp_mod_idx');

            $table->decimal('amount', 12, 2)->nullable();
            $table->integer('article_variant_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_deposit_movement_modification_despues');
    }
}
