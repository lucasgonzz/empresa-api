<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saca de `descriptions` las descripciones de IA de baja confianza que nadie revisó y las deja como
 * propuestas "a revisar" (misión alertas-descripciones, 14/10/2026, decisión D1 de Lucas).
 *
 * 🔴 POR QUÉ: hasta esta versión el lote guardaba lo dudoso como cualquier descripción, y la tienda
 * propia y la ficha del ERP lo mostraban apenas se generaba. Desde ahora lo dudoso vive en
 * `article_description_proposals` hasta que alguien lo aprueba; esta migración hace lo mismo con lo
 * que ya estaba pendiente en cada cliente, así deja de verse en su tienda con el deploy y le aparece
 * al dueño en Alertas → Catálogo → Descripciones.
 *
 * Qué mueve: por artículo, las filas `ai_generated = 1`, `ai_confidence = 'low'` y sin
 * `ai_reviewed_at` (lo mismo que `Description::pendingAiReview()`). UNA propuesta por artículo con
 * esas secciones en orden de `id`, la confianza y las fuentes de la primera y la fecha de la más
 * vieja. Lo de confianza media o alta y lo que una persona ya revisó no se toca.
 *
 * Los artículos en la papelera (soft delete) también se mueven: es inocuo y no deja filas huérfanas
 * a la vista si el artículo se restaura. Las filas de un artículo que ya no existe ni en la papelera
 * se dejan como están: no hay dueño al que asignarle la propuesta, y no las muestra nadie.
 *
 * Va con `DB::table` y no con modelos: una migración corre en toda la flota con el código de esa
 * versión y no puede depender de un modelo que cambie después. Por lo mismo, no dispara el observer
 * de embeddings (un artículo que tenía vectorizada una descripción dudosa la sigue teniendo en el
 * índice hasta su próxima regeneración).
 *
 * En tandas por `article_id` (chunkById: se borra lo que se recorre, y un OFFSET se salteaba filas),
 * cada tanda en su transacción para que propuesta creada y filas borradas vayan juntas.
 */
class MoverDescripcionesIaPendientesAPropuestas extends Migration
{
    /** Artículos por tanda. */
    const TANDA = 200;

    /**
     * Mueve las pendientes a propuestas `a_revisar`.
     *
     * @return void
     */
    public function up()
    {
        if (!$this->hay_tablas()) {
            return;
        }

        DB::table('descriptions')
            ->select('article_id')
            ->where('ai_generated', 1)
            ->where('ai_confidence', 'low')
            ->whereNull('ai_reviewed_at')
            ->whereNotNull('article_id')
            ->groupBy('article_id')
            ->chunkById(self::TANDA, function ($filas) {
                $article_ids = [];

                foreach ($filas as $fila) {
                    $article_ids[] = (int) $fila->article_id;
                }

                $this->mover_tanda($article_ids);
            }, 'article_id');
    }

    /**
     * El camino inverso: las propuestas `a_revisar` vuelven a ser filas de `descriptions` como las
     * dejaba el lote viejo (IA, su confianza, sin revisar) y se borran. Las resueltas (aprobadas o
     * descartadas) son historia y quedan donde están: la tabla la borra la migración anterior.
     *
     * @return void
     */
    public function down()
    {
        if (!$this->hay_tablas()) {
            return;
        }

        DB::table('article_description_proposals')
            ->where('status', 'a_revisar')
            ->orderBy('id')
            ->chunkById(self::TANDA, function ($propuestas) {
                DB::transaction(function () use ($propuestas) {
                    $ahora = Carbon::now();
                    $filas = [];
                    $ids   = [];

                    foreach ($propuestas as $propuesta) {
                        $ids[] = (int) $propuesta->id;

                        $secciones = json_decode((string) $propuesta->sections, true);

                        foreach (is_array($secciones) ? $secciones : [] as $seccion) {
                            if (!is_array($seccion)) {
                                continue;
                            }

                            $filas[] = [
                                'title'          => isset($seccion['title']) ? (string) $seccion['title'] : null,
                                'content'        => isset($seccion['content']) ? (string) $seccion['content'] : null,
                                'article_id'     => (int) $propuesta->article_id,
                                'ai_generated'   => 1,
                                'ai_confidence'  => $propuesta->ai_confidence,
                                'ai_sources'     => $propuesta->ai_sources,
                                'ai_reviewed_at' => null,
                                'created_at'     => is_null($propuesta->created_at) ? $ahora : $propuesta->created_at,
                                'updated_at'     => $ahora,
                            ];
                        }
                    }

                    if (!empty($filas)) {
                        DB::table('descriptions')->insert($filas);
                    }

                    DB::table('article_description_proposals')->whereIn('id', $ids)->delete();
                });
            });
    }

