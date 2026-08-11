<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: crea price_update_runs, una fila por corrida de recálculo de precios.
 *
 * Existe porque hasta ahora el recálculo en segundo plano avisaba cuando ARRANCABA, no
 * cuando terminaba: ProcessSetFinalPrices encolaba los chunks y notificaba en la misma
 * función. Para poder avisar al final con números ciertos hace falta un lugar donde
 * llevar la cuenta de los chunks y el resultado.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreatePriceUpdateRunsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('price_update_runs')) {
            return;
        }

        Schema::create('price_update_runs', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unsigned()->index();

            /**
             * Por qué se recalculó: configuracion_usuario | dolar | tipo_de_precio |
             * listas_de_precio | proveedor | otro. Es lo que el modal le muestra al usuario
             * como chip ("Se recalcularon por un cambio en el dólar"): que sepa por qué se le
             * movieron los precios es la mitad del valor de este aviso.
             */
            $table->string('origen', 50);
            $table->string('origen_detalle', 255)->nullable();

            /** en_proceso | terminado | sin_cambios | error */
            $table->string('status', 20);

            $table->integer('total_chunks')->default(0);
            $table->integer('processed_chunks')->default(0);

            /**
             * Se pone en 1 DESPUES de que termina el bucle que despacha los chunks.
             *
             * No es decorativo: los chunks se despachan dentro de un ->chunk(100, ...), así que
             * los primeros ya pueden estar terminando mientras el bucle todavía despacha. Un
             * finalizador que sólo mirara processed_chunks >= total_chunks cerraría la corrida
             * con la mitad del catálogo sin procesar, y el modal mostraría números falsos sin
             * que nada falle.
             */
            $table->boolean('chunks_encolados')->default(0);

            /** Artículos cuyo final_price EFECTIVAMENTE cambió, no los procesados. */
            $table->integer('articles_updated')->default(0);

            /** Desglose por proveedor y por categoría, ya agregado. */
            $table->longText('stats_json')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('price_update_runs');
    }
}
