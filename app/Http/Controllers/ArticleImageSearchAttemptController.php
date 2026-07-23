<?php

namespace App\Http\Controllers;

use App\Models\ArticleImageSearchAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints de solo lectura para consultar el diagnóstico de la asignación automática de
 * imágenes de artículos (grupo 201, Prompt 01). Toda consulta filtra siempre por
 * `$this->userId()` (multi-tenant): un usuario nunca puede ver intentos de otro.
 *
 * Este controller no escribe attempts: eso lo hace el job de asignación masiva de imágenes
 * (prompt 03 de este mismo grupo), directamente sobre el modelo `ArticleImageSearchAttempt`.
 */
class ArticleImageSearchAttemptController extends Controller
{
    /**
     * Devuelve los intentos de búsqueda de una corrida puntual (`batch_uuid`), agrupados
     * por artículo y ordenados por `criterion_order` dentro de cada artículo (bar_code
     * primero, name segundo).
     *
     * @param string $batch_uuid Uuid de la corrida a consultar.
     * @return \Illuminate\Http\JsonResponse {"models": [{"article_id", "article_name", "article_bar_code", "attempts": [...]}]}
     */
    function by_batch($batch_uuid)
    {
        // Traemos las filas de la corrida, del usuario actual, ordenadas por artículo y criterio.
        $attempts = ArticleImageSearchAttempt::where('user_id', $this->userId())
            ->where('batch_uuid', $batch_uuid)
            ->orderBy('article_id')
            ->orderBy('criterion_order')
            ->get();

        // Agrupamos las filas por article_id, armando un array por artículo con sus intentos.
        $articles = [];
        foreach ($attempts as $attempt) {

            if (!isset($articles[$attempt->article_id])) {
                $articles[$attempt->article_id] = [
                    'article_id'       => $attempt->article_id,
                    'article_name'     => $attempt->article_name,
                    'article_bar_code' => $attempt->article_bar_code,
                    'attempts'         => [],
                ];
            }

            $articles[$attempt->article_id]['attempts'][] = $attempt;
        }

        // Devolvemos como array indexado (no asociativo por article_id) para el frontend.
        return response()->json(['models' => array_values($articles)]);
    }

    /**
     * Devuelve las últimas 10 corridas del usuario actual, una fila por `batch_uuid`, con
     * la fecha de la primera fila de la corrida, la cantidad de artículos distintos
     * involucrados y la cantidad de artículos a los que se les asignó imagen. No trae
     * `candidates` (son pesados y no hacen falta para este listado resumido).
     *
     * @return \Illuminate\Http\JsonResponse {"models": [{"batch_uuid", "created_at", "articles_count", "assigned_count"}]}
     */
    function recent_batches()
    {
        // Agrupamos por batch_uuid: fecha de la primera fila, artículos distintos y asignados.
        $batches = DB::table('article_image_search_attempts')
            ->select(
                'batch_uuid',
                DB::raw('MIN(created_at) as created_at'),
                DB::raw('COUNT(DISTINCT article_id) as articles_count'),
                DB::raw("COUNT(DISTINCT CASE WHEN outcome = '" . ArticleImageSearchAttempt::OUTCOME_ASSIGNED . "' THEN article_id END) as assigned_count")
            )
            ->where('user_id', $this->userId())
            ->groupBy('batch_uuid')
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->get();

        return response()->json(['models' => $batches]);
    }
}