    /**
     * Una tanda de artículos: una propuesta por artículo y las filas movidas, borradas.
     *
     * @param  array $article_ids
     * @return void
     */
    protected function mover_tanda(array $article_ids)
    {
        DB::transaction(function () use ($article_ids) {
            // El dueño de cada artículo, incluidos los de la papelera (DB::table no filtra deleted_at).
            $duenos = [];

            foreach (DB::table('articles')->whereIn('id', $article_ids)->get(['id', 'user_id']) as $articulo) {
                $duenos[(int) $articulo->id] = (int) $articulo->user_id;
            }

            $filas = DB::table('descriptions')
                ->whereIn('article_id', $article_ids)
                ->where('ai_generated', 1)
                ->where('ai_confidence', 'low')
                ->whereNull('ai_reviewed_at')
                ->orderBy('article_id')
                ->orderBy('id')
                ->get(['id', 'article_id', 'title', 'content', 'ai_confidence', 'ai_sources', 'created_at']);

            $por_articulo = [];

            foreach ($filas as $fila) {
                $por_articulo[(int) $fila->article_id][] = $fila;
            }

            $ahora      = Carbon::now();
            $propuestas = [];
            $a_borrar   = [];

            foreach ($por_articulo as $article_id => $filas_del_articulo) {
                // Sin artículo no hay dueño: esas filas no las ve nadie y se dejan como están.
                if (!isset($duenos[$article_id])) {
                    continue;
                }

                $primera   = $filas_del_articulo[0];
                $secciones = [];
                $creada    = null;

                foreach ($filas_del_articulo as $fila) {
                    $secciones[] = [
                        'title'   => is_null($fila->title) ? '' : (string) $fila->title,
                        'content' => is_null($fila->content) ? '' : (string) $fila->content,
                    ];

                    $a_borrar[] = (int) $fila->id;

                    if (!is_null($fila->created_at) && (is_null($creada) || $fila->created_at < $creada)) {
                        $creada = $fila->created_at;
                    }
                }

                $propuestas[] = [
                    'user_id'       => $duenos[$article_id],
                    'article_id'    => (int) $article_id,
                    'sections'      => json_encode($secciones, JSON_UNESCAPED_UNICODE),
                    'ai_confidence' => $primera->ai_confidence,
                    // Ya es el JSON [{source, url, title}] que guardó el lote: se copia tal cual.
                    'ai_sources'    => $primera->ai_sources,
                    'status'        => 'a_revisar',
                    'resolved_at'   => null,
                    'resolved_by'   => null,
                    'created_at'    => is_null($creada) ? $ahora : $creada,
                    'updated_at'    => $ahora,
                ];
            }

            if (!empty($propuestas)) {
                DB::table('article_description_proposals')->insert($propuestas);
            }

            foreach (array_chunk($a_borrar, 1000) as $ids) {
                DB::table('descriptions')->whereIn('id', $ids)->delete();
            }
        });
    }

    /**
     * Las dos puntas tienen que existir: una base sin las columnas de IA en `descriptions` (anterior
     * a las descripciones inteligentes) no tiene nada que mover.
     *
     * @return bool
     */
    protected function hay_tablas()
    {
        return Schema::hasTable('descriptions')
            && Schema::hasColumn('descriptions', 'ai_confidence')
            && Schema::hasColumn('descriptions', 'ai_generated')
            && Schema::hasColumn('descriptions', 'ai_reviewed_at')
            && Schema::hasTable('article_description_proposals');
    }
}
