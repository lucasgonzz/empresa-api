<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detecta artículos duplicados por provider_code + name y elimina los sobrantes.
 */
class articulos_repetidos_servian extends Command
{
    /**
     * articulos_repetidos_servian {eliminar_repetidos?} {--detalle : Lista cada grupo en consola (lento con muchos grupos)}
     *
     * - Detecta grupos con el mismo provider_code y el mismo name (mismo user_id)
     * - Si eliminar_repetidos es true, conserva el que tenga bar_code (si hay); si no, el de mayor id
     * - Procesamiento en lote: una lectura de artículos duplicados y borrados masivos por chunks
     */
    protected $signature = 'articulos_repetidos_servian {eliminar_repetidos?} {--detalle : Muestra detalle de cada grupo (no recomendado con miles de grupos)}';

    /**
     * Descripción mostrada en php artisan list.
     *
     * @var string
     */
    protected $description = 'Busca artículos con el mismo provider_code y name; opcionalmente elimina duplicados conservando el que tenga bar_code o, si ninguno, el de mayor id.';

    /**
     * Tamaño de chunk para leer artículos duplicados de la BD.
     *
     * @var int
     */
    protected $read_chunk_size = 3000;

    /**
     * Tamaño de chunk para borrados masivos.
     *
     * @var int
     */
    protected $delete_chunk_size = 2000;

    /**
     * Punto de entrada del comando Artisan.
     *
     * @return int
     */
    public function handle()
    {
        // Si el argumento viene en true, se ejecuta el borrado de duplicados.
        $eliminar_repetidos = (bool) $this->argument('eliminar_repetidos');

        // Detalle línea por línea solo si se pide (--detalle).
        $verbose = (bool) $this->option('detalle');

        // Usuario dueño de la instancia (misma convención que otros comandos de artículos).
        $user_id = config('app.USER_ID');

        // Cantidad de grupos repetidos (una sola consulta agregada).
        $total_groups = $this->count_duplicate_groups($user_id);

        $this->comment("Grupos repetidos (provider_code + name): {$total_groups}");

        if ($total_groups === 0) {
            $this->comment('No hay duplicados.');
            return 0;
        }

        if ($eliminar_repetidos) {
            $this->comment('Modo eliminación (procesamiento en lote)...');
        } elseif (!$verbose) {
            $this->comment('Modo vista previa resumida. Usá --detalle para listar cada grupo.');
        }

        // IDs a eliminar acumulados en memoria; se vacían al flushear por chunks de borrado.
        $ids_to_delete = [];

        // Buffer del grupo actual mientras se recorre el cursor ordenado.
        $group_buffer = [];

        // Clave del grupo anterior (provider_code + separador + name).
        $last_group_key = null;

        // Contadores para el resumen final.
        $groups_processed = 0;
        $articles_in_duplicate_groups = 0;
        $verbose_groups_shown = 0;
        $verbose_groups_limit = 20;

        // Recorre todos los artículos pertenecientes a grupos duplicados en pocas consultas.
        $this->stream_duplicate_articles($user_id, function ($rows) use (
            &$group_buffer,
            &$last_group_key,
            &$ids_to_delete,
            &$groups_processed,
            &$articles_in_duplicate_groups,
            $verbose,
            &$verbose_groups_shown,
            $verbose_groups_limit,
            $total_groups
        ) {
            foreach ($rows as $row) {
                $articles_in_duplicate_groups++;

                // Clave única del grupo actual.
                $group_key = $this->build_group_key($row->provider_code, $row->name);

                // Al cambiar de grupo, procesar el buffer del grupo anterior.
                if ($last_group_key !== null && $group_key !== $last_group_key) {
                    $this->flush_duplicate_group(
                        $group_buffer,
                        $ids_to_delete,
                        $verbose,
                        $verbose_groups_shown,
                        $verbose_groups_limit,
                        $groups_processed,
                        $total_groups
                    );
                    $group_buffer = [];
                }

                $group_buffer[] = $row;
                $last_group_key = $group_key;
            }
        });

        // Último grupo pendiente en el buffer.
        if (!empty($group_buffer)) {
            $this->flush_duplicate_group(
                $group_buffer,
                $ids_to_delete,
                $verbose,
                $verbose_groups_shown,
                $verbose_groups_limit,
                $groups_processed,
                $total_groups
            );
        }

        // Borrado masivo de todos los IDs acumulados.
        if ($eliminar_repetidos && !empty($ids_to_delete)) {
            $deleted_total = $this->delete_article_ids_in_chunks($ids_to_delete);
            $this->comment("Eliminados {$deleted_total} artículos duplicados en total.");
        } elseif ($eliminar_repetidos) {
            $this->comment('No había artículos para eliminar.');
        }

        $to_delete_count = count($ids_to_delete);

        $this->comment("Grupos procesados: {$groups_processed}");
        $this->comment("Artículos en grupos duplicados: {$articles_in_duplicate_groups}");
        $this->comment('Artículos a eliminar / eliminados: ' . $to_delete_count);
        $this->comment('Artículos a conservar: ' . ($articles_in_duplicate_groups - $to_delete_count));

        if (!$eliminar_repetidos) {
            $this->comment('Modo solo vista previa. Para eliminar: php artisan articulos_repetidos_servian 1');
        } else {
            $this->comment('Listo. Se eliminaron los duplicados por provider_code + name.');
        }

        return 0;
    }

