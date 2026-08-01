<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unifica la columna de URL de imagen de la tabla `images` en `hosting_url`.
 *
 * Contexto (grupo 215, prompt 01): la migración original creaba la columna con
 * `env('IMAGE_URL_PROP_NAME', 'image_url')`, por lo que su nombre dependía del `.env`
 * del server al momento de migrar. La mayoría de la flota quedó con `hosting_url`
 * (nombre canónico, hardcodeado 47+ veces en empresa-api), pero algunas bases nuevas
 * (ej. bellabianca2) quedaron con `image_url`, rompiendo en silencio la visualización
 * de imágenes de artículos y el sync a Tienda Nube.
 *
 * Esta migración es idempotente: puede correrse varias veces seguidas sin romper,
 * y resuelve los tres estados posibles en los que puede estar una base de la flota.
 */
class RenameImageUrlToHostingUrlInImagesTable extends Migration
{
    /**
     * Nombre de la tabla afectada.
     *
     * @var string
     */
    private $table = 'images';

    /**
     * Ejecuta la migración.
     *
     * Resuelve, en este orden exacto, los tres estados posibles de la tabla `images`:
     * 1) Solo existe `hosting_url` (estado correcto, mayoría de la flota): no-op.
     * 2) Solo existe `image_url` (caso bellabianca2): se renombra a `hosting_url`.
     * 3) Existen ambas columnas (no debería pasar): se copian los datos de `image_url`
     *    hacia `hosting_url` donde esta última esté vacía/nula, se loguea el caso
     *    porque es anómalo, y se elimina la columna vieja `image_url`.
     *
     * @return void
     */
    public function up()
    {
        // Existencia de cada una de las dos columnas posibles en la tabla actual.
        $hasHostingUrl = Schema::hasColumn($this->table, 'hosting_url');
        $hasImageUrl = Schema::hasColumn($this->table, 'image_url');

        if ($hasHostingUrl && !$hasImageUrl) {
            // Estado correcto: ya tiene el nombre canónico y no hay columna vieja. No-op.
            return;
        }

        if (!$hasHostingUrl && $hasImageUrl) {
            // Caso bellabianca2: solo existe la columna vieja, se renombra directo.
            Schema::table($this->table, function (Blueprint $table) {
                $table->renameColumn('image_url', 'hosting_url');
            });
            return;
        }

        if ($hasHostingUrl && $hasImageUrl) {
            // Caso anómalo: existen las dos columnas a la vez. No debería pasar nunca,
            // así que se loguea para poder detectarlo y revisar cómo llegó la base a ese estado.
            Log::info('rename_image_url_to_hosting_url_in_images_table: se encontraron ambas columnas (image_url y hosting_url) en la tabla images. Copiando datos y eliminando la columna vieja.');

            // Copia el valor de image_url hacia hosting_url solo en las filas donde
            // hosting_url está vacía o nula, para no pisar datos ya migrados.
            DB::statement("UPDATE images SET hosting_url = image_url WHERE hosting_url IS NULL OR hosting_url = ''");

            Schema::table($this->table, function (Blueprint $table) {
                $table->dropColumn('image_url');
            });
            return;
        }

        // Si no existe ninguna de las dos columnas no hay nada para hacer acá: es un
        // estado inesperado que escapa al alcance de esta migración de renombre.
    }

    /**
     * Revierte la migración: si la base quedó con `hosting_url` y sin `image_url`,
     * la vuelve a renombrar a `image_url`. En cualquier otro estado, no-op (no hay
     * un reverso seguro y determinístico para el caso de columnas duplicadas).
     *
     * @return void
     */
    public function down()
    {
        $hasHostingUrl = Schema::hasColumn($this->table, 'hosting_url');
        $hasImageUrl = Schema::hasColumn($this->table, 'image_url');

        if ($hasHostingUrl && !$hasImageUrl) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->renameColumn('hosting_url', 'image_url');
            });
        }
    }
}
