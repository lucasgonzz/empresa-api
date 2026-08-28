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
     * Devuelve las corridas del usuario actual (una fila por `batch_uuid`), la más reciente
     * primero, con la fecha de la primera fila de la corrida y los contadores que necesita
     * la tabla del historial: artículos totales, asignados, con revisión pendiente y saltados
     * por cuota agotada. No trae `candidates` (son pesados y no hacen falta para este listado
     * resumido).
     *
     * `skipped_count` se calcula en PHP (no con un COUNT condicional en la query) porque un
     * artículo puede tener `outcome = all_candidates_rejected` en un criterio y terminar
     * `assigned` en el siguiente: ese artículo no es "saltado", así que no se lo puede contar
     * mirando una sola fila. La única forma correcta es la resta:
     * articles_count - assigned_count - quota_count. NO reemplazar esto por un COUNT
     * condicional de `outcome = quota` u otro: daría mal en corridas con reintento entre
     * criterios (grupo 217, prompt 03).
     *
     * @return \Illuminate\Http\JsonResponse {"models": [{"batch_uuid", "created_at", "articles_count", "assigned_count", "needs_review_count", "quota_count", "skipped_count"}], "retention_days": 30}
     */
    function recent_batches()
    {
        // Límite de corridas a traer: viene por query string, default 30, tope duro 100.
        // Si no es numérico (o falta), (int) lo deja en 0 y el max(1, ...) lo sube a 1, así
        // que agregamos el default explícito antes de castear para no devolver una sola fila.
        $limit = request('limit');
        $limit = is_numeric($limit) ? (int) $limit : 30;
        $limit = min(max(1, $limit), 100);

        // Agrupamos por batch_uuid: fecha de la primera fila y los contadores distinct por
        // article_id que se pueden sacar con un COUNT condicional simple (assigned, needs
        // review dentro de assigned, y quota). skipped_count se completa después en PHP.
        $batches = DB::table('article_image_search_attempts')
            ->select(
                'batch_uuid',
                DB::raw('MIN(created_at) as min_created_at'),
                DB::raw('COUNT(DISTINCT article_id) as articles_count'),
                DB::raw("COUNT(DISTINCT CASE WHEN outcome = '" . ArticleImageSearchAttempt::OUTCOME_ASSIGNED . "' THEN article_id END) as assigned_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN outcome = '" . ArticleImageSearchAttempt::OUTCOME_ASSIGNED . "' AND needs_review = 1 THEN article_id END) as needs_review_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN outcome = '" . ArticleImageSearchAttempt::OUTCOME_QUOTA . "' THEN article_id END) as quota_count")
            )
            ->where('user_id', $this->userId())
            ->groupBy('batch_uuid')
            // Ordenamos por el alias del agregado (no por la columna cruda created_at): en
            // MySQL, agrupando por batch_uuid, hay que ordenar por MIN(created_at).
            ->orderByRaw('MIN(created_at) DESC')
            ->limit($limit)
            ->get();

        // Completamos created_at y skipped_count fila por fila (ver comentario del docblock).
        $models = [];
        foreach ($batches as $batch) {
            $models[] = [
                'batch_uuid'         => $batch->batch_uuid,
                'created_at'         => $batch->min_created_at,
                'articles_count'     => (int) $batch->articles_count,
                'assigned_count'     => (int) $batch->assigned_count,
                'needs_review_count' => (int) $batch->needs_review_count,
                'quota_count'        => (int) $batch->quota_count,
                'skipped_count'      => (int) $batch->articles_count - (int) $batch->assigned_count - (int) $batch->quota_count,
            ];
        }

        return response()->json([
            'models'         => $models,
            'retention_days' => ArticleImageSearchAttempt::RETENTION_DAYS,
        ]);
    }

    /**
     * Reconstruye, a partir de las filas crudas de `article_image_search_attempts`, el mismo
     * shape que antes viajaba completo por Pusher (evento `ArticleBatchImagesProcessed`) al
     * terminar la corrida `$batch_uuid`.
     *
     * 🔴 Desde el fix del 25/8/2026 (el broadcast de Pusher superaba los 10240 bytes con lotes
     * grandes), este endpoint dejó de ser solo el camino del historial: es el que usa el modal
     * EN VIVO al terminar una corrida (`SearchImageAutomatica.vue`, vía
     * `ArticleBatchImagesProcessed::broadcastWith()`, que ahora solo manda contadores +
     * `batch_uuid`). El modal de historial (`SmartImagesHistoryModal.vue`) también sigue
     * usándolo para corridas viejas. No asumir que solo lo llama el historial.
     *
     * Devuelve 404 tanto si el uuid no existe como si pertenece a otro comercio: no hay que
     * distinguir los dos casos en la respuesta para no filtrar la existencia de corridas
     * ajenas ni la de corridas ya purgadas por retención.
     *
     * @param string $batch_uuid Uuid de la corrida a reconstruir.
     * @return \Illuminate\Http\JsonResponse {"batch_uuid", "created_at", "articles_count", "processed", "skipped", "skipped_by_quota", "quota_reached", "needs_review", "skipped_items": [{"article_id","name","summary"}], "skipped_names", "needs_review_items": [{"article_id","name","image_url"}], "skipped_by_quota_names"}
     */
    function summary($batch_uuid)
    {
        // Traemos todas las filas de la corrida del usuario actual, ordenadas por artículo y
        // por criterio (bar_code antes que name), para poder agrupar por article_id abajo.
        $attempts = ArticleImageSearchAttempt::where('user_id', $this->userId())
            ->where('batch_uuid', $batch_uuid)
            ->orderBy('article_id')
            ->orderBy('criterion_order')
            ->get();

        // Sin filas: o el uuid no existe, o es de otro comercio, o la retención de 30 días ya
        // la purgó. En los tres casos devolvemos el mismo 404 genérico.
        if ($attempts->isEmpty()) {
            return response()->json(['message' => 'No se encontró el historial de esa búsqueda de imágenes.'], 404);
        }

        // Agrupamos las filas por article_id para poder mirar, por artículo, todos los
        // intentos que se hicieron (uno por criterio) antes de decidir en qué balde cae.
        $articles = [];
        foreach ($attempts as $attempt) {
            if (!isset($articles[$attempt->article_id])) {
                $articles[$attempt->article_id] = [];
            }
            $articles[$attempt->article_id][] = $attempt;
        }

        // Contadores y arrays a completar recorriendo cada artículo, igual que hace el job.
        $processed              = 0;
        $skipped                = 0;
        $skipped_by_quota       = 0;
        $quota_reached          = false;
        $needs_review           = 0;
        $skipped_items          = [];
        $needs_review_items     = [];
        $skipped_by_quota_names = [];

        foreach ($articles as $article_id => $article_attempts) {

            // Nombre a mostrar: el snapshot guardado en la primera fila, o el fallback con el
            // id si el artículo no tenía nombre (mismo criterio que get_article_display_name()
            // del job).
            $article_name = $article_attempts[0]->article_name ?: 'Artículo #' . $article_id;

            // Buscamos si alguna fila del artículo terminó asignada.
            $assigned_attempt = null;
            foreach ($article_attempts as $attempt) {
                if ($attempt->outcome === ArticleImageSearchAttempt::OUTCOME_ASSIGNED) {
                    $assigned_attempt = $attempt;
                    break;
                }
            }

            if ($assigned_attempt) {
                // Se le asignó imagen: cuenta como procesado, y si quedó marcada para revisar
                // (baja confianza) va además a needs_review_items.
                $processed++;
                if ($assigned_attempt->needs_review) {
                    $needs_review++;
                    $needs_review_items[] = [
                        'article_id' => $article_id,
                        'name'       => $article_name,
                        'image_url'  => $assigned_attempt->assigned_image_url,
                    ];
                }
                continue;
            }

            // No se asignó imagen: miramos el último intento (mayor criterion_order) para ver
            // si el motivo fue que se agotó la cuota antes de llegar a buscar.
            $last_attempt = end($article_attempts);
            if ($last_attempt->outcome === ArticleImageSearchAttempt::OUTCOME_QUOTA) {
                $skipped_by_quota++;
                $skipped_by_quota_names[] = $article_name;
                $quota_reached = true;
                continue;
            }

            // Cualquier otro caso: saltado por resultado (sin candidatos, todos rechazados,
            // error de API, etc). El resumen junta los outcome_detail de todos los intentos
            // del artículo, mismo separador que usa el fallback summary_line() del SPA.
            $skipped++;
            $details = [];
            foreach ($article_attempts as $attempt) {
                if ($attempt->outcome_detail) {
                    $details[] = $attempt->outcome_detail;
                }
            }
            $skipped_items[] = [
                'article_id' => $article_id,
                'name'       => $article_name,
                'summary'    => implode(' · ', $details),
            ];
        }

        // Los dos listados de items van ordenados por nombre (insensible a mayúsculas): en el
        // historial se los busca por nombre, no por article_id.
        usort($skipped_items, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        usort($needs_review_items, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // skipped_names se deriva de skipped_items (ya ordenado) en vez de armarse aparte,
        // para no duplicar el orden/la lógica: es solo compatibilidad con el fallback legacy
        // del SPA cuando el summary del payload de Pusher no viene.
        $skipped_names = [];
        foreach ($skipped_items as $item) {
            $skipped_names[] = $item['name'];
        }

        return response()->json([
            'batch_uuid'             => $batch_uuid,
            'created_at'             => $attempts->first()->created_at,
            'articles_count'         => count($articles),
            'processed'              => $processed,
            'skipped'                => $skipped,
            'skipped_by_quota'       => $skipped_by_quota,
            'quota_reached'          => $quota_reached,
            'needs_review'           => $needs_review,
            'skipped_items'          => $skipped_items,
            'skipped_names'          => $skipped_names,
            'needs_review_items'     => $needs_review_items,
            'skipped_by_quota_names' => $skipped_by_quota_names,
        ]);
    }
}
