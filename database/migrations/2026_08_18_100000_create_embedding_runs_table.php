<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de una tanda de generación de embeddings (misión embeddings-por-lote-y-semilla).
 * Mismo rol que `import_statuses` cumple para una importación de Excel: el comando
 * `articles:generate-embeddings` abre la fila, cada job suma su resultado, y un job
 * finalizador la cierra y recién ahí notifica.
 *
 * 🔴 POR QUÉ EXISTE ESTA TABLA, si el comando "ya informaba" cuántos embeddings generó:
 * no informaba eso. Informaba JOBS DESPACHADOS, que es otro número. Un job puede cortar
 * por `embedding_source_hash` sin llamar a OpenAI, puede encontrarse con un artículo sin
 * texto que vectorizar, o puede fallar tres veces y rendirse. El comando corre en el
 * scheduler y los jobs en el worker: cuando el comando termina de imprimir, no se generó
 * todavía ni un vector. Los contadores de acá son lo único que sabe qué pasó de verdad.
 *
 * Descartado `Bus::batch` (un batch sabe cuántos jobs terminaron, no cuántos hicieron algo;
 * y `InitExcelImport` ya documenta por qué el repo se fue de los batches). Descartado
 * `Cache::increment` (se lo lleva puesto un `cache:clear`, no es inspeccionable y no se
 * puede assertar en un test).
 *
 * status: 'despachando' (el comando todavía está encolando jobs) → 'en_proceso' (ya
 * despachó todo, se esperan los resultados) → 'completada' | 'vencida' (se cerró con lo
 * que había porque pasó la ventana de 20 minutos).
 *
 * 🔴 'despachando' NO ES COSMÉTICO. Con `QUEUE_CONNECTION=database` el worker levanta los
 * jobs del primer chunk mientras el comando todavía despacha el segundo. Si la tanda
 * naciera en 'en_proceso' con `total_jobs = 0`, el finalizador vería
 * `terminados (0) >= total_jobs (0)` y la cerraría antes de que se despachara nada.
 *
 * origen: 'scheduler' (la corrida agendada cada 30 min) | 'importacion' (disparada al
 * terminar una importación de Excel) | 'manual' (alguien corrió el comando a mano).
 * Viaja hasta el toast, así que no es solo para auditar.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateEmbeddingRunsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('embedding_runs')) {
            return;
        }

        Schema::create('embedding_runs', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /*
             * Dueño de la tanda. integer y no unsignedBigInteger porque `users.id` es
             * increments() -> int unsigned
             * (2014_10_12_000000_create_users_table.php): espejar la fuente acá SIGNIFICA
             * integer, igual que en `offer_suggestions` y `purchase_suggestions`.
             */
            $table->integer('user_id');

            /* 'scheduler' | 'importacion' | 'manual' */
            $table->string('origen', 20)->default('scheduler');

            /* 'despachando' | 'en_proceso' | 'completada' | 'vencida' */
            $table->string('status', 20)->default('despachando');

            /*
             * Cuántos jobs se despacharon en total. Lo escribe el comando recién DESPUÉS
             * de terminar de encolar, junto con el pase a 'en_proceso' (ver el bloque rojo
             * del docblock).
             */
            $table->integer('total_jobs')->default(0);

            /*
             * terminados = generados + salteados + fallados. Se lleva aparte en vez de
             * sumarse al vuelo porque es la comparación contra `total_jobs` que decide si
             * la tanda ya se puede cerrar, y hacerla con un solo campo evita que un
             * incremento a medio camino la deje cerrando de más.
             */
            $table->integer('terminados')->default(0);

            /*
             * 🔴 `generados` ES EL NÚMERO DEL TOAST, y el motivo entero de la tabla. Solo
             * cuenta los artículos que quedaron con un vector NUEVO escrito. Los que
             * cortaron por hash y los que no tenían texto van a `salteados`, aunque su job
             * haya terminado sin errores: para el comerciante, un artículo que ya estaba
             * vectorizado no es una novedad.
             */
            $table->integer('generados')->default(0);
            $table->integer('salteados')->default(0);
            $table->integer('fallados')->default(0);

            /*
             * Candado de idempotencia del broadcast. El finalizador se re-despacha varias
             * veces mientras espera a los jobs, así que la única garantía de que el toast
             * salga UNA vez es este campo.
             *
             * 🔴 La idempotencia va por acá y NO por `status`: el status también lo toca la
             * guarda anti-solapamiento del comando (marca 'vencida' una tanda vieja), así
             * que condicionar el evento al status haría que en algunos caminos no se emita
             * nunca. Es exactamente el error que `FinalizeArticleImport` ya documenta en
             * rojo después de habérselo comido.
             */
            $table->timestamp('notificado_at')->nullable();

            /* `created_at` es además el reloj del vencimiento de 20 minutos */
            $table->timestamps();

            /*
             * Único índice, y el porqué: las dos consultas que existen entran igual —
             * la guarda anti-solapamiento del comando ("¿este user tiene una tanda
             * abierta?", user_id + status IN (...)) y el barrido de tandas vencidas.
             * Ninguno más "por las dudas": cada índice es un árbol que se actualiza en
             * cada UPDATE de contador, y acá los UPDATEs son uno por job.
             */
            $table->index(['user_id', 'status'], 'embedding_runs_user_id_status_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('embedding_runs');
    }
}