    /**
     * Cuenta grupos con más de un artículo (provider_code + name).
     *
     * @param int $user_id
     * @return int
     */
    protected function count_duplicate_groups($user_id)
    {
        // Subconsulta agregada; evita cargar 12k+ filas solo para contar.
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total FROM (
                SELECT provider_code, name
                FROM articles
                WHERE user_id = ? AND provider_code IS NOT NULL AND deleted_at IS NULL
                GROUP BY provider_code, name
                HAVING COUNT(*) > 1
            ) AS duplicate_groups',
            [$user_id]
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * Lee artículos que pertenecen a grupos duplicados, ordenados por grupo e id descendente.
     *
     * @param int $user_id
     * @param callable $callback Recibe una Collection de filas por chunk
     * @return void
     */
    protected function stream_duplicate_articles($user_id, $callback)
    {
        // Subconsulta: combinaciones provider_code + name con más de un artículo.
        $duplicate_groups_subquery = DB::table('articles')
            ->select('provider_code', 'name')
            ->where('user_id', $user_id)
            ->whereNotNull('provider_code')
            ->whereNull('deleted_at')
            ->groupBy('provider_code', 'name')
            ->havingRaw('COUNT(*) > 1');

        DB::table('articles as a')
            ->joinSub($duplicate_groups_subquery, 'dup', function ($join) {
                $join->on('a.provider_code', '=', 'dup.provider_code')
                    ->on('a.name', '=', 'dup.name');
            })
            ->where('a.user_id', $user_id)
            ->whereNull('a.deleted_at')
            ->select('a.id', 'a.provider_code', 'a.name', 'a.bar_code')
            ->orderBy('a.provider_code')
            ->orderBy('a.name')
            ->orderByDesc('a.id')
            ->chunk($this->read_chunk_size, function ($rows) use ($callback) {
                $callback($rows);
            });
    }

    /**
     * Arma clave estable para agrupar en memoria.
     *
     * @param string $provider_code
     * @param string $name
     * @return string
     */
    protected function build_group_key($provider_code, $name)
    {
        return $provider_code . "\x1e" . $name;
    }

    /**
     * Procesa un grupo: resuelve cuál conservar y acumula IDs a borrar.
     *
     * @param array<int, object> $group_buffer
     * @param array<int, int> $ids_to_delete
     * @param bool $verbose
     * @param int $verbose_groups_shown
     * @param int $verbose_groups_limit
     * @param int $groups_processed
     * @param int $total_groups
     * @return void
     */
    protected function flush_duplicate_group(
        array $group_buffer,
        array &$ids_to_delete,
        $verbose,
        &$verbose_groups_shown,
        $verbose_groups_limit,
        &$groups_processed,
        $total_groups
    ) {
        if (empty($group_buffer)) {
            return;
        }

        $groups_processed++;

        // ID a conservar según bar_code o mayor id.
        $keep_id = $this->resolve_keep_from_rows($group_buffer);

        $first = $group_buffer[0];
        $provider_code = $first->provider_code;
        $name = $first->name;

        // Mostrar detalle solo en verbose y con límite para no saturar consola.
        $show_verbose = $verbose && $verbose_groups_shown < $verbose_groups_limit;

        if ($show_verbose) {
            $verbose_groups_shown++;
            $this->comment("({$groups_processed}/{$total_groups}) provider_code: {$provider_code} | name: {$name} | total: " . count($group_buffer));

            foreach ($group_buffer as $row) {
                $keep_marker = ((int) $row->id === (int) $keep_id) ? ' [CONSERVAR]' : '';
                $bar_code_display = $row->bar_code !== null ? $row->bar_code : 'null';
                $this->comment("- ID: {$row->id}, bar_code: {$bar_code_display}{$keep_marker}");
            }
        } elseif ($groups_processed % 500 === 0) {
            // Progreso liviano sin --detalle (cada 500 grupos).
            $this->comment("Procesados {$groups_processed}/{$total_groups} grupos...");
        }

        // Acumular IDs a eliminar (el borrado se hace al final en batch).
        foreach ($group_buffer as $row) {
            if ((int) $row->id !== (int) $keep_id) {
                $ids_to_delete[] = (int) $row->id;
            }
        }
    }

    /**
     * Resuelve qué artículo conservar en un grupo (colección en memoria).
     * Prioridad: con bar_code no vacío (mayor id si hay varios); si ninguno, mayor id.
     *
     * @param array<int, object> $rows Filas con id y bar_code, idealmente ordenadas id DESC
     * @return int|null
     */
    protected function resolve_keep_from_rows(array $rows)
    {
        if (empty($rows)) {
            return null;
        }

        // Candidato por mayor id (el buffer viene ordenado id DESC desde la query).
        $keep_id = (int) $rows[0]->id;

        // Buscar el de mayor id que tenga bar_code cargado.
        foreach ($rows as $row) {
            if ($this->has_bar_code($row->bar_code)) {
                $keep_id = (int) $row->id;
                break;
            }
        }

        return $keep_id;
    }

    /**
     * Indica si bar_code está cargado (no null ni vacío).
     *
     * @param mixed $bar_code
     * @return bool
     */
    protected function has_bar_code($bar_code)
    {
        if (is_null($bar_code)) {
            return false;
        }

        return trim((string) $bar_code) !== '';
    }

    /**
     * Elimina artículos por IDs en chunks (pocas consultas DELETE).
     *
     * @param array<int, int> $ids_to_delete
     * @return int Total eliminado
     */
    protected function delete_article_ids_in_chunks(array $ids_to_delete)
    {
        $deleted_total = 0;
        $chunks = array_chunk($ids_to_delete, $this->delete_chunk_size);

        $this->comment('Eliminando ' . count($ids_to_delete) . ' artículos en ' . count($chunks) . ' lote(s)...');

        foreach ($chunks as $index => $chunk_ids) {
            Article::whereIn('id', $chunk_ids)->delete();
            $deleted_total += count($chunk_ids);

            $chunk_num = $index + 1;
            $this->comment("  Lote {$chunk_num}/" . count($chunks) . ": eliminados " . count($chunk_ids) . " (acumulado {$deleted_total})");
        }

        return $deleted_total;
    }
}
