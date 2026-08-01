<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `article_image_search_attempts` (grupo 201) las dos columnas que faltan para poder
 * reconstruir después el resumen de una corrida de asignación automática de imágenes, sin
 * depender del payload efímero que llega por Pusher (grupo 217, Prompt 01):
 *
 * - `assigned_image_url`: la URL final ya guardada en el storage del comercio (la misma que
 *   se inserta en `images`), distinta de la URL original de Google que queda dentro del array
 *   `candidates`.
 * - `needs_review`: si esa asignación quedó marcada para que el comercio la revise a mano.
 *
 * No toca `ProcessArticleBatchImagesJob` (todavía nadie escribe estas columnas, eso es el
 * Prompt 02 de este grupo) ni ningún endpoint (Prompt 03).
 */
class AddAssignedImageToArticleImageSearchAttemptsTable extends Migration
{
    /**
     * Agrega las columnas nuevas, con guardas de idempotencia (hasTable/hasColumn) porque hay
     * bases de clientes donde la tabla del grupo 201 todavía puede no existir.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('article_image_search_attempts')) {
            Schema::table('article_image_search_attempts', function (Blueprint $table) {
                // URL final de la imagen ya descargada, recortada y guardada en el storage del
                // comercio (lo que termina en la tabla `images`). Solo se completa en la fila
                // cuyo `outcome` es `assigned`; en el resto queda null. Largo 500 porque las URLs
                // de storage pueden ser más largas que las de Google.
                if (! Schema::hasColumn('article_image_search_attempts', 'assigned_image_url')) {
                    $table->string('assigned_image_url', 500)->nullable();
                }

                // Si la asignación quedó marcada para revisión manual del comercio (confianza
                // media/baja del aspect ratio o de la IA, o la IA no pudo evaluar la imagen).
                // Solo tiene sentido en la fila con `outcome = assigned`.
                if (! Schema::hasColumn('article_image_search_attempts', 'needs_review')) {
                    $table->boolean('needs_review')->default(false);
                }
            });
        }
    }

    /**
     * Revierte agregando las mismas guardas de idempotencia que el up().
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('article_image_search_attempts')) {
            Schema::table('article_image_search_attempts', function (Blueprint $table) {
                if (Schema::hasColumn('article_image_search_attempts', 'assigned_image_url')) {
                    $table->dropColumn('assigned_image_url');
                }

                if (Schema::hasColumn('article_image_search_attempts', 'needs_review')) {
                    $table->dropColumn('needs_review');
                }
            });
        }
    }
}
