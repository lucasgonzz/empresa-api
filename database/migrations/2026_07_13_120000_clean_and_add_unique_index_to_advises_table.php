<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza de datos basura en `advises` + índice único (article_id, email).
 *
 * Contexto (prompt 354): el endpoint público de tienda que crea avisos no valida nada
 * (se corrige en el prompt 355) y tienda-spa tenía un bug que posteaba con email vacío
 * (se corrige en el prompt 356). Eso genera filas con email inválido, filas huérfanas
 * (article_id de un artículo borrado) y filas duplicadas para el mismo (article_id, email).
 *
 * Esta migración limpia esa data existente y agrega el índice único que evita que se
 * vuelvan a generar duplicados a nivel base de datos.
 */
class CleanAndAddUniqueIndexToAdvisesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        // Los pasos de limpieza (1-3) tienen que correr antes de crear el índice único,
        // porque si la tabla ya tiene duplicados o basura, el unique() del paso 4 revienta.
        // Se envuelven en try/catch para no dejar la migración a medias sin el índice
        // si algo falla durante la limpieza (por ejemplo, en una base que ya esté limpia).
        try {
            $this->deleteRowsWithInvalidEmail();
            $this->deleteOrphanRows();
            $this->deduplicateRows();
        } catch (\Exception $e) {
            Log::error('advises cleanup migration: error durante la limpieza previa al índice único: ' . $e->getMessage());
        }

        Schema::table('advises', function (Blueprint $table) {
            // Índice único sobre (article_id, email) para que no puedan existir dos
            // avisos para el mismo artículo y el mismo email (a nivel base de datos).
            $table->unique(['article_id', 'email'], 'advises_article_email_unique');

            // Índice normal sobre article_id: ArticleHelper::checkAdvises() filtra por esta
            // columna en cada movimiento de stock, conviene tenerlo indexado.
            $table->index('article_id', 'advises_article_id_idx');
        });
    }

    /**
     * Borra las filas de `advises` cuyo email es null, vacío, o no tiene formato de email
     * válido (filter_var con FILTER_VALIDATE_EMAIL). Recorre la tabla en chunks para no
     * cargar todo en memoria de una vez. Loguea cuántas filas se borraron.
     *
     * @return void
     */
    private function deleteRowsWithInvalidEmail()
    {
        // Contador de filas borradas por email inválido, para el log final.
        $deletedCount = 0;

        DB::table('advises')->orderBy('id')->chunkById(200, function ($rows) use (&$deletedCount) {
            // Ids de las filas de este chunk que tienen email inválido (null, vacío o
            // que no pasa la validación de formato de email).
            $invalidIds = [];

            foreach ($rows as $row) {
                // Email normalizado (trim) solo para evaluar si está vacío o es inválido,
                // sin modificar todavía el dato guardado (eso se hace en la deduplicación).
                $email = $row->email === null ? '' : trim($row->email);

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $invalidIds[] = $row->id;
                }
            }

            if (!empty($invalidIds)) {
                $deletedCount += DB::table('advises')->whereIn('id', $invalidIds)->delete();
            }
        });

        Log::info('advises cleanup migration: se borraron ' . $deletedCount . ' filas con email inválido.');
    }

    /**
     * Borra las filas de `advises` cuyo article_id apunta a un artículo que ya no existe
     * en la tabla `articles`. Estas filas nunca se borran solas (solo se borran al enviar
     * el mail cuando entra stock) y crecerían para siempre si no se limpian acá.
     *
     * @return void
     */
    private function deleteOrphanRows()
    {
        // Borra en una sola consulta las filas cuyo article_id no exista en articles.
        $deletedCount = DB::table('advises')
            ->whereNotIn('article_id', function ($query) {
                $query->select('id')->from('articles');
            })
            ->delete();

        Log::info('advises cleanup migration: se borraron ' . $deletedCount . ' filas huérfanas (article_id inexistente).');
    }

    /**
     * Deja una sola fila por cada par (article_id, email) normalizado (trim + lowercase),
     * conservando la de id más bajo y borrando el resto. Además, actualiza el email de la
     * fila que se conserva para que quede guardado ya normalizado (sin esto, el índice
     * único del paso 4 no evita duplicados como "Juan@Mail.com" vs "juan@mail.com").
     *
     * @return void
     */
    private function deduplicateRows()
    {
        // Trae solo lo necesario para agrupar: id, article_id y email.
        $rows = DB::table('advises')->select('id', 'article_id', 'email')->orderBy('id')->get();

        // Agrupa las filas por clave normalizada "article_id|email_normalizado" para
        // detectar duplicados que difieren solo en mayúsculas/minúsculas o espacios.
        $groups = [];

        foreach ($rows as $row) {
            $normalizedEmail = strtolower(trim($row->email));
            $key = $row->article_id . '|' . $normalizedEmail;

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $row;
        }

        // Ids a borrar (todas las filas duplicadas menos la de id más bajo de cada grupo).
        $idsToDelete = [];

        foreach ($groups as $key => $groupRows) {
            // Ordena por id ascendente para conservar siempre la más antigua (id más bajo).
            usort($groupRows, function ($a, $b) {
                return $a->id <=> $b->id;
            });

            // La primera fila (id más bajo) es la que se conserva.
            $keeper = $groupRows[0];
            $normalizedEmail = strtolower(trim($keeper->email));

            // Si el email guardado no está exactamente normalizado, se actualiza para que
            // el índice único sirva de verdad a futuro.
            if ($keeper->email !== $normalizedEmail) {
                DB::table('advises')->where('id', $keeper->id)->update(['email' => $normalizedEmail]);
            }

            // El resto de las filas del grupo (si hay más de una) se marcan para borrar.
            for ($i = 1; $i < count($groupRows); $i++) {
                $idsToDelete[] = $groupRows[$i]->id;
            }
        }

        if (!empty($idsToDelete)) {
            $deletedCount = DB::table('advises')->whereIn('id', $idsToDelete)->delete();
            Log::info('advises cleanup migration: se borraron ' . $deletedCount . ' filas duplicadas (article_id, email).');
        } else {
            Log::info('advises cleanup migration: no se encontraron filas duplicadas (article_id, email).');
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('advises', function (Blueprint $table) {
            $table->dropUnique('advises_article_email_unique');
            $table->dropIndex('advises_article_id_idx');
        });
    }
}
